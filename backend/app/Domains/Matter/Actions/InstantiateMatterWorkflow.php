<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Matter\Enums\MatterStageStatus;
use App\Models\Matter;
use App\Models\MatterStageHistory;
use App\Models\MatterStageInstance;
use App\Models\MatterWorkflow;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Start a Matter's workflow from a template (M4.7, D-104, D-112).
 *
 * ## Doing nothing is the ordinary outcome
 *
 * **A deployment with no configured template instantiates no workflow, and the
 * Matter is created anyway.** That is not an error path — it is the *normal*
 * path, because D-104 forbids seeding workflow content, so a fresh deployment has
 * no templates at all until an office enters some. Failing Matter creation
 * because nobody has configured a process yet would make the entire Matter module
 * depend on domain validation that has not happened.
 *
 * The method returns `null` in that case so the caller can see it happened rather
 * than having to infer it.
 *
 * ## Choosing the template, deterministically
 *
 * M4.6 deliberately put no uniqueness on `is_default` (D-111), so the database
 * may hand back several. **This action must therefore break ties itself and say
 * how**, which is what D-111 required of M4.7:
 *
 *   1. the Matter's own Service Type, if it has one — a template configured for
 *      this exact service beats a generic one;
 *   2. otherwise the Office's generic default — `service_type_id` null;
 *   3. within either, `is_default` first, then the **oldest** by `id`.
 *
 * Oldest rather than newest, because a ULID sorts by creation time and the
 * established default is the one the office has been using. A newest-wins rule
 * would let a template created this morning silently capture every new Matter.
 *
 * Only `is_active` templates are considered: a retired template is exactly one
 * that should not start new work (D-111).
 *
 * **The Office is the Matter's own.** A template from another Office is never
 * eligible, which the `office_id` filter enforces here and the composite key on
 * `workflow_templates` makes structurally impossible to configure anyway.
 *
 * ## The snapshot
 *
 * Every stage copies `stage_code`, both names, and `sequence_no` at this moment.
 * Editing the template afterwards changes nothing already running — the
 * requirement of `CLAUDE.md` section 18, and the reason
 * `matter_stage_instances.workflow_stage_id` is `RESTRICT` rather than `CASCADE`.
 *
 * The first stage becomes `ACTIVE` and is stamped `started_at`; the rest are
 * `PENDING`. **`is_start_stage` is deliberately not consulted**: it is a template
 * marker whose meaning no canonical document settles, and honouring it here would
 * be inferring workflow semantics D-104 forbids. Sequence order is structural and
 * already total.
 *
 * A template with no stages produces a workflow with none — recorded rather than
 * refused, since "a template that configures nothing" is a configuration mistake
 * for an office to see, not a reason to fail creating a Matter.
 *
 * **This action opens no transaction of its own**, so it participates in the
 * caller's. `CreateMatter` already runs one, and a workflow that committed while
 * its Matter rolled back would be an orphan the unique key would then block
 * forever.
 */
class InstantiateMatterWorkflow
{
    public function handle(User $actor, Matter $matter): ?MatterWorkflow
    {
        $template = $this->templateFor($matter);

        if ($template === null) {
            return null;
        }

        $workflow = new MatterWorkflow;
        $workflow->matter_id = $matter->getKey();
        $workflow->workflow_template_id = $template->getKey();
        $workflow->workflow_version = $template->version;
        $workflow->started_at = Date::now();
        $workflow->save();

        $stages = $template->stages()->get();

        foreach ($stages as $index => $stage) {
            $this->snapshot($workflow, $stage, isFirst: $index === 0);
        }

        // The opening transition, recorded like any other. `from_stage_code` is
        // null because there is no origin — that is what the nullable column is
        // for, and omitting the row entirely would make a Matter's history start
        // mid-story.
        if ($stages->isNotEmpty()) {
            $this->record($actor, $matter, null, $stages->first()->code);
        }

        return $workflow;
    }

    private function templateFor(Matter $matter): ?WorkflowTemplate
    {
        if ($matter->service_type_id !== null) {
            $forService = $this->query($matter)
                ->where('service_type_id', $matter->service_type_id)
                ->first();

            if ($forService !== null) {
                return $forService;
            }
        }

        return $this->query($matter)->whereNull('service_type_id')->first();
    }

    /**
     * @return Builder<WorkflowTemplate>
     */
    private function query(Matter $matter)
    {
        return WorkflowTemplate::query()
            ->where('office_id', $matter->office_id)
            ->where('is_active', true)
            // The tie-break, in one place. `is_default` first because that is
            // what the flag is for; `id` second because several may carry it and
            // something must decide (D-111).
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    private function snapshot(MatterWorkflow $workflow, WorkflowStage $stage, bool $isFirst): void
    {
        $instance = new MatterStageInstance;

        $instance->matter_workflow_id = $workflow->getKey();
        $instance->workflow_stage_id = $stage->getKey();

        // Copied, never read back through the relation afterwards.
        $instance->stage_code = $stage->code;
        $instance->stage_name_snapshot_id = $stage->name_id;
        $instance->stage_name_snapshot_en = $stage->name_en;
        $instance->sequence_no = $stage->sequence_no;

        $instance->status = $isFirst ? MatterStageStatus::ACTIVE : MatterStageStatus::PENDING;
        $instance->started_at = $isFirst ? Date::now() : null;

        // Assignment and approval are somebody else's milestone.
        $instance->assigned_user_id = null;
        $instance->approved_at = null;
        $instance->approved_by = null;

        $instance->save();
    }

    private function record(User $actor, Matter $matter, ?string $from, string $to): void
    {
        $history = new MatterStageHistory;

        $history->matter_id = $matter->getKey();
        $history->from_stage_code = $from;
        $history->to_stage_code = $to;
        $history->changed_by = $actor->getKey();
        $history->reason = null;
        $history->changed_at = Date::now();

        $history->save();
    }
}
