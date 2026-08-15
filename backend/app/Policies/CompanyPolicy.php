<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\PartyVisibility;
use App\Models\Company;
use App\Models\User;

/**
 * Who may work with Company records.
 *
 * The same architecture as {@see IndividualPolicy}, with two differences worth
 * naming.
 *
 * **Lifecycle uses `companies.*`, not `parties.*`.** Creating a Company writes a
 * Party row inside its transaction, but that is persistence composition, not an
 * authorization fact — requiring the user to hold two permissions because of it
 * would leak the schema into the permission model (D-078). One ordinary mutation,
 * one permission.
 *
 * **Sensitive identity still uses `parties.identity.*`.** The Company tax
 * identifier is the NPWP, and it answers to `parties.identity.npwp.view_full` —
 * the same canonical code an Individual's does, because the identity surface
 * belongs to the aggregate. No `companies.identity.*` family exists, and M2.1
 * invents none (D-082).
 *
 * Relationship abilities distinguish management from ownership (D-083). They were
 * written at M2.1 ahead of any HTTP surface, so that M2.4 would inherit the
 * boundary rather than invent one. **M2.3 and M2.4 built those surfaces**, and
 * every ability here is now reachable — lifecycle through
 * {@see CompanyController}, identity through {@see CompanyIdentityController},
 * and relationships through the two relationship controllers. The note that
 * nothing here was reachable was true when written and stopped being true two
 * milestones later (corrected at M2.6, D-077).
 */
class CompanyPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope($this->resolver->resolve($actor, 'companies.view'));
    }

    public function view(User $actor, Company $company): bool
    {
        return $this->reaches($actor, 'companies.view', $company);
    }

    public function create(User $actor, ?string $officeId = null): bool
    {
        $access = $this->resolver->resolve($actor, 'companies.create');

        if ($officeId === null) {
            return $this->visibility->hasUsableScope($access);
        }

        return $this->visibility->permitsCreationIn($actor, $access, $officeId);
    }

    public function update(User $actor, Company $company): bool
    {
        return $this->reaches($actor, 'companies.update', $company);
    }

    public function archive(User $actor, Company $company): bool
    {
        return $this->reaches($actor, 'companies.archive', $company);
    }

    /*
    |--------------------------------------------------------------------------
    | Sensitive identity — D-082
    |--------------------------------------------------------------------------
    */

    public function viewIdentity(User $actor, Company $company): bool
    {
        return $this->reaches($actor, 'parties.identity.view', $company);
    }

    public function updateIdentity(User $actor, Company $company): bool
    {
        return $this->reaches($actor, 'parties.identity.update', $company);
    }

    /**
     * Reveal the raw Company tax identifier.
     *
     * `companies.view` grants no sight of this, and neither does opening the
     * identity surface — only the canonical NPWP full-view permission does.
     */
    public function viewFullTaxId(User $actor, Company $company): bool
    {
        return $this->viewIdentity($actor, $company)
            && $this->reaches($actor, 'parties.identity.npwp.view_full', $company);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships — D-083. Behaviour belongs to M2.4.
    |--------------------------------------------------------------------------
    */

    public function viewRelationships(User $actor, Company $company, CompanyRelationshipCategory $category): bool
    {
        return $this->reaches($actor, $category->viewPermission(), $company);
    }

    public function updateRelationships(User $actor, Company $company, CompanyRelationshipCategory $category): bool
    {
        return $this->reaches($actor, $category->updatePermission(), $company);
    }

    private function reaches(User $actor, string $permission, Company $company): bool
    {
        $party = $company->party;

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
