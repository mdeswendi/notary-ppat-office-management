<?php

namespace App\Models;

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * The M3 aggregate root: one client engagement (D-087).
 *
 * **What is not fillable is the interesting part.** `office_id`, `pic_user_id`,
 * `status`, `created_by`, and `updated_by` are all withheld from mass assignment,
 * each for its own reason:
 *
 *   office_id       the security boundary, and immutable during M3 (D-089)
 *   project_number  allocated once and immutable thereafter (D-094)
 *   pic_user_id     answers to `projects.assign`, never to `projects.update` (D-091)
 *   status          answers to `projects.change_status`, likewise (D-091)
 *   created_by      actor metadata, written by the application, never by request
 *   updated_by      input (docs/07_SECURITY_RULES.md section 34)
 *
 * That list is the D-091 mutation boundary expressed where it cannot be
 * forgotten. A future `UpdateProject` Action accepting a request body cannot
 * accidentally reassign a Project or move its status, because the model refuses
 * the fields rather than trusting the Action to filter them.
 *
 * **No Matter relation exists here, and none may be added before M4.** Matter
 * will reference Project, not the reverse (D-087), and Project holds no
 * participant collection either — `project_parties` is maintained through its
 * own model and its own nested endpoints (M3.4, D-098).
 */
#[Fillable([
    'title',
    'description',
    'priority',
    'opened_at',
    'target_completion_date',
    'completed_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The persistence-level archive, distinct from business status `ARCHIVED`
     * (D-093). `projects.restore` reverses this and nothing else — not a
     * completion, not a workflow, not a business status.
     */
    use SoftDeletes;

    /**
     * Refuse an Office change before it reaches SQL.
     *
     * M3 has no Project Office-transfer operation (D-089), and the absence needs
     * a guard rather than a convention: `office_id` is not fillable, but an
     * Action calling `forceFill` or setting the attribute directly would
     * otherwise move a Project between security boundaries silently.
     *
     * This is an **engineering boundary, not a claim of legal impossibility**. An
     * office may one day have a good reason to move a Project; what M3 refuses is
     * inventing what that move means for participants, for future Matters, and
     * for references already issued. Lifting it requires its own architecture
     * decision, and this exception is where a future reader will find that out.
     */
    protected static function booted(): void
    {
        static::updating(function (self $project): void {
            if ($project->isDirty('office_id')) {
                throw new RuntimeException(
                    'projects.office_id is immutable during M3 (D-089). '
                    .'No Project Office-transfer operation is designed; lifting this needs its own decision.'
                );
            }

            // The internal reference is allocated once and then belongs to the
            // record (D-094). Changing it would break the one thing an
            // identifier is for, and would strand the counter that issued it.
            //
            // This fires on `updating` only, so it never blocks the allocation
            // itself: `CreateProject` stamps a *new* model, which is an insert.
            // M3.2 additionally allowed a null -> reference transition here,
            // because the column was nullable then; M3.3 made it NOT NULL, so
            // that branch became unreachable and was removed rather than left as
            // dead reassurance.
            if ($project->isDirty('project_number')) {
                throw new RuntimeException(
                    'projects.project_number is immutable once allocated (D-094). '
                    .'A reference belongs to the record that received it; archiving does not release it.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The person in charge — the `ASSIGNED` Data Scope predicate (D-088).
     *
     * @return BelongsTo<User, $this>
     */
    public function picUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    /**
     * The creator — the `OWN` Data Scope predicate (D-088).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'opened_at' => 'date',
            'target_completion_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
