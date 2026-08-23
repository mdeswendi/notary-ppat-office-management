<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Matter\Enums\MatterStageStatus;
use App\Domains\Matter\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\MatterWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Mark a Matter completed (M4.4, D-109).
 *
 * Sets `status = COMPLETED` and stamps `completed_at` from the application clock.
 *
 * **Why this stamps a timestamp when `ChangeProjectStatus` deliberately does
 * not.** Project has one generic status endpoint, and coupling `completed_at` to
 * a generic write would be wrong the first time somebody corrects a status set by
 * mistake. Matter has no generic status endpoint at all: `*.matters.complete` is
 * a **named lifecycle act** with exactly one meaning, so recording when it
 * happened is what the capability is for rather than an inference layered onto a
 * general-purpose field.
 *
 * **No transition matrix, and completion is not a lock** (D-102, following
 * D-091). Completing an already-completed Matter succeeds and re-stamps the time;
 * a cancelled Matter may be completed. Which status may follow which is an
 * operational rule no canonical document defines, and encoding one from memory is
 * the failure `CLAUDE.md` section 62 prohibits one domain removed. M4 authorizes
 * *who* may complete; the office decides *what* completing means.
 *
 * **Completion is not gated by a workflow, and it carries one with it**
 * *(M4.7, D-112)*. The gating half is unchanged from M4.4: no stage may prevent
 * a Matter being completed, because which stage permits completion is workflow
 * content nobody has validated (D-104). If domain authority later says otherwise,
 * that is a rule somebody states rather than one this action assumes.
 *
 * What M4.7 adds is the other direction. **A stage becomes `COMPLETED` by moving
 * on from it, so the final stage would never complete on its own** and
 * `matter_workflows.completed_at` would be unreachable schema. Completing the
 * Matter is the act an office already performs to finish work, and it is already
 * authorized by `*.matters.complete`, so it closes the run: the `ACTIVE` stage is
 * marked complete and the workflow is stamped. No new endpoint and no new
 * capability — inventing either would be asserting that finishing a Matter and
 * finishing its process are separate decisions, which no document says.
 *
 * A Matter with no workflow — the ordinary case while no template is configured —
 * completes exactly as it did at M4.4.
 *
 * **Nothing is deleted and no reference is released.** The record stays live and
 * readable, and `COMPLETED` is a business status rather than a persistence state
 * — `deleted_at` is untouched (D-102).
 */
class CompleteMatter
{
    public function handle(User $actor, Matter $matter): Matter
    {
        return DB::transaction(function () use ($actor, $matter): Matter {
            $matter->status = MatterStatus::COMPLETED;
            $matter->completed_at = Date::now();
            $matter->updated_by = $actor->getKey();
            $matter->save();

            $this->closeWorkflow($matter);

            return $matter->refresh();
        });
    }

    /**
     * Close the Matter's workflow run, if it has one.
     *
     * Idempotent, matching the action around it: completing an already-completed
     * Matter re-stamps both, and neither is a lock.
     *
     * **No history row is written.** History records stage *transitions*, and
     * this is not one — nothing moves anywhere. Writing a row whose
     * `from_stage_code` and `to_stage_code` were the same stage would put a
     * movement in the record that never happened.
     */
    private function closeWorkflow(Matter $matter): void
    {
        $workflow = MatterWorkflow::query()->where('matter_id', $matter->getKey())->first();

        if ($workflow === null) {
            return;
        }

        $now = Date::now();

        $workflow->stages()
            ->where('status', MatterStageStatus::ACTIVE->value)
            ->update([
                'status' => MatterStageStatus::COMPLETED->value,
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

        $workflow->completed_at = $now;
        $workflow->save();
    }
}
