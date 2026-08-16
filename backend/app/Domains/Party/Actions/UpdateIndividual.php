<?php

namespace App\Domains\Party\Actions;

use App\Models\Individual;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update the ordinary profile fields of an Individual aggregate.
 *
 * Ordinary means: not the sensitive identity fields. `nik` and `npwp` are
 * reachable only through {@see UpdateIndividualIdentity}, because
 * `parties.update` and `parties.identity.update` are separate capabilities and
 * folding them together here would let the first quietly acquire the second
 * (D-082). The Form Request refuses those keys; this action never reads them.
 *
 * When the canonical full name changes, `parties.display_name` changes with it in
 * the same transaction (D-079). Without that, a rename leaves the directory
 * showing the old name while the detail page shows the new one — and the
 * directory is what people search.
 *
 * `office_id` is deliberately not handled. Moving a Party between Offices is not
 * designed: it would move a record across a security boundary and orphan any
 * company relationship pinned to the old Office. M2.2 rejects it rather than
 * invent semantics for it.
 */
class UpdateIndividual
{
    /**
     * @param  array<string, mixed>  $partyAttributes
     * @param  array<string, mixed>  $individualAttributes
     */
    public function handle(
        User $actor,
        Individual $individual,
        array $partyAttributes,
        array $individualAttributes,
    ): Individual {
        return DB::transaction(function () use ($actor, $individual, $partyAttributes, $individualAttributes): Individual {
            $individual->fill($individualAttributes);
            $individual->save();

            $party = $individual->party;
            $party->fill($partyAttributes);

            if (array_key_exists('full_name', $individualAttributes)) {
                $party->display_name = trim((string) $individualAttributes['full_name']);
            }

            $party->updated_by = $actor->getKey();
            $party->save();

            return $individual->fresh(['party']);
        });
    }
}
