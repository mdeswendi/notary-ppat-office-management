<?php

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Contracts\HasAuditOffice;
use App\Domains\Audit\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The only thing that writes to `audit_logs` (M8.1, D-123, closing D-115).
 *
 * A single writer is what makes the redaction rule below enforceable. Scattered
 * `AuditLog::create()` calls would each have to remember it; one class has to
 * remember it once.
 *
 * ## What is never recorded
 *
 * `CLAUDE.md` section 32 lists what must never be logged, D-105 adds the
 * leak-surface rule, and D-115 restates both with more force for audit
 * specifically: an audit row records **that** a sensitive field changed, never
 * what it changed from and to.
 *
 * So {@see self::REDACTED} names the attributes whose values are replaced with a
 * marker before anything is written. The key survives — an auditor still learns
 * that the NIK was edited — and the value never reaches the table. This is
 * deliberately a denylist of exact attribute names rather than a pattern match:
 * a regex over field names silently starts redacting the wrong things when a
 * future column happens to match, and silently stops when one does not.
 *
 * ## Failures are not swallowed
 *
 * If the audit write fails, the surrounding transaction fails with it. That is
 * the intended behaviour rather than an oversight: the record of an act and the
 * act itself belong to the same transaction (`CLAUDE.md` section 37), and an
 * audit trail with silent gaps is worse than one that stops the line. Callers
 * that genuinely must not fail this way should not be audited at all — a decision
 * to take explicitly, not by catching an exception here.
 */
class AuditLogger
{
    /**
     * Attribute names whose values never reach the audit table.
     *
     * @var list<string>
     */
    private const REDACTED = [
        'nik',
        'npwp',
        'tax_id',
        'nik_fingerprint',
        'npwp_fingerprint',
        'tax_id_fingerprint',
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
    ];

    /**
     * What replaces a redacted value. A marker rather than a null, so a reader
     * can tell "withheld" from "was empty".
     */
    private const REDACTION_MARKER = '[redacted]';

    /**
     * Record one event.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        AuditEvent $event,
        Model $auditable,
        ?User $actor = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $reason = null,
    ): AuditLog {
        $actor ??= auth()->user();

        $log = new AuditLog;

        $log->office_id = $this->resolveOfficeId($auditable, $actor);
        $log->actor_user_id = $actor?->getKey();
        $log->event = $event;
        $log->auditable_type = $auditable->getMorphClass();
        $log->auditable_id = (string) $auditable->getKey();
        $log->old_values = $this->redact($oldValues);
        $log->new_values = $this->redact($newValues);
        $log->reason = $reason;

        // `request()` exists in console context too, but carries no meaningful
        // client address there — a queued job or an artisan command records null
        // rather than the machine's own loopback, which would be misleading.
        if (app()->runningInConsole()) {
            $log->ip_address = null;
            $log->user_agent = null;
        } else {
            $log->ip_address = request()->ip();
            $log->user_agent = substr((string) request()->userAgent(), 0, 255) ?: null;
        }

        $log->save();

        return $log;
    }

    /**
     * Record a model's creation.
     */
    public function created(Model $auditable, ?User $actor = null): AuditLog
    {
        return $this->log(AuditEvent::CREATED, $auditable, $actor, null, $this->attributesOf($auditable));
    }

    /**
     * Record a model's change, carrying only the attributes that actually moved.
     *
     * A row listing every column of an unchanged record tells an auditor nothing
     * and buries the one field that did change.
     */
    public function updated(Model $auditable, ?User $actor = null, ?string $reason = null): ?AuditLog
    {
        $changes = $auditable->getChanges();

        unset($changes['updated_at']);

        if ($changes === []) {
            return null;
        }

        $original = [];

        foreach (array_keys($changes) as $key) {
            $original[$key] = $auditable->getOriginal($key);
        }

        return $this->log(AuditEvent::UPDATED, $auditable, $actor, $original, $changes, $reason);
    }

    /**
     * Record a status transition as its own event.
     *
     * Separate from {@see self::updated()} because "this deed became APPROVED" is
     * the question an auditor asks, and finding it inside a general update row
     * means scanning every change ever made to the record.
     */
    public function statusChanged(
        Model $auditable,
        ?string $from,
        ?string $to,
        ?User $actor = null,
        ?string $reason = null,
    ): AuditLog {
        return $this->log(
            AuditEvent::STATUS_CHANGED,
            $auditable,
            $actor,
            ['status' => $from],
            ['status' => $to],
            $reason,
        );
    }

    /**
     * Record that somebody read something the interface normally withholds.
     *
     * **The `field` is named; the value never is.** This is the event D-115
     * exists for, and the rule it carries with more force than anywhere else.
     */
    public function sensitiveAccess(Model $auditable, string $field, ?User $actor = null): AuditLog
    {
        return $this->log(
            AuditEvent::SENSITIVE_ACCESS,
            $auditable,
            $actor,
            null,
            ['field' => $field],
        );
    }

    /**
     * Which Office the event belongs to.
     *
     * The auditable's own Office wins, because that is the record the event is
     * about. Falling back to the actor's covers the case where the subject has no
     * Office of its own — and if neither exists the write fails rather than
     * guessing, because an audit row filed against the wrong Office is invisible
     * to the people entitled to see it.
     */
    private function resolveOfficeId(Model $auditable, ?User $actor): string
    {
        // A record that knows its own Office indirectly says so explicitly.
        // `Individual` and `Company` hold theirs on the parent Party.
        $officeId = $auditable instanceof HasAuditOffice
            ? $auditable->auditOfficeId()
            : $auditable->getAttribute('office_id');

        $officeId ??= $actor?->office_id;

        if (! is_string($officeId) || $officeId === '') {
            throw new RuntimeException(
                'Cannot audit '.$auditable::class.' without an Office. The subject carries no '
                .'office_id and no actor was resolved, so the row could only be filed against a '
                .'guess — which would hide it from everybody entitled to read it.'
            );
        }

        return $officeId;
    }

    /**
     * A model's attributes, with sensitive values withheld.
     *
     * @return array<string, mixed>
     */
    private function attributesOf(Model $auditable): array
    {
        $attributes = $auditable->getAttributes();

        unset($attributes['created_at'], $attributes['updated_at']);

        return $this->redact($attributes) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array($key, self::REDACTED, true) && $value !== null) {
                $values[$key] = self::REDACTION_MARKER;
            }
        }

        return $values;
    }
}
