<?php

namespace App\Domains\Task\Enums;

/**
 * The lifecycle state of a Task (M5.4, D-119).
 *
 * Transcribed verbatim from `03_DATABASE_ERD.md` section 15. Five values, and no
 * sixth may be added: a status the canonical list does not name would be a
 * lifecycle rule invented here.
 *
 * **Priority is deliberately not an enum of its own.** The ERD gives Task
 * `LOW NORMAL HIGH URGENT`, which is exactly `ProjectPriority` — already shared by
 * Project and Matter (D-095). A third identical enum would be three places for one
 * vocabulary to drift.
 *
 * ## Transitions are encoded, superseding the lock
 *
 * `15_M5_DOCUMENT_TASK_ARCHITECTURE.md` section 11.3 said M5 would encode **no
 * transition matrix for tasks**. D-117 already crossed that line for Document, by
 * decision rather than drift, and the same reasoning applies here with less
 * tension: a Task status is **operational, not legal**. Nothing about it says what
 * a deed or a Warkah may become.
 *
 * ```text
 * create    ->  OPEN
 * progress  OPEN, WAITING              ->  IN_PROGRESS
 * wait      OPEN, IN_PROGRESS          ->  WAITING
 * complete  OPEN, IN_PROGRESS, WAITING ->  COMPLETED
 * reopen    COMPLETED                  ->  IN_PROGRESS
 * cancel    anything not yet finished  ->  CANCELLED
 * delete    COMPLETED, CANCELLED       ->  (soft deleted)
 * ```
 *
 * **Deletion is the rule the others exist to support.** A task still being worked
 * on cannot be removed — finish it or cancel it first — so nothing in flight
 * disappears without somebody saying what happened to it.
 *
 * **Completion is reversible and cancellation is not.** Reopening a task that was
 * finished too early is an ordinary correction; un-cancelling is not, because
 * cancelling states that the work will not happen. Somebody who cancelled by
 * mistake raises a new task, which leaves a record of both.
 */
enum TaskStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case WAITING = 'WAITING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Statuses an ordinary update may set.
     *
     * **`COMPLETED` and `CANCELLED` are absent on purpose.** Each answers to its
     * own capability — `tasks.complete` and `tasks.delete` — so letting
     * `tasks.update` write either would make one grant a silent superset of
     * another (the D-091 discipline).
     *
     * @return array<int, self>
     */
    public static function settableByUpdate(): array
    {
        return [self::OPEN, self::IN_PROGRESS, self::WAITING];
    }

    /**
     * Work that is still live: neither finished nor called off.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }

    public function isCompletable(): bool
    {
        return in_array($this, [self::OPEN, self::IN_PROGRESS, self::WAITING], true);
    }

    public function isReopenable(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isCancellable(): bool
    {
        return $this->isOpen();
    }

    /**
     * Only settled work may be removed.
     *
     * The mirror of `DocumentStatus::isDeletable()` (D-117), for the same reason:
     * a capability that must be restricted is restricted by state rather than by
     * inventing a permission.
     */
    public function isDeletable(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
