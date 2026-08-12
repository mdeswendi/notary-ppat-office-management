<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Domains\Party\PartyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\IndividualCompanyResource;
use App\Models\CompanyPerson;
use App\Models\Individual;
use App\Policies\CompanyPolicy;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The companies a person is involved with — the reverse of M2.4's Company
 * relationship surfaces (M2.5).
 *
 * M2.4 deliberately deferred this: the relation was reachable, but building the
 * reverse view because a foreign key exists would have been scope on the
 * strength of a database edge. It lands here, in the integration milestone, and
 * it is **read-only**. Relationship management stays on the Company, where
 * D-085's add-and-close model lives; nothing here creates, ends, or alters a
 * row.
 *
 * **The permission split survives the reversal.** Two endpoints, not one:
 * management answers to `companies.management.view` and ownership to
 * `companies.shareholders.view`, exactly as they do from the Company side. A
 * single combined endpoint would have had to pick one permission and would have
 * leaked the other category to whoever held it (D-083).
 *
 * **Two authorization questions, both asked.** Whether the actor may see this
 * *person* — otherwise the endpoint is a way to confirm an Individual exists in
 * an Office the caller cannot reach — and whether they may see this *category*
 * of relationship. Neither implies the other.
 *
 * Companies the actor may not open are still named, because the person's history
 * is about them and hiding it would misrepresent the record. What the actor
 * cannot do is navigate: `can_view_company` is computed from the real Company
 * policy, scope included, so the interface links only where the backend would
 * actually answer.
 */
class IndividualCompanyController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
        private readonly CompanyPolicy $companyPolicy,
    ) {}

    public function management(Individual $individual): AnonymousResourceCollection
    {
        return $this->relationships($individual, CompanyRelationshipCategory::MANAGEMENT);
    }

    public function shareholders(Individual $individual): AnonymousResourceCollection
    {
        return $this->relationships($individual, CompanyRelationshipCategory::OWNERSHIP);
    }

    private function relationships(
        Individual $individual,
        CompanyRelationshipCategory $category,
    ): AnonymousResourceCollection {
        // The person first: this must not become a way to probe for Individuals
        // in an Office the caller cannot see.
        $this->authorize('view', $individual);

        $actor = request()->user();

        // Then the category. Authorized deployment-wide rather than against a
        // particular Company, because the answer spans whatever companies this
        // person is involved with — including ones the actor may not open.
        $access = $this->resolver->resolve($actor, $category->viewPermission());

        abort_unless(
            $this->visibility->hasUsableScope($access),
            403,
            'You may not view that category of company relationship.',
        );

        $relationships = CompanyPerson::query()
            ->where('individual_party_id', $individual->party_id)
            ->whereIn('relationship_type', $this->categoryTypeCodes($category))
            ->with(['company.party' => fn ($query) => $query->withTrashed()])
            // History, current first — the same ordering the Company side uses.
            ->orderByRaw('effective_until IS NULL DESC')
            ->orderByDesc('effective_until')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();

        return IndividualCompanyResource::collection(
            $relationships->map(function (CompanyPerson $relationship) use ($actor): CompanyPerson {
                $company = $relationship->company;

                // Real policy evaluation, scope included — never "does the actor
                // hold companies.view somewhere". A record in another Office is
                // named but not linkable.
                $relationship->setAttribute(
                    'can_view_company',
                    $company !== null && $this->companyPolicy->view($actor, $company),
                );

                return $relationship;
            })
        );
    }

    /**
     * @return array<int, string>
     */
    private function categoryTypeCodes(CompanyRelationshipCategory $category): array
    {
        return array_values(array_map(
            fn (CompanyRelationshipType $type): string => $type->value,
            array_filter(
                CompanyRelationshipType::cases(),
                fn (CompanyRelationshipType $type): bool => $type->category() === $category,
            ),
        ));
    }
}
