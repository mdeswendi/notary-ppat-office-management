<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Models\PpatWarkah;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Set a Warkah's status (M7.4, D-121).
 *
 * ## Two values, and the other three are reached elsewhere or not at all
 *
 * ```text
 * INCOMPLETE    here, under ppat.warkah.update
 * UNDER_REVIEW  here, under ppat.warkah.update
 *
 * COMPLETE      VerifyWarkah, under ppat.warkah.verify
 * FINALIZED     nowhere — registered, unimplemented
 * ARCHIVED      nowhere — registered, unimplemented
 * ```
 *
 * **`COMPLETE` is refused here on purpose.** It is the one status that comes with a
 * stamped pair — `verified_at` and `verified_by` — and it answers to
 * `ppat.warkah.verify`. Accepting it through the update code would let one capability
 * perform an act a separate one was granted to control (D-091), which is exactly the
 * distinction an office draws when it separates assembling evidence from checking it.
 *
 * **`FINALIZED` and `ARCHIVED` are refused everywhere**, because their trigger is open
 * question eight and `09_PPAT_WORKFLOW.md` section 2 names those obligations as
 * *"precisely the kind of rule that must not be reconstructed from memory."* The two
 * codes stay registered and unimplemented (D-064, O-041).
 *
 * ## There is no transition matrix, and that is a ruling rather than an omission
 *
 * The M7.4 brief specified one: `INCOMPLETE → UNDER_REVIEW` requiring at least one
 * item, `UNDER_REVIEW → COMPLETE` requiring every item verified or not-applicable,
 * `COMPLETE → FINALIZED` requiring `verified_by`. Each of those is a **verification
 * rule**, and *"what is the mandatory Warkah composition per deed type?"* is open
 * question three. The M7 lock section 8.2 settles it in four words: **"Status is
 * settable and not gated."**
 *
 * Two of the three gates are also unbuildable on their own terms. "Every item
 * `VERIFIED` or `NOT_APPLICABLE`" needs an item-status vocabulary, and
 * `ppat_warkah_items.status` has none — the ERD gives that column no values, which is
 * the whole reason completeness counts documents instead. And `COMPLETE → FINALIZED`
 * needs a `FINALIZED` nothing reaches.
 *
 * What *is* enforced is the capability, which is the honest gate: D-102 refused a
 * transition matrix on `MatterStatus` for the same reason and said so in that enum's
 * own docblock.
 *
 * ## Moving back does not erase who verified it
 *
 * Sending a bundle back to `UNDER_REVIEW` leaves `verified_at` and `verified_by`
 * standing. Somebody did check it on that date; that is a fact, and `CLAUDE.md`
 * section 63 asks that facts not be overwritten because the current state moved on.
 * Re-verifying overwrites the pair with the newer act, which is the same record kept
 * current rather than a second one appended — the ERD gives this table one pair of
 * columns and no history table beside it.
 *
 * **The percentage is not touched.** Status is somebody's decision and completeness is
 * an arithmetic fact; the lock forbids deriving either from the other in either
 * direction.
 */
class SetWarkahStatus
{
    /**
     * The statuses this action accepts.
     *
     * @return array<int, PpatWarkahStatus>
     */
    public static function settable(): array
    {
        return [PpatWarkahStatus::INCOMPLETE, PpatWarkahStatus::UNDER_REVIEW];
    }

    /**
     * @return array<int, string>
     */
    public static function settableValues(): array
    {
        return array_map(
            static fn (PpatWarkahStatus $status): string => $status->value,
            self::settable(),
        );
    }

    public function handle(User $actor, PpatWarkah $warkah, PpatWarkahStatus $status): PpatWarkah
    {
        return DB::transaction(function () use ($warkah, $status): PpatWarkah {
            $warkah->status = $status;
            $warkah->save();

            return $warkah;
        });
    }
}
