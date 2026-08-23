<?php

namespace App\Models;

use Database\Factories\MatterWorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Matter's run of one workflow template (M4.7, D-104, D-112).
 *
 * **One per Matter**, enforced by `UNIQUE (matter_id)`. A second instantiation
 * would leave two answers to "what is this Matter doing" and nothing could choose
 * between them. The consequence is recorded rather than papered over: a Matter
 * created before its office configured any template can never acquire a workflow
 * later, because M4.7 builds no re-instantiation path. That needs its own
 * decision.
 *
 * **`workflow_version` is what makes the snapshot legible.** M4.6 made `version`
 * a counter on a single template row (D-111), so the foreign key says *which*
 * template and this says *which iteration of it*. The stage instances carry the
 * content of that iteration, so editing the template afterwards changes nothing
 * here — which is exactly what `CLAUDE.md` section 18 requires.
 *
 * **Nothing here is fillable.** Every column is decided by the instantiating
 * action or by completing the Matter; none is ever a request field.
 */
#[Fillable([])]
class MatterWorkflow extends Model
{
    /** @use HasFactory<MatterWorkflowFactory> */
    use HasFactory;

    use HasUlids;

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    /**
     * The stages of this run, in snapshot order.
     *
     * Ordered here rather than at every call site: a workflow read out of
     * sequence is not a workflow. `sequence_no` is unique per run, so the order
     * is total.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(MatterStageInstance::class)->orderBy('sequence_no');
    }

    protected function casts(): array
    {
        return [
            'workflow_version' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
