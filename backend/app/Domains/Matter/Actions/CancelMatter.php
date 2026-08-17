<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Matter\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark a Matter cancelled (M4.4, D-109).
 *
 * Sets `status = CANCELLED`, and that is the whole of it.
 *
 * **No cancellation reason, and no cancellation timestamp.** Neither is canonical
 * anywhere in this repository: `03_DATABASE_ERD.md` section 9 gives `matters` no
 * such column, and adding one here would be a business rule wearing an
 * implementation's clothing — the office would be told the system records why work
 * was cancelled when nobody has decided what that record means or who may read it.
 * `notes` remains available for an office that wants to write one, under ordinary
 * update. If a structured reason is later required it needs its own decision and
 * its own column.
 *
 * **`completed_at` is deliberately untouched.** A cancelled Matter was not
 * completed, and clearing a timestamp that a completion genuinely produced would
 * destroy a fact rather than correct one.
 *
 * **No transition matrix, and cancellation is not a lock** (D-102, following
 * D-091). Cancelling an already-cancelled Matter succeeds; a completed Matter may
 * be cancelled, and a cancelled one may be completed. Which status may follow
 * which is an operational rule no canonical document defines.
 *
 * **Nothing is deleted and no reference is released.** `CANCELLED` is a business
 * status, never a persistence state — `deleted_at` is untouched, and M4 ships no
 * archive or restore lifecycle (D-102). The Matter stays in the ordinary list and
 * keeps its internal reference permanently.
 */
class CancelMatter
{
    public function handle(User $actor, Matter $matter): Matter
    {
        return DB::transaction(function () use ($actor, $matter): Matter {
            $matter->status = MatterStatus::CANCELLED;
            $matter->updated_by = $actor->getKey();
            $matter->save();

            return $matter->refresh();
        });
    }
}
