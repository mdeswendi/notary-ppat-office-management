<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\DuplicateCandidateFinder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\CompanyDuplicateCheckRequest;
use App\Http\Requests\Party\IndividualDuplicateCheckRequest;
use App\Models\Company;
use App\Models\Individual;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Advisory duplicate candidates (D-084, D-086).
 *
 * **`POST` for every variant, including the ones that only read.** A duplicate
 * check carries the identifiers being typed, and a `GET` would put a NIK in a
 * URL, a browser history entry, a proxy log, and a cached response — the same
 * reasoning that made identity reveal a `POST` (D-082). Nothing here mutates
 * anything.
 *
 * **Advisory, never blocking.** These endpoints exist beside the create and
 * update surfaces, not inside them: no lifecycle Action refuses because a
 * candidate exists, no `409` is returned, and an alternate client may skip the
 * check entirely. That is intentional — the software surfaces evidence and a
 * person decides (D-084).
 *
 * **Two authorization questions, asked separately.** Whether the actor may do
 * the thing they are checking for (create here, or update this record), and
 * whether they may be *told about* the candidates. The second is the one that
 * matters: a candidate disclosure is a statement about somebody else's record,
 * so it answers to the lifecycle view permission, and a *sensitive* signal
 * answers additionally to that identifier's own full-view permission. Asking for
 * a NIK match without `parties.identity.nik.view_full` is a **403**, not a
 * quietly narrowed result — silently dropping the signal would let a caller
 * infer the answer from its absence.
 */
class PartyDuplicateController extends Controller
{
    public function __construct(private readonly DuplicateCandidateFinder $finder) {}

    /**
     * Candidates for an Individual about to be created in a chosen Office.
     */
    public function individualsForCreate(IndividualDuplicateCheckRequest $request): JsonResponse
    {
        $officeId = $request->validated('office_id');

        $this->authorize('create', [Individual::class, $officeId]);

        return $this->individualCandidates($request, $officeId, null);
    }

    /**
     * Candidates for an Individual being edited. The Office comes from the
     * record, and the record itself is excluded.
     */
    public function individualsForUpdate(
        IndividualDuplicateCheckRequest $request,
        Individual $individual,
    ): JsonResponse {
        $this->authorize('update', $individual);

        return $this->individualCandidates($request, $individual->party->office_id, $individual->party_id);
    }

    public function companiesForCreate(CompanyDuplicateCheckRequest $request): JsonResponse
    {
        $officeId = $request->validated('office_id');

        $this->authorize('create', [Company::class, $officeId]);

        return $this->companyCandidates($request, $officeId, null);
    }

    public function companiesForUpdate(
        CompanyDuplicateCheckRequest $request,
        Company $company,
    ): JsonResponse {
        $this->authorize('update', $company);

        return $this->companyCandidates($request, $company->party->office_id, $company->party_id);
    }

    private function individualCandidates(
        IndividualDuplicateCheckRequest $request,
        string $officeId,
        ?string $excludePartyId,
    ): JsonResponse {
        $actor = $request->user();

        foreach ($request->sensitiveFields() as $field) {
            $permission = $field === 'nik'
                ? 'parties.identity.nik.view_full'
                : 'parties.identity.npwp.view_full';

            abort_unless(
                $this->finder->mayUseSensitiveSignal($actor, $permission, $officeId),
                403,
                'You may not check that identifier for duplicates.',
            );
        }

        return $this->respond(
            $this->finder->forIndividual($actor, $officeId, $request->comparisonAttributes(), $excludePartyId)
        );
    }

    private function companyCandidates(
        CompanyDuplicateCheckRequest $request,
        string $officeId,
        ?string $excludePartyId,
    ): JsonResponse {
        $actor = $request->user();

        // The Company tax identifier is the NPWP — same canonical code (D-082).
        foreach ($request->sensitiveFields() as $field) {
            abort_unless(
                $this->finder->mayUseSensitiveSignal($actor, 'parties.identity.npwp.view_full', $officeId),
                403,
                'You may not check that identifier for duplicates.',
            );
        }

        return $this->respond(
            $this->finder->forCompany($actor, $officeId, $request->comparisonAttributes(), $excludePartyId)
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    private function respond($candidates): JsonResponse
    {
        return response()
            ->json([
                'data' => $candidates->all(),
                // Says plainly what the payload is. A client that treats this as
                // an adjudication is misreading a result the API labels.
                'meta' => ['advisory' => true],
            ])
            // The request body carried identifiers. Nothing about the response
            // should be reusable from a cache.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
