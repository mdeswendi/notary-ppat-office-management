<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Models\PpatWarkah;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Mark a Warkah verified (M7.4, D-121).
 *
 * Sets `COMPLETE` and stamps `verified_at` / `verified_by` together — the database
 * CHECK and `PpatWarkah::booted()` both refuse the pair half-written, so they are
 * assigned in one save.
 *
 * **Its own capability, `ppat.warkah.verify`**, and the only path to `COMPLETE`.
 * {@see SetWarkahStatus} refuses that value precisely so this act cannot be performed
 * through the update code.
 *
 * ## What this deliberately does not check
 *
 * **Not completeness.** A bundle at 40% may be verified and one at 100% need not be.
 * The M7 lock section 8.2: *"100% does not mean complete in law. It means every item
 * this office listed has a document."* Requiring 100% would assert that the office's
 * own checklist is the legal requirement, which is open question three — *"what is the
 * mandatory Warkah composition per deed type?"*
 *
 * **Not the item statuses.** `ppat_warkah_items.status` has no canonical vocabulary at
 * all, which is why completeness counts documents rather than statuses. Gating on a
 * vocabulary that does not exist is not a stricter rule; it is an invented one.
 *
 * **Not the current status.** There is no transition matrix (see
 * {@see SetWarkahStatus}); a bundle may be verified from `INCOMPLETE` without passing
 * through `UNDER_REVIEW`, because no canonical document says otherwise and D-102
 * refused exactly this shape of rule for `MatterStatus`.
 *
 * **Not the deed.** *"No completeness percentage gates any deed act"* runs the other
 * way too: finalizing a PPAT deed with an empty Warkah is permitted, and verifying a
 * Warkah says nothing about the deed's own lifecycle. Which must precede which is open
 * questions three and eight together.
 *
 * An office that requires any of these enforces it as practice until somebody writes
 * it down.
 *
 * ## Re-verifying replaces the stamp
 *
 * The ERD gives this table one `verified_at` / `verified_by` pair and no history table
 * beside it, so a second verification overwrites the first. That is the record kept
 * current, not history destroyed — a Warkah has one current verification the way a
 * deed has one current status.
 */
class VerifyWarkah
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, PpatWarkah $warkah, ?string $notes = null): PpatWarkah
    {
        return DB::transaction(function () use ($actor, $warkah, $notes): PpatWarkah {
            $from = $warkah->status?->value;

            $warkah->status = PpatWarkahStatus::COMPLETE;

            // Written together; the CHECK and the model guard both refuse a half pair.
            $warkah->verified_at = Date::now();
            $warkah->verified_by = $actor->getKey();

            if ($notes !== null) {
                $warkah->notes = $notes;
            }

            $warkah->save();

            $this->events->statusChanged(
                $warkah,
                $actor,
                $from,
                $warkah->status->value,
                ActivityType::WARKAH_VERIFIED,
            );

            return $warkah;
        });
    }
}
