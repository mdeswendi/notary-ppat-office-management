<?php

namespace App\Domains\Party\Actions;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update the ordinary profile fields of a Company aggregate.
 *
 * Ordinary means: not `tax_id`. The Company NPWP is reachable only through
 * {@see UpdateCompanyIdentity}, because `companies.update` and
 * `parties.identity.update` are separate capabilities and folding them together
 * here would let the first quietly acquire the second (D-082). The Form Request
 * refuses the key; this action never reads it.
 *
 * **`display_name` is recomputed on every update, not only when a name field was
 * submitted.** That is a deliberate difference from the Individual action, and
 * the reason is that the Company rule has two inputs rather than one: removing a
 * short name changes the display name without touching the legal name, and
 * adding one changes it without touching the legal name either. A conditional
 * "only if the name changed" would have to enumerate those cases correctly
 * forever. Asking the updated record what it should be called cannot get that
 * wrong, and when nothing name-related moved it writes back the same value.
 *
 * `office_id` is deliberately not handled. Moving a Party between Offices is not
 * designed: it would move a record across a security boundary and orphan any
 * company relationship pinned to the old Office. M2.3 rejects it rather than
 * invent semantics for it.
 */
class UpdateCompany
{
    /**
     * @param  array<string, mixed>  $partyAttributes
     * @param  array<string, mixed>  $companyAttributes
     */
    public function handle(
        User $actor,
        Company $company,
        array $partyAttributes,
        array $companyAttributes,
    ): Company {
        return DB::transaction(function () use ($actor, $company, $partyAttributes, $companyAttributes): Company {
            $company->fill($companyAttributes);
            $company->save();

            $party = $company->party;
            $party->fill($partyAttributes);
            $party->display_name = $company->preferredDisplayName();

            $party->updated_by = $actor->getKey();
            $party->save();

            return $company->fresh(['party']);
        });
    }
}
