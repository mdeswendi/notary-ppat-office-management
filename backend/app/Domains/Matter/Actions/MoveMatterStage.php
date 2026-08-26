<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Matter\Enums\MatterStageStatus;
use App\Models\Matter;
use App\Models\MatterStageHistory;
use App\Models\MatterStageInstance;
use App\Models\MatterWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a Matter to another stage of its running workflow (M4.7, D-104, D-112).
 *
 * ## There is no transition matrix, and its absence is deliberate
 *
 * M4 authorizes **who** may change a stage and never encodes **which** stage may
 * follow which (D-104). Nothing here asks whether the move is sensible, whether
 * prerequisites are met, whether documents are complete, or whether a tax or deed
 * gate is satisfied — every one of those is workflow content that no validated
 * domain source has authored, and inventing one would be exactly the invented
 * legal rule `CLAUDE.md` section 62 forbids.
 *
 * Two checks and no more:
 *
 *   1. the target stage belongs to **this Matter's** workflow;
 *   2. its status is open — `PENDING` or `ACTIVE`.
 *
 * The second is not a transition rule. It says a destination must be somewhere
 * you can go, not which destinations follow which origins: a finished, skipped,
 * or blocked stage is not a place.
 *
 * ## What a move does to the stage you leave — the M4.7 ruling
 *
 * Exactly one stage is `ACTIVE`, so a move has to say what becomes of the one
 * that was. **It becomes `COMPLETED`**, because moving on from a stage is what
 * finishing it means operationally (D-112).
 *
 * **Stages jumped over are left `PENDING` and untouched.** Marking them `SKIPPED`
 * would infer a decision from a navigation — skipping is something somebody
 * chooses, and moving to a later stage is not that choice. `SKIPPED` and
 * `BLOCKED` therefore stay vocabulary nothing sets, recorded as a gap rather than
 * filled by inference. The same shape M4.4 left for the unreachable Matter
 * statuses.
 *
 * Moving to the stage already active is refused: it would record a transition
 * that did not happen and would mark the stage complete and active at once.
 *
 * ## Matter Status is untouched
 *
 * Matter Status and Workflow Stage are separate concepts and must not be merged
 * (`CLAUDE.md` section 18, D-104). Moving a stage never writes `matters.status`,
 * and completing a Matter is its own act with its own capability.
 *
 * One transaction: the stage updates and the history row are one change, and a
 * history that recorded a move the stages did not make would be worse than no
 * history.
 */
class MoveMatterStage
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(
        User $actor,
        Matter $matter,
        MatterWorkflow $workflow,
        string $targetStageCode,
        ?string $reason,
    ): MatterStageInstance {
        return DB::transaction(function () use ($actor, $matter, $workflow, $targetStageCode, $reason): MatterStageInstance {
            $stages = $workflow->stages()->lockForUpdate()->get();

            $target = $stages->firstWhere('stage_code', $targetStageCode);

            if ($target === null) {
                throw new RuntimeException("No stage [{$targetStageCode}] in this Matter's workflow.");
            }

            if (! $target->status->isSelectableAsTarget()) {
                throw new RuntimeException("Stage [{$targetStageCode}] is not open to move to.");
            }

            $current = $stages->firstWhere(
                fn (MatterStageInstance $stage): bool => $stage->status === MatterStageStatus::ACTIVE,
            );

            if ($current !== null && $current->getKey() === $target->getKey()) {
                throw new RuntimeException("Stage [{$targetStageCode}] is already the current stage.");
            }

            $from = $current?->stage_code;

            if ($current !== null) {
                $current->status = MatterStageStatus::COMPLETED;
                $current->completed_at = Date::now();
                $current->save();
            }

            $target->status = MatterStageStatus::ACTIVE;

            // Only on first entry. Re-entering a stage keeps the moment work on
            // it actually began, which is what the column records; overwriting it
            // would quietly rewrite history the audit trail already holds.
            $target->started_at ??= Date::now();

            // Reopening a stage clears its completion, because it is no longer
            // complete. The history row records that this happened.
            $target->completed_at = null;

            $target->save();

            $history = new MatterStageHistory;
            $history->matter_id = $matter->getKey();
            $history->from_stage_code = $from;
            $history->to_stage_code = $target->stage_code;
            $history->changed_by = $actor->getKey();
            $history->reason = $reason;
            $history->changed_at = Date::now();
            $history->save();

            $this->events->statusChanged(
                $matter,
                $actor,
                $from,
                $target->stage_code,
                ActivityType::MATTER_STAGE_CHANGED,
                ['stage' => $target->stage_code],
                $reason,
            );

            return $target;
        });
    }
}
