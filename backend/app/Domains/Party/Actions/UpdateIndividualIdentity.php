<?php

namespace App\Domains\Party\Actions;

use App\Models\Individual;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update the sensitive identity fields of an Individual.
 *
 * Separate from {@see UpdateIndividual} because the capabilities are separate.
 * Holding `parties.identity.update` authorizes writing these values; it grants no
 * ability to read back a value the actor could not otherwise see, which is why
 * nothing here returns a raw identifier and the controller answers with masked
 * data (D-082).
 *
 * The values are encrypted by the model casts on save, so no plaintext is written
 * and nothing here needs to know how that works.
 *
 * `updated_by` is stamped on the Party, because the aggregate is what changed
 * from an audit standpoint even though the row written is the subtype.
 */
class UpdateIndividualIdentity
{
    /**
     * @param  array<string, mixed>  $identityAttributes
     */
    public function handle(User $actor, Individual $individual, array $identityAttributes): Individual
    {
        return DB::transaction(function () use ($actor, $individual, $identityAttributes): Individual {
            $individual->fill($identityAttributes);
            $individual->save();

            $party = $individual->party;
            $party->updated_by = $actor->getKey();
            $party->save();

            return $individual->fresh(['party']);
        });
    }
}
