<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Matter\Actions\MoveMatterStage;
use App\Domains\Matter\Enums\MatterStageStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesMatterDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matter\MoveMatterStageRequest;
use App\Http\Resources\MatterStageResource;
use App\Models\Matter;
use App\Models\MatterStageHistory;
use App\Models\MatterStageInstance;
use App\Models\MatterWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A Matter's running workflow: what stage it is on, where it can go, and how it
 * got there (M4.7, D-104, D-112).
 *
 * **One capability governs the writes**: `*.matters.change_stage`, canonical
 * since the catalogue was transcribed and badged deferred from M4.4 until this
 * milestone gave it a route. It carries the four Matter predicates like every
 * other Matter capability, and none of `update`, `assign`, `complete` or `cancel`
 * reaches it.
 *
 * **Reading the workflow answers to `*.matters.view`.** A stage is part of what a
 * Matter *is*, not a separate resource with its own audience — unlike
 * participation, which the registry gave its own pair of codes (D-105). Inventing
 * a `*.matters.stages.view` here would change the canonical count for a read that
 * the Matter's own visibility already governs.
 *
 * **The domain comes from the route** (D-101), and a Matter of the other domain
 * answers **404** rather than 403, so the paired endpoints cannot be used to
 * discover that a record exists across the Notary/PPAT boundary.
 *
 * **A Matter with no workflow is an ordinary state, not an error.** D-104 seeds
 * no templates, so on a fresh deployment every Matter is in exactly that state.
 * These endpoints answer 200 with an empty workflow rather than 404, because the
 * Matter genuinely exists and genuinely has no process configured — and the
 * interface needs to say so rather than look broken.
 */
class MatterStageController extends Controller
{
    use ResolvesMatterDomain;

    /**
     * The workflow run: every stage, the current one, and the transition history.
     *
     * Reading answers to the Matter's own view capability. The history is
     * append-only (D-104) and is returned oldest first, because a sequence of
     * events reads forwards.
     */
    public function index(Request $request, string $matter): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('view', [$subject, $domain]);

        $workflow = $this->workflowFor($subject);

        $stages = $workflow === null
            ? collect()
            : $workflow->stages()->with('assignee')->get();

        $history = MatterStageHistory::query()
            ->where('matter_id', $subject->getKey())
            ->with('actor')
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();

        $current = $stages->first(
            fn (MatterStageInstance $stage): bool => $stage->status === MatterStageStatus::ACTIVE,
        );

        return response()->json([
            'data' => [
                'workflow' => $workflow === null ? null : [
                    'id' => $workflow->getKey(),
                    // The iteration this Matter was started from, which is what
                    // makes the snapshot legible (D-111).
                    'workflow_version' => $workflow->workflow_version,
                    'started_at' => $workflow->started_at?->toIso8601String(),
                    'completed_at' => $workflow->completed_at?->toIso8601String(),
                ],

                'current_stage' => $current === null
                    ? null
                    : (new MatterStageResource($current))->toArray($request),

                'stages' => $stages->map(
                    fn (MatterStageInstance $stage): array => (new MatterStageResource($stage))->toArray($request)
                )->all(),

                // Codes rather than resolved stage rows, exactly as stored: a
                // later template edit must not rewrite what the record says
                // happened.
                'history' => $history->map(fn (MatterStageHistory $entry): array => [
                    'id' => $entry->getKey(),
                    'from_stage_code' => $entry->from_stage_code,
                    'to_stage_code' => $entry->to_stage_code,
                    'reason' => $entry->reason,
                    'changed_at' => $entry->changed_at?->toIso8601String(),
                    'changed_by' => $entry->actor === null
                        ? null
                        : ['id' => $entry->actor->getKey(), 'name' => $entry->actor->name],
                ])->all(),
            ],

            'meta' => [
                'has_workflow' => $workflow !== null,
                'can_change_stage' => $request->user()->can('changeStage', [$subject, $domain]),
            ],
        ]);
    }

    /**
     * The stages this Matter may be moved to.
     *
     * Open stages only — `PENDING` or `ACTIVE`. **This is not a transition
     * matrix** (D-104): it says a destination must be somewhere you can go, never
     * which destinations follow which origins. The currently active stage is
     * excluded, because moving to where you already are is not a move.
     *
     * Answers to `change_stage` rather than `view`: this list exists to be acted
     * on, and offering it to somebody who cannot act would be a menu of refusals.
     */
    public function options(Request $request, string $matter): JsonResponse
    {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('changeStage', [$subject, $domain]);

        $workflow = $this->workflowFor($subject);

        $stages = $workflow === null
            ? collect()
            : $workflow->stages()->get()
                ->filter(fn (MatterStageInstance $stage): bool => $stage->status->isSelectableAsTarget()
                    && $stage->status !== MatterStageStatus::ACTIVE)
                ->values();

        return response()->json([
            'data' => [
                'stages' => $stages->map(fn (MatterStageInstance $stage): array => [
                    'stage_code' => $stage->stage_code,
                    'stage_name_id' => $stage->stage_name_snapshot_id,
                    'stage_name_en' => $stage->stage_name_snapshot_en,
                    'sequence_no' => $stage->sequence_no,
                    'status' => $stage->status->value,
                ])->all(),
            ],
        ]);
    }

    /**
     * Move the Matter to another stage of its workflow.
     *
     * The action decides everything about validity against the running instance —
     * a Form Request cannot see which workflow this Matter is on. A refusal is a
     * 422 with the reason, because "that stage is finished" is something the
     * caller can act on, unlike the deliberately indistinguishable refusals the
     * Party surfaces give.
     */
    public function move(
        MoveMatterStageRequest $request,
        string $matter,
        MoveMatterStage $move,
    ): JsonResponse {
        $domain = $this->matterDomain($request);
        $subject = $this->resolveMatter($domain, $matter);

        $this->authorize('changeStage', [$subject, $domain]);

        $workflow = $this->workflowFor($subject);

        if ($workflow === null) {
            abort(422, 'This Matter has no workflow to move through.');
        }

        try {
            $stage = $move->handle(
                $request->user(),
                $subject,
                $workflow,
                $request->validated('target_stage_code'),
                $request->validated('reason'),
            );
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }

        $stage->load('assignee');

        return response()->json(['data' => (new MatterStageResource($stage))->toArray($request)]);
    }

    private function workflowFor(Matter $matter): ?MatterWorkflow
    {
        return MatterWorkflow::query()->where('matter_id', $matter->getKey())->first();
    }
}
