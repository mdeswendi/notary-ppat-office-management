<?php

namespace App\Domains\Party\Actions;

use App\Domains\Party\Enums\PartyType;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a Party aggregate of type COMPANY.
 *
 * The mirror of {@see CreateIndividual}, and for the same reason: M2.0 invariant
 * 5 — "no Party without a subtype" — is the one the database cannot carry
 * (D-078), so it rests entirely on this transaction. A Party that outlives a
 * failed subtype insert is a defect state, and the only thing preventing one is
 * that this method rolls back.
 *
 * `party_type` is set from the enum, never from input. The caller chooses fields;
 * it does not choose what kind of aggregate this is, so there is no request shape
 * that could produce an INDIVIDUAL through the Company endpoint.
 *
 * **`display_name` is derived before the Party is written.** The Company decides
 * it — short name when one was intentionally recorded, otherwise the legal name
 * (D-079) — so the subtype is built first, in memory, and asked. The alternative,
 * writing a placeholder and correcting it after the subtype saves, would leave a
 * window where the directory shows something nobody chose.
 *
 * The destination Office arrives already authorized — the Policy judged it before
 * this ran. This action does not re-decide authorization; it records the actor.
 */
class CreateCompany
{
    /**
     * @param  array<string, mixed>  $partyAttributes
     * @param  array<string, mixed>  $companyAttributes
     */
    public function handle(
        User $actor,
        string $officeId,
        array $partyAttributes,
        array $companyAttributes,
    ): Company {
        return DB::transaction(function () use ($actor, $officeId, $partyAttributes, $companyAttributes): Company {
            // Built but not saved: it has no party_id yet, and its only job at
            // this point is to answer what the aggregate should be called.
            $company = new Company;
            $company->fill($companyAttributes);

            $party = new Party;

            // Not fillable, by design: Office ownership is a security boundary
            // and party_type is immutable, so neither may arrive through fill().
            $party->office_id = $officeId;
            $party->party_type = PartyType::COMPANY;
            $party->created_by = $actor->getKey();
            $party->updated_by = $actor->getKey();

            $party->fill($partyAttributes);
            $party->display_name = $company->preferredDisplayName();
            $party->save();

            $company->party_id = $party->getKey();
            $company->save();

            return $company;
        });
    }
}
