<?php

namespace App\Domains\Party\Actions;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update the sensitive tax identity of a Company.
 *
 * Separate from {@see UpdateCompany} because the capabilities are separate.
 * Holding `parties.identity.update` authorizes writing `tax_id`; it grants no
 * ability to read back a value the actor could not otherwise see, which is why
 * nothing here returns a raw identifier and the controller answers with masked
 * data (D-082).
 *
 * The value is encrypted by the model cast on save, so no plaintext is written
 * and nothing here needs to know how that works.
 *
 * `display_name` is deliberately untouched: the tax identifier contributes
 * nothing to it, and a raw identifier must never reach a display field (D-082).
 *
 * `updated_by` is stamped on the Party, because the aggregate is what changed
 * from an audit standpoint even though the row written is the subtype.
 */
class UpdateCompanyIdentity
{
    /**
     * @param  array<string, mixed>  $identityAttributes
     */
    public function handle(User $actor, Company $company, array $identityAttributes): Company
    {
        return DB::transaction(function () use ($actor, $company, $identityAttributes): Company {
            $company->fill($identityAttributes);
            $company->save();

            $party = $company->party;
            $party->updated_by = $actor->getKey();
            $party->save();

            return $company->fresh(['party']);
        });
    }
}
