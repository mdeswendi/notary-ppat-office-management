<?php

namespace App\Domains\Party\Actions;

use App\Domains\Party\Enums\PartyType;
use App\Models\Individual;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a Party aggregate of type INDIVIDUAL.
 *
 * **One transaction, both rows.** M2.0 invariant 5 — "no Party without a
 * subtype" — is the one the database cannot carry (D-078), so it rests entirely
 * here. A Party that outlives a failed subtype insert is a defect state, and the
 * only thing preventing it is that this method rolls back.
 *
 * `party_type` is set from the enum, never from input. The caller chooses fields;
 * it does not choose what kind of aggregate this is, so there is no request shape
 * that could produce a COMPANY through the Individual endpoint.
 *
 * `display_name` is derived from the canonical full name in the same transaction
 * (D-079), so the directory and the detail page can never disagree.
 *
 * The destination Office arrives already authorized — the Policy judged it before
 * this ran. This action does not re-decide authorization; it records the actor.
 */
class CreateIndividual
{
    /**
     * @param  array<string, mixed>  $partyAttributes
     * @param  array<string, mixed>  $individualAttributes
     */
    public function handle(
        User $actor,
        string $officeId,
        array $partyAttributes,
        array $individualAttributes,
    ): Individual {
        return DB::transaction(function () use ($actor, $officeId, $partyAttributes, $individualAttributes): Individual {
            $party = new Party;

            // Not fillable, by design: Office ownership is a security boundary
            // and party_type is immutable, so neither may arrive through fill().
            $party->office_id = $officeId;
            $party->party_type = PartyType::INDIVIDUAL;
            $party->created_by = $actor->getKey();
            $party->updated_by = $actor->getKey();

            $party->fill($partyAttributes);
            $party->display_name = trim((string) $individualAttributes['full_name']);
            $party->save();

            $individual = new Individual;
            $individual->party_id = $party->getKey();
            $individual->fill($individualAttributes);
            $individual->save();

            return $individual;
        });
    }
}
