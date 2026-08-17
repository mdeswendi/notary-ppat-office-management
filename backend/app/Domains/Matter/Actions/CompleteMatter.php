<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Matter\Enums\MatterStatus;
use App\Models\Matter;
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
 * **No workflow is consulted**, because none exists (D-104). Completion is not
 * gated by a stage, and no stage is advanced by it. If domain authority later
 * says a Matter may only be completed from a particular stage, that is a rule
 * somebody states rather than one this action assumes.
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

            return $matter->refresh();
        });
    }
}
