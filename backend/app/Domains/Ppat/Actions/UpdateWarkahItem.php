<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Party;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct a line of a Warkah (M7.4, D-121).
 *
 * **Reaches only what `PpatWarkahItem` marks fillable** — `requirement_code`, the two
 * titles, `sequence_no` and `notes` — plus `party_id`, which the controller resolves
 * through canonical Party visibility and this assigns explicitly.
 *
 * ```text
 * warkah_id   never — a line belongs to one bundle; the model refuses the change
 * office_id   never — the security boundary; likewise refused
 * status      never — the column has no canonical vocabulary (see below)
 * ```
 *
 * **`status` is absent for the reason {@see AddWarkahItem} gives at length**: the ERD
 * gives `ppat_warkah_items.status` no values, so M7.1 built no enum and left the
 * column out of the fillable set. The M7.4 brief listed six values; an item-status
 * vocabulary *is* the verification rule, which is open question three.
 *
 * What replaces it is the fact the interface actually shows: **whether a document is
 * attached to this line.** That is observable, needs no vocabulary, and is the same
 * fact `PpatWarkah::computeCompleteness()` counts.
 *
 * ## Changing the party is a correction, not a re-filing
 *
 * `party_id` says which person a line concerns — a seller's identity document rather
 * than the transaction's land certificate. The model guards `warkah_id` and
 * `office_id` and deliberately does not guard this one: an office that typed the wrong
 * party on a checklist line is correcting a line it wrote, not moving evidence between
 * transactions.
 *
 * Passing `null` clears it, which is how a line moves from belonging to one party to
 * belonging to the transaction as a whole.
 *
 * **Completeness is not recomputed**, because nothing here changes the numerator or
 * the denominator: the line still exists and still has whatever documents it had.
 * {@see AttachWarkahDocument} and {@see RemoveWarkahItem} are where those move.
 */
class UpdateWarkahItem
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        PpatWarkahItem $item,
        array $attributes,
        bool $partyGiven = false,
        ?Party $party = null,
    ): PpatWarkahItem {
        return DB::transaction(function () use ($item, $attributes, $partyGiven, $party): PpatWarkahItem {
            $item->fill($attributes);

            // `array_key_exists` semantics, passed in by the controller: a caller who
            // sent `party_id: null` means "clear it", and one who omitted the key means
            // "leave it". `??` cannot tell those apart, because it coalesces on a null
            // *value* rather than a missing key.
            if ($partyGiven) {
                $item->party_id = $party?->getKey();
            }

            $item->save();

            return $item;
        });
    }
}
