<?php

namespace App\Domains\Activity\Services;

use App\Domains\Activity\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Matter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The only thing that writes to `activities` (M8.1, D-123).
 *
 * There is no create endpoint and no user-facing write path — the question of
 * whether one should exist is O-047, and it needs a catalogue extension.
 *
 * ## `metadata` is for interpolation values, and nothing else
 *
 * `description_key` names a translation key; `metadata` carries what that key
 * interpolates — a title, a reference number, a stage name. It is subject to the
 * same denylist as the audit trail: never a NIK, never an NPWP, never a filename
 * that might carry one (`CLAUDE.md` section 32, D-105).
 *
 * Because the values here are chosen explicitly at each call site rather than
 * swept from a model's attributes, the risk is different from the audit
 * logger's — nothing arrives by accident. {@see self::REDACTED_KEYS} is a
 * backstop for the call site that gets it wrong anyway.
 *
 * ## Context is denormalised on purpose
 *
 * `project_id` and `matter_id` are set from the subject where the subject knows
 * them, so a Matter timeline is one indexed query rather than a union across
 * every subject type that could belong to a Matter.
 */
class ActivityRecorder
{
    /**
     * Metadata keys that never reach the table, whatever a caller passes.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'nik',
        'npwp',
        'tax_id',
        'password',

        // **Money, added at M8.2.** `billing.amount.view` is a separate gate
        // (D-125), and the activity feed is read by anyone who can reach the
        // subject — no billing capability is consulted for it at all. An amount
        // in a timeline entry would disclose to every colleague exactly what the
        // masking rule exists to withhold. Billing entries carry a reference and
        // a title; what it was worth is on the record, behind the gate.
        'amount',
        'total',
        'total_amount',
        'subtotal',
        'subtotal_amount',
        'paid_amount',
        'unit_amount',
    ];

    /**
     * Record that something happened.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ActivityType $type,
        Model $subject,
        ?User $actor = null,
        array $metadata = [],
        ?string $projectId = null,
        ?string $matterId = null,
    ): Activity {
        $actor ??= auth()->user();

        $activity = new Activity;

        $activity->office_id = $this->resolveOfficeId($subject, $actor);
        $activity->actor_user_id = $actor?->getKey();
        $activity->activity_type = $type;
        $activity->subject_type = $subject->getMorphClass();
        $activity->subject_id = (string) $subject->getKey();
        $activity->description_key = $type->descriptionKey();
        $activity->metadata = $this->clean($metadata);

        $activity->project_id = $projectId ?? $this->inferProjectId($subject);
        $activity->matter_id = $matterId ?? $this->inferMatterId($subject);

        $activity->save();

        return $activity;
    }

    private function resolveOfficeId(Model $subject, ?User $actor): string
    {
        $officeId = $subject->getAttribute('office_id') ?? $actor?->office_id;

        if (! is_string($officeId) || $officeId === '') {
            throw new RuntimeException(
                'Cannot record activity for '.$subject::class.' without an Office. '
                .'A feed row filed against a guessed Office would be readable by the wrong people.'
            );
        }

        return $officeId;
    }

    /**
     * The Project this subject belongs to, where it knows.
     *
     * A Project is its own context; everything else either carries a
     * `project_id` or has none.
     */
    private function inferProjectId(Model $subject): ?string
    {
        if ($subject instanceof Project) {
            return (string) $subject->getKey();
        }

        $value = $subject->getAttribute('project_id');

        return is_string($value) ? $value : null;
    }

    private function inferMatterId(Model $subject): ?string
    {
        if ($subject instanceof Matter) {
            return (string) $subject->getKey();
        }

        $value = $subject->getAttribute('matter_id');

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function clean(array $metadata): ?array
    {
        foreach (array_keys($metadata) as $key) {
            if (in_array($key, self::REDACTED_KEYS, true)) {
                unset($metadata[$key]);
            }
        }

        return $metadata === [] ? null : $metadata;
    }
}
