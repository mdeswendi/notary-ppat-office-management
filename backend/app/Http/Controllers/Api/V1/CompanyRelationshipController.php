<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\Actions\AddCompanyRelationship;
use App\Domains\Party\Actions\EndCompanyRelationship;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\EndCompanyRelationshipRequest;
use App\Models\Company;
use App\Models\CompanyPerson;
use App\Models\Individual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared behaviour for the two company-relationship surfaces (M2.4).
 *
 * **The category is a property of the subclass, never a request parameter.**
 * That is the whole reason this is abstract rather than one controller taking a
 * category argument: management and ownership answer to different permissions,
 * and a category that could arrive from outside is a category that could arrive
 * wrong. The route points at a class; the class knows what it is.
 *
 * The mutation surface is deliberately **add and end, and nothing else**. There
 * is no `DELETE` and no generic `PATCH`: `company_people` is history, and "who
 * was the director in March" must stay answerable because deeds executed in
 * March depend on it (D-083). Superseding a relationship is end-then-add — two
 * rows, both readable — rather than an overwrite of one.
 *
 * Nothing here counts, totals, or infers anything. No director cap, no required
 * commissioner, no 100% rule, no beneficial ownership derived from a percentage.
 * The software records what the office knows.
 */
abstract class CompanyRelationshipController extends Controller
{
    /**
     * Which authorization surface this controller serves.
     */
    abstract protected function category(): CompanyRelationshipCategory;

    /**
     * The Resource this surface serializes with.
     *
     * @return class-string<JsonResource>
     */
    abstract protected function resourceClass(): string;

    /**
     * The relationship history for this Company and category.
     *
     * **History, not a current-state list.** Ended relationships stay and are
     * returned, ordered so the live ones lead — an office asking "who is the
     * director" and an office asking "who was the director in March" are served
     * by the same endpoint, because the second question is the one legal records
     * depend on.
     */
    public function index(Company $company): AnonymousResourceCollection
    {
        $this->authorize('viewRelationships', [$company, $this->category()]);

        $relationships = $this->scoped($company)
            ->with(['individual.party' => fn ($query) => $query->withTrashed()])
            // Current first, then most recently ended, then most recently
            // recorded — so a reader sees the standing arrangement without
            // losing the history beneath it.
            ->orderByRaw('effective_until IS NULL DESC')
            ->orderByDesc('effective_until')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();

        return $this->resourceClass()::collection($relationships);
    }

    /**
     * Record a new relationship.
     *
     * Authorized by this category's **update** permission alone. Deliberately
     * not also `companies.update`: `company_people` belongs to the Company, but
     * the relationship capability is the authority over it, and requiring the
     * lifecycle permission too would mean anyone maintaining directors could
     * also rename the company. Deliberately not `parties.update` either — the
     * operation reads the Individual and mutates nothing about it.
     */
    protected function storeRelationship(
        Request $request,
        Company $company,
        array $attributes,
        string $individualId,
        AddCompanyRelationship $add,
    ): JsonResponse {
        $this->authorize('updateRelationships', [$company, $this->category()]);

        $individual = $this->resolveCandidate($company, $individualId);

        $relationship = $add->handle(
            $request->user(),
            $company,
            $individual,
            $this->category(),
            $attributes,
        );

        $relationship->load(['individual.party' => fn ($query) => $query->withTrashed()]);

        return $this->resourceClass()::make($relationship)->response()->setStatusCode(201);
    }

    /**
     * Close a relationship.
     *
     * Not a deletion and not an edit of what the relationship was — see
     * {@see EndCompanyRelationship}.
     */
    public function end(
        EndCompanyRelationshipRequest $request,
        Company $company,
        string $relationship,
        EndCompanyRelationship $endRelationship,
    ): JsonResource {
        $this->authorize('updateRelationships', [$company, $this->category()]);

        $target = $this->resolveRelationship($company, $relationship);

        // A second end is not idempotent housekeeping — it asks to change a
        // recorded end date, which is an amendment, and M2.4 builds no amendment
        // workflow. Answering 204 would quietly discard that intent.
        if ($target->effective_until !== null) {
            throw new ConflictHttpException('This relationship has already ended.');
        }

        $ended = $endRelationship->handle($request->user(), $target, $request->validated('effective_until'));

        $ended->load(['individual.party' => fn ($query) => $query->withTrashed()]);

        return $this->resourceClass()::make($ended);
    }

