<?php

namespace App\Domains\Audit\Services;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Activity\Services\ActivityRecorder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One call that writes to both stores (M8.1, D-123).
 *
 * `activities` and `audit_logs` are deliberately separate tables answering to
 * different capabilities, but almost every business event belongs in both: an
 * auditor wants *"this deed's status went UNDER_REVIEW → APPROVED, by Rina, from
 * this address"* and a user wants *"Rina approved the deed"*. Making each Action
 * call two services would mean twenty chances to remember one and forget the
 * other.
 *
 * So Actions depend on this, and it fans out.
 *
 * ## Why Actions and not model observers
 *
 * The M8.1 brief proposed observers for create/update/delete. Two reasons this
 * does not:
 *
 * **Factories.** An observer fires for every `Project::factory()->create()` in
 * the suite, writing thousands of audit rows about records no actor ever created
 * — into a table nobody may delete from. The rows would also be false: a factory
 * row was not created by the authenticated user, and often by nobody at all.
 *
 * **Meaning.** An observer sees `status: 'UNDER_REVIEW' → 'APPROVED'`. It cannot
 * tell approving from any other write that happens to set that column, and the
 * activity feed's whole value is that it says `DEED_APPROVED` rather than
 * "something changed". The Action already knows which act it is performing.
 *
 * The cost is that a future write path could forget to call this. That cost is
 * accepted: a forgotten call leaves a gap, where an observer that fires in the
 * wrong places leaves a table full of confident falsehoods.
 */
class EventRecorder
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * A record came into existence.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function created(Model $subject, User $actor, ?ActivityType $type = null, array $metadata = []): void
    {
        $this->audit->created($subject, $actor);

        if ($type !== null) {
            $this->activity->record($type, $subject, $actor, $metadata);
        }
    }

    /**
     * A record's fields were corrected.
     *
     * **Audit only, and deliberately no activity row.** A timeline that reports
     * every typo fix is a timeline nobody reads; the change itself is preserved
     * with its old and new values where an auditor can find it.
     *
     * Returns without writing anything when nothing actually changed, so a save
     * that touched no column leaves no trace.
     */
    public function updated(Model $subject, User $actor, ?string $reason = null): void
    {
        $this->audit->updated($subject, $actor, $reason);
    }

    /**
     * A record moved from one lifecycle state to another.
     *
     * The audit row carries the transition; the activity row names the act.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function statusChanged(
        Model $subject,
        User $actor,
        ?string $from,
        ?string $to,
        ?ActivityType $type = null,
        array $metadata = [],
        ?string $reason = null,
    ): void {
        $this->audit->statusChanged($subject, $from, $to, $actor, $reason);

        if ($type !== null) {
            $this->activity->record($type, $subject, $actor, $metadata);
        }
    }

    /**
     * Something happened that is worth a timeline entry but is not a status move.
     *
     * Assignment is the case this exists for: handing work to somebody changes no
     * lifecycle state and is exactly what a colleague wants to see.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function happened(ActivityType $type, Model $subject, User $actor, array $metadata = []): void
    {
        $this->activity->record($type, $subject, $actor, $metadata);
    }
}
