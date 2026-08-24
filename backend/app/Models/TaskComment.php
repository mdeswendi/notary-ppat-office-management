<?php

namespace App\Models;

use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * One remark on a Task (M5.4, D-119).
 *
 * **Only `comment` is fillable.** `task_id` and `user_id` are identity: the first
 * decides which Office's boundary applies, and the second is attribution that must
 * come from the session rather than the payload — a fillable `user_id` would let a
 * caller sign somebody else's name.
 *
 * **A comment is never edited.** No update path exists, and the model refuses one:
 * a remark somebody made is a record of what was said at the time, and silently
 * rewriting it would make the conversation above it stop making sense. A
 * correction is another comment.
 *
 * `deleted_at` exists because the ERD lists it, and the trait is taken so the
 * column has a lifecycle rather than sitting reserved — but **M5.4 exposes no way
 * to retract a comment.** Whether a person may, and whose remark they may reach,
 * is a decision with its own capability question that this milestone does not
 * take.
 */
#[Fillable(['comment'])]
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (self $comment): void {
            // Deleting is an update under SoftDeletes, and that one is allowed:
            // it changes `deleted_at` and nothing a reader would have seen.
            if ($comment->isDirty('deleted_at') && ! $comment->isDirty('comment')) {
                return;
            }

            throw new RuntimeException(
                'task_comments are written once (M5.4, D-119). '
                .'A remark records what somebody said at the time; rewriting it would make the '
                .'conversation around it stop making sense. A correction is another comment.'
            );
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }
}