    /**
     * Candidate Individuals and the relationship types this surface accepts.
     *
     * **Narrow on purpose.** The relationship update permission authorizes this,
     * not `parties.view` — somebody who maintains a company's directors needs to
     * pick a person, and requiring the whole Party directory capability for that
     * would grant far more than the task needs. The price of that narrower
     * requirement is that the payload must be correspondingly narrow: an
     * identifier and a display name, and nothing else. No identity, no masks, no
     * contact details, no other companies the person is involved with.
     *
     * Candidates are same-Office as the parent Company and **not archived** —
     * the first because a cross-office relationship is unrepresentable anyway
     * (D-080) and offering one would be an existence oracle for another Office's
     * directory, the second because a retired record should not be picked for a
     * new arrangement. Existing history involving an archived person is
     * untouched by this.
     */
    public function options(Request $request, Company $company): JsonResponse
    {
        $this->authorize('updateRelationships', [$company, $this->category()]);

        $officeId = $company->party->office_id;

        $candidates = Individual::query()
            ->whereHas('party', fn ($query) => $query->where('office_id', $officeId))
            ->with(['party' => fn ($query) => $query->select('id', 'display_name')]);

        if ($search = trim((string) $request->query('search', ''))) {
            $candidates->where(function ($inner) use ($search): void {
                $inner->whereLike('full_name', "%{$search}%")
                    ->orWhereHas('party', fn ($party) => $party->whereLike('display_name', "%{$search}%"));
            });
        }

        $individuals = $candidates->orderBy('full_name')->limit(50)->get()
            ->map(fn (Individual $individual): array => [
                'id' => $individual->party_id,
                'display_name' => $individual->party?->display_name,
            ])->all();

        return response()->json([
            'data' => [
                'individuals' => $individuals,
                'relationship_types' => $this->categoryTypeCodes(),
            ],
        ]);
    }

    /**
     * Resolve a candidate Individual, or refuse without saying why.
     *
     * A cross-Office or archived candidate answers **422 with a generic
     * message**. Distinguishing "that person is in another Office" from "no such
     * person" would answer a question the caller has no permission to ask — the
     * candidate list is same-Office precisely so it cannot be used to probe
     * another Office's directory.
     *
     * The database would refuse a cross-office row anyway through the composite
     * foreign keys (D-080). This check exists so an ordinary mistake surfaces as
     * a validation error rather than a 500.
     */
    private function resolveCandidate(Company $company, string $individualId): Individual
    {
        $individual = Individual::query()
            ->whereKey($individualId)
            ->whereHas('party', fn ($query) => $query->where('office_id', $company->party->office_id))
            ->first();

        if ($individual === null) {
            abort(422, 'Select a person from this office.');
        }

        return $individual;
    }

    /**
     * Resolve a relationship that genuinely belongs to this Company **and** this
     * category, or 404.
     *
     * Both constraints are in the query rather than checked afterwards, and both
     * answer 404 rather than 403. A relationship id from another Company, or a
     * shareholder id used on the management surface, must not be distinguishable
     * from one that does not exist — a 403 would confirm the record is real and
     * say which category it belongs to, which is exactly what the permission
     * split exists to withhold.
     */
    private function resolveRelationship(Company $company, string $relationshipId): CompanyPerson
    {
        $relationship = $this->scoped($company)->whereKey($relationshipId)->first();

        if ($relationship === null) {
            throw (new ModelNotFoundException)->setModel(CompanyPerson::class, [$relationshipId]);
        }

        return $relationship;
    }

    /**
     * @return Builder<CompanyPerson>
     */
    private function scoped(Company $company): Builder
    {
        return CompanyPerson::query()
            ->where('company_party_id', $company->party_id)
            ->whereIn('relationship_type', $this->categoryTypeCodes());
    }

    /**
     * The stable codes belonging to this surface's category.
     *
     * Derived from the enum's own mapping, so the surfaces cannot drift from
     * `CompanyRelationshipType::category()`.
     *
     * @return array<int, string>
     */
    protected function categoryTypeCodes(): array
    {
        return array_values(array_map(
            fn (CompanyRelationshipType $type): string => $type->value,
            array_filter(
                CompanyRelationshipType::cases(),
                fn (CompanyRelationshipType $type): bool => $type->category() === $this->category(),
            ),
        ));
    }
}
