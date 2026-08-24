<?php

namespace App\Models;

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * One piece of operational office work (M5.4, D-119).
 *
 * **`office_id` is immutable**, following `ServiceType`, `WorkflowTemplate` and
 * `Document`: it is the security boundary and the `OFFICE` scope predicate, and
 * moving a Task between Offices would silently redefine who may see it — and
 * would strand four user references that the composite keys hold to that Office.
 *
 * **`created_by` is the `OWN` predicate and never changes.** `assigned_to` is
 * `ASSIGNED`, a separate predicate that changes whenever work is handed over.
 * Keeping them apart is what lets an administrator grant "tasks I raised" without
 * granting "tasks I was given", and the two union when both are held (D-028).
 *
 * **Neither `status` nor the completion pair is fillable.** Completion answers to
 * `tasks.complete` and cancellation to `tasks.delete`; letting mass assignment
 * reach either would make `tasks.update` a silent superset of both.
 *
 * `isOverdue()` is **computed, never stored**. A row that went stale overnight
 * would need a job to notice; a comparison at read time is always right, and
 * `OVERDUE` is deliberately not a sixth status — the ERD names five.
 */
#[Fillable([
    'title',
    'description',
    'priority',
    'due_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $task): void {
            if ($task->isDirty('office_id')) {
                throw new RuntimeException(
                    'tasks.office_id is immutable (M5.4). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a task '
                    .'between Offices would silently redefine who may see it — and would strand the four '
                    .'user references the composite keys hold to that Office.'
                );
            }

            if ($task->isDirty('created_by')) {
                throw new RuntimeException(
                    'tasks.created_by is immutable (M5.4, D-119). '
                    .'It is the OWN scope predicate: changing it would move a task between people\'s '
                    .'reach without anybody deciding it. Reassigning work writes assigned_to instead.'
                );
            }
        });

        // The completion pair is enforced by a PostgreSQL CHECK; this is what
        // holds the same rule on the SQLite connection the suite runs on, so a
        // half-written completion fails in the tests rather than only in
        // production.
        static::saving(function (self $task): void {
            $hasWhen = $task->completed_at !== null;
            $hasWho = $task->completed_by !== null;

            if ($hasWhen !== $hasWho) {
                throw new RuntimeException(
                    'tasks completion is recorded as a pair (M5.4). '
                    .'completed_at and completed_by are written together and cleared together: half of '
                    .'a completion is a row nobody can explain.'
                );
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /** The `ASSIGNED` predicate made visible. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** The `OWN` predicate made visible. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Remarks, oldest first.
     *
     * Ordered here rather than at each call site: a conversation read out of
     * order is not a conversation.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    /**
     * Past its due date and still live.
     *
     * **Computed, never stored**, and never a status. A task with no due date is
     * never overdue — an absent deadline is not a missed one.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->status->isOpen()
            && $this->due_at->isBefore(Date::now());
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => ProjectPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
