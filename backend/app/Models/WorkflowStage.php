<?php

namespace App\Models;

use App\Domains\Authorization\PermissionRegistry;
use Database\Factories\WorkflowStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One configured step inside a workflow template (M4.6, D-104, D-111).
 *
 * **Mechanism, empty of content.** That a stage *can* require approval, carry a
 * target duration, start a process or complete one is architecture. *Which*
 * stages do any of that is workflow content, and D-104 forbids seeding or
 * inferring it. Nothing here ships populated.
 *
 * **`sequence_no` is position within this template and nothing else.** Unlike
 * `matter_parties.sequence_no`, which D-105 deferred because four plausible
 * meanings competed, this one has a settled structural meaning: the order the
 * engine reads stages in. It is not a signing order, not a legal priority, and
 * not an order of appearance in a deed.
 *
 * **`is_completion_stage` marks the end of a configured process, not a legal
 * effect.** Whether reaching it means anything for a deed is undecided and must
 * not be inferred (D-104). Matter Status and Workflow Stage stay separate
 * concepts.
 *
 * ## `approval_permission`, and why it is validated here
 *
 * The column stores a permission code as data, which is an authorization surface
 * configured by text. It is guarded rather than trusted: **a value that is not a
 * canonical permission code is refused on save** (D-111). Left open, a typo or a
 * renamed code would sit in the table until M4.7 tried to resolve it and had to
 * invent a meaning for an unknown string — and "unknown" is exactly the case
 * where inventing a meaning is most dangerous.
 *
 * Storing a code authorizes nothing by itself. Whatever eventually reads this
 * must still go through a Policy and `EffectiveAccessResolver` with the actor's
 * Data Scope, like every other decision in the application (D-048, `CLAUDE.md`
 * section 24). This column names *which* capability a stage asks for; it never
 * answers whether somebody has it.
 */
#[Fillable([
    'code',
    'name_id',
    'name_en',
    'sequence_no',
    'target_days',
    'requires_approval',
    'approval_permission',
    'is_start_stage',
    'is_completion_stage',
])]
class WorkflowStage extends Model
{
    /** @use HasFactory<WorkflowStageFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::saving(function (self $stage): void {
            $permission = $stage->approval_permission;

            // Null is the ordinary case: most stages ask for no specific
            // capability, and `requires_approval` alone is a meaningful state.
            if ($permission === null) {
                return;
            }

            if (! in_array($permission, PermissionRegistry::all(), true)) {
                throw new RuntimeException(
                    "workflow_stages.approval_permission must name a canonical permission, got '{$permission}' (M4.6). "
                    .'An unregistered code is unresolvable, so storing one would defer to runtime a question '
                    .'that has no safe answer. Register the permission first, or leave the column null.'
                );
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'target_days' => 'integer',
            'requires_approval' => 'boolean',
            'is_start_stage' => 'boolean',
            'is_completion_stage' => 'boolean',
        ];
    }
}
