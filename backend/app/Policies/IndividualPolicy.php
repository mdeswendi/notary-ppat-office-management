<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\PartyVisibility;
use App\Models\Individual;
use App\Models\User;

/**
 * Who may work with Individual records.
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * Party is Office-owned, so `OFFICE` is a working predicate here and `ALL` is not
 * required — unlike a deployment-global resource such as a Role definition.
 * {@see PartyVisibility} turns granted scopes into the same condition the list
 * query uses, so a record can never be hidden from a listing yet remain reachable
 * by id.
 *
 * **The identity abilities are the security-critical half of this class.** They
 * implement D-082's two tiers, and the separation is deliberate at every step:
 * opening the identity surface is not seeing the values, seeing one identifier is
 * not seeing the other, and being allowed to write is not being allowed to read
 * back. No lifecycle permission produces a raw identifier by any route.
 *
 * M2.1 exposes no HTTP endpoint. These abilities exist and are tested directly so
 * that M2.2 has only to call them.
 */
class IndividualPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope($this->resolver->resolve($actor, 'parties.view'));
    }

    public function view(User $actor, Individual $individual): bool
    {
        return $this->reaches($actor, 'parties.view', $individual);
    }

    /**
     * Creation is judged against the destination Office, since no record exists
     * yet to judge against.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        $access = $this->resolver->resolve($actor, 'parties.create');

        if ($officeId === null) {
            return $this->visibility->hasUsableScope($access);
        }

        return $this->visibility->permitsCreationIn($actor, $access, $officeId);
    }

    public function update(User $actor, Individual $individual): bool
    {
        return $this->reaches($actor, 'parties.update', $individual);
    }

    public function archive(User $actor, Individual $individual): bool
    {
        return $this->reaches($actor, 'parties.archive', $individual);
    }

    /*
    |--------------------------------------------------------------------------
    | Sensitive identity — D-082
    |--------------------------------------------------------------------------
    */

    /**
     * Tier 1: open the identity surface. Values stay masked.
     *
     * `parties.view` deliberately does not imply this — reading a directory entry
     * is not reading somebody's identity documents.
     */
    public function viewIdentity(User $actor, Individual $individual): bool
    {
        return $this->reaches($actor, 'parties.identity.view', $individual);
    }

    /**
     * Tier 1: mutate sensitive identity.
     *
     * Confers no full readback of anything. Writing a value is not licence to
     * read a different one.
     */
    public function updateIdentity(User $actor, Individual $individual): bool
    {
        return $this->reaches($actor, 'parties.identity.update', $individual);
    }

    /**
     * Tier 2: reveal the raw NIK, and nothing else.
     *
     * Requires the identity surface as well: a permission to see one field of a
     * surface the actor may not open would be incoherent.
     */
    public function viewFullNik(User $actor, Individual $individual): bool
    {
        return $this->viewIdentity($actor, $individual)
            && $this->reaches($actor, 'parties.identity.nik.view_full', $individual);
    }

    /**
     * Tier 2: reveal the raw NPWP, and nothing else. Implies nothing about NIK.
     */
    public function viewFullNpwp(User $actor, Individual $individual): bool
    {
        return $this->viewIdentity($actor, $individual)
            && $this->reaches($actor, 'parties.identity.npwp.view_full', $individual);
    }

    private function reaches(User $actor, string $permission, Individual $individual): bool
    {
        $party = $individual->party;

        if ($party === null) {
            return false;
        }

        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $party,
        );
    }
}
