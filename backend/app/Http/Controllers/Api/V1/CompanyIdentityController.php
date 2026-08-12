<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\Actions\UpdateCompanyIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\UpdateCompanyIdentityRequest;
use App\Http\Resources\CompanyIdentityResource;
use App\Models\Company;
use App\Policies\CompanyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The sensitive tax identity surface for a Company — D-082 in practice.
 *
 * The same three properties hold here as on the Individual surface, and the
 * tests assert each:
 *
 * 1. **Opening the surface reveals nothing.** `parties.identity.view` returns a
 *    masked value. Access to the surface is not access to the value, and
 *    `companies.view` does not even reach the surface.
 * 2. **Reveal answers to the canonical NPWP permission.** The Company tax
 *    identifier *is* the NPWP, so it uses `parties.identity.npwp.view_full` —
 *    the same code an Individual's does. No `companies.identity.*` family was
 *    invented, because the identity surface belongs to the aggregate.
 * 3. **Reveal is an explicit operation, not a fuller read.** A raw value is
 *    returned by a POST the client makes deliberately, so it never becomes part
 *    of a cached GET. `no-store` says so to every layer in between, and the
 *    limiter is the Party identity reveal bucket, deliberately clear of the
 *    `security.*` budgets.
 *
 * The raw value is never logged. The access event is — actor, record, and which
 * field — because "who looked at whose tax identifier" is exactly the question
 * an audit asks, and answering it does not require storing the answer's subject.
 */
class CompanyIdentityController extends Controller
{
    public function __construct(private readonly CompanyPolicy $policy) {}

    /**
     * Tier 1: the identity surface, masked.
     */
    public function show(Request $request, Company $company): CompanyIdentityResource
    {
        $this->authorize('viewIdentity', $company);

        return $this->resource($request, $company);
    }

    /**
     * Tier 1: mutate sensitive tax identity.
     *
     * Answers with the masked resource, never an echo of what was submitted.
     * `parties.identity.update` authorizes writing; it confers no readback of a
     * value the actor may not otherwise see, and echoing the payload would hand
     * one back through the response.
     */
    public function update(
        UpdateCompanyIdentityRequest $request,
        Company $company,
        UpdateCompanyIdentity $update,
    ): CompanyIdentityResource {
        $this->authorize('updateIdentity', $company);

        $updated = $update->handle($request->user(), $company, $request->identityAttributes());

        Log::info('PARTY_IDENTITY_UPDATED', [
            'actor_id' => $request->user()->getKey(),
            'party_id' => $updated->party_id,
            'fields' => array_keys($request->identityAttributes()),
        ]);

        return $this->resource($request, $updated);
    }

    /**
     * Tier 2: reveal the raw tax identifier, and nothing else.
     */
    public function revealTaxId(Request $request, Company $company): JsonResponse
    {
        $this->authorize('viewFullTaxId', $company);

        // Metadata only. The field name, never its content — a raw identifier in
        // a log line is a raw identifier in a backup, a log aggregator, and
        // whatever ships logs off the host.
        Log::info('PARTY_IDENTITY_REVEALED', [
            'actor_id' => $request->user()->getKey(),
            'party_id' => $company->party_id,
            'field' => 'tax_id',
        ]);

        return response()
            ->json([
                'data' => [
                    'field' => 'tax_id',
                    // Null stays null. Fabricating a placeholder would make an
                    // absent identifier indistinguishable from a present one.
                    'value' => $company->tax_id,
                ],
            ])
            // Keeps the value out of shared caches, browser disk cache, and the
            // back/forward cache. The point of an explicit reveal is that the
            // value exists in one response and nowhere else.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    private function resource(Request $request, Company $company): CompanyIdentityResource
    {
        // The policy is consulted directly rather than through the Gate facade.
        // Both reach the same decision, but a bare `Gate::allows(` in first-party
        // code reads like the pattern D-048 bans, and a rule that needs a second
        // look to clear is one somebody will eventually copy wrongly.
        return new CompanyIdentityResource(
            $company,
            $this->policy->viewFullTaxId($request->user(), $company),
        );
    }
}
