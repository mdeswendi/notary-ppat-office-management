<?php

namespace App\Models;

use App\Domains\Matter\Enums\MatterStageStatus;
use Database\Factories\MatterStageInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of one Matter's workflow run (M4.7, D-104, D-112).
 *
 * ## The snapshot, and the column name that misleads
 *
 * `stage_code`, `stage_name_snapshot_id`, `stage_name_snapshot_en` and
 * `sequence_no` are **copied at instantiation and never refreshed**. Renaming or
 * renumbering a template stage afterwards changes nothing here, which is what
 * `CLAUDE.md` section 18 requires and what D-104 calls the point of the design
 * rather than decoration.
 *
 * **`stage_name_snapshot_id` is not a foreign key.** The `_id` is the ISO 639-1
 * code for Bahasa Indonesia, matching `name_id` / `name_en` throughout this
 * schema; the column holds a displayable stage name, not a ULID. Every other
 * `*_id` column in the Matter domain does hold a reference, so the name genuinely
 * invites a wrong join — it is transcribed from `03_DATABASE_ERD.md` section 11
 * rather than renamed, and a test asserts it holds a name.
 *
 * ## `assigned_user_id` is not an authorization predicate
 *
 * **A stage assignee gains no Matter reach** (D-100). Matter `ASSIGNED` means
 * `matters.pic_user_id` and nothing else; consulting this column in a scope
 * predicate would widen `ASSIGNED` silently, for a role nobody granted. Nothing
 * in M4.7 writes it — no stage-assignment surface exists — and a test asserts
 * `MatterVisibility` ignores it.
 *
 * ## Approval is recorded, not performed
 *
 * `approved_at` and `approved_by` exist because the ERD names them and because
 * M4.6 gave stages `requires_approval` and `approval_permission`. **M4.7 ships no
 * approval endpoint**, so both stay null. Whichever milestone approves must
 * resolve `approval_permission` through a Policy and `EffectiveAccessResolver`
 * with the actor's Data Scope (D-048, D-111); the stored code names a capability
 * and never answers who holds it.
 *
 * Only `status` and the two lifecycle timestamps are fillable: the snapshot and
 * the endpoints identify the row rather than describing it.
 */
#[Fillable([
    'status',
    'started_at',
    'completed_at',
])]
class MatterStageInstance extends Model
{
    /** @use HasFactory<MatterStageInstanceFactory> */
    use HasFactory;

    use HasUlids;

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(MatterWorkflow::class, 'matter_workflow_id');
    }

    /**
     * The template stage this was instantiated from.
     *
     * Kept as a `RESTRICT` reference so a template cannot be deleted out from
     * under a running Matter — the line that stops M4.6's template-to-stage
     * cascade from reaching this table (D-112). **Reading the current template
     * stage is not how a name is displayed**: the snapshot columns are, because
     * the template may have changed since.
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => MatterStageStatus::class,
            'sequence_no' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
