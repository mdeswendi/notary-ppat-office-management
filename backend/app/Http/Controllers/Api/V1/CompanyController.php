<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\Actions\ArchiveCompany;
use App\Domains\Party\Actions\CreateCompany;
use App\Domains\Party\Actions\UpdateCompany;
use App\Domains\Party\Enums\CompanyEntityType;
use App\Domains\Party\PartyVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\StoreCompanyRequest;
use App\Http\Requests\Party\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Office;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Company records.
 *
 * Thin (CLAUDE.md section 35): authorize, take validated input, call an Action,
 * return a Resource. The aggregate rules live in the Actions and the scope rules
 * in {@see PartyVisibility}, where both can be read and tested without HTTP.
 *
 * **Lifecycle authorizes on `companies.*`, never also on `parties.*`.** Creating
 * a Company writes a Party row inside its transaction, but that is persistence
 * composition, not an authorization fact — requiring two permissions because of
 * it would leak the schema into the permission model (D-078). One ordinary
 * mutation, one permission.
 *
 * Deliberately absent: any tax-identity handling, which lives on its own surface
 * under its own permissions; anything touching `company_people`, which is M2.4;
 * and duplicate detection, which is M2.5. Also absent: `DELETE`. Party-domain
 * records are archived, never destroyed (D-081), and no restore exists because
 * no restore permission does.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PartyVisibility $visibility,
    ) {}

    /**
     * Companies the caller may see.
     *
     * Visibility is applied **in the query**, so an office-scoped caller's SQL
     * never selects another Office's rows — the pagination total counts only what
     * they may see, and no filter can widen it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $actor = $request->user();

        $parties = $this->visibility->scope(
            Party::query()->where('party_type', 'COMPANY'),
            $actor,
            $this->resolver->resolve($actor, 'companies.view'),
        );

        $query = Company::query()
            ->with(['party.office'])
            ->whereIn('party_id', $parties->select('parties.id'));

        if ($search = trim((string) $request->query('search', ''))) {
            // Grouped so the search cannot escape the visibility constraint.
            //
            // Ordinary fields only. `tax_id` is excluded because it is encrypted
            // and matching it is impossible without a keyed construction nobody
            // has designed (D-082); `registration_number` is excluded because it
            // is the duplicate signal M2.5 owns, and answering "does this
            // registration number exist" from the directory would make it an
            // existence oracle before the rules governing that are written.
            $query->where(function ($inner) use ($search): void {
                $inner->whereLike('legal_name', "%{$search}%")
                    ->orWhereLike('short_name', "%{$search}%")
                    ->orWhereHas('party', function ($party) use ($search): void {
                        $party->whereLike('display_name', "%{$search}%")
                            ->orWhereLike('primary_phone', "%{$search}%")
                            ->orWhereLike('primary_email', "%{$search}%");
                    });
            });
        }

        // Ordered by the directory name rather than the legal name, because the
        // directory is what the list shows: a company recorded with a short name
        // is displayed by it, and sorting by something else would read as
        // unsorted. A correlated subquery keeps this off the eager loads.
        $query->orderBy(
            Party::query()->select('display_name')->whereColumn('parties.id', 'companies.party_id')
        )->orderBy('party_id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        return CompanyResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(StoreCompanyRequest $request, CreateCompany $create): JsonResponse
    {
        // The destination Office is part of the decision, so it is authorized
        // rather than merely validated: an office-scoped actor may create
        // companies, but only in their own Office.
        $this->authorize('create', [Company::class, $request->validated('office_id')]);

        $company = $create->handle(
            $request->user(),
            $request->validated('office_id'),
            $request->partyAttributes(),
            $request->companyAttributes(),
        );

        return CompanyResource::make($company->load('party.office'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        return CompanyResource::make($company->load('party.office'));
    }

    public function update(
        UpdateCompanyRequest $request,
        Company $company,
        UpdateCompany $update,
    ): CompanyResource {
        $this->authorize('update', $company);

        $updated = $update->handle(
            $request->user(),
            $company,
            $request->partyAttributes(),
            $request->companyAttributes(),
        );

        return CompanyResource::make($updated->load('party.office'));
    }

    /**
     * Archive the aggregate. Not a deletion — see {@see ArchiveCompany}.
     */
    public function archive(Request $request, Company $company, ArchiveCompany $archive): Response
    {
        $this->authorize('archive', $company);

        $archive->handle($request->user(), $company);

        return response()->noContent();
    }

    /**
     * Form metadata for creating a Company.
     *
     * Narrow: the Offices this caller may create in, and the canonical entity
     * type codes. It reads nothing else and writes nothing — this is not an
     * Office API. An office-scoped caller sees only their own Office, so the
     * dropdown cannot offer a destination the Policy would then refuse.
     *
     * Entity types are **codes only**. The interface translates them; the server
     * never sends a display string, because a translated value in the payload is
     * one the database might end up storing (CLAUDE.md section 12).
     *
     * No relationship categories: those belong to M2.4's forms, and returning
     * them here would advertise a capability this milestone does not have.
     */
    public function options(Request $request): JsonResponse
    {
        $this->authorize('create', Company::class);

        $actor = $request->user();
        $access = $this->resolver->resolve($actor, 'companies.create');

        $offices = Office::query()->where('is_active', true);

        // Anything short of ALL is confined to the actor's own Office — the same
        // predicate the Policy applies, so the two cannot disagree.
        if (! $this->visibility->reachesAllOffices($access)) {
            $offices->whereKey($actor->office_id);
        }

        return response()->json([
            'data' => [
                'offices' => $offices->orderBy('name')->get(['id', 'code', 'name'])
                    ->map(fn (Office $office): array => [
                        'id' => $office->id,
                        'code' => $office->code,
                        'name' => $office->name,
                    ])->all(),
                'entity_types' => CompanyEntityType::values(),
            ],
        ]);
    }
}
