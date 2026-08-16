<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Party\Actions\UpdateIndividualIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Party\UpdateIndividualIdentityRequest;
use App\Http\Resources\IndividualIdentityResource;
use App\Models\Individual;
use App\Policies\IndividualPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The sensitive identity surface for an Individual — D-082 in practice.
 *
 * Three things are true of every response here, and the tests assert each:
 *
 * 1. **Opening the surface reveals nothing.** `parties.identity.view` returns
 *    masked values. Access to the surface is not access to the values.
 * 2. **Reveal is per field.** NIK and NPWP each answer to their own permission,
 *    and neither implies the other. There is no combination of lifecycle
 *    permissions that produces a raw identifier.
 * 3. **Reveal is an explicit operation, not a fuller read.** A raw value is
 *    returned by a POST that the client makes deliberately, so it never becomes
 *    part of a cached GET. `no-store` says so to every layer in between.
 *
 * The raw value is never logged. The access event is — actor, record, and which
 * field — because "who looked at whose NIK" is exactly the question an audit
 * asks, and answering it does not require storing the answer's subject.
 */
class IndividualIdentityController extends Controller
{
    public function __construct(private readonly IndividualPolicy $policy) {}

    /**
     * Tier 1: the identity surface, masked.
     */
    public function show(Request $request, Individual $individual): IndividualIdentityResource
    {
        $this->authorize('viewIdentity', $individual);

        return $this->resource($request, $individual);
    }

    /**
     * Tier 1: mutate sensitive identity.
     *
     * Answers with the masked resource, never an echo of what was submitted.
     * `parties.identity.update` authorizes writing; it confers no readback of a
     * value the actor may not otherwise see, and echoing the payload would hand
     * one back through the response.
     */
    public function update(
        UpdateIndividualIdentityRequest $request,
        Individual $individual,
        UpdateIndividualIdentity $update,
    ): IndividualIdentityResource {
        $this->authorize('updateIdentity', $individual);

        $updated = $update->handle($request->user(), $individual, $request->identityAttributes());

        Log::info('PARTY_IDENTITY_UPDATED', [
            'actor_id' => $request->user()->getKey(),
            'party_id' => $updated->party_id,
            'fields' => array_keys($request->identityAttributes()),
        ]);

        return $this->resource($request, $updated);
    }

    /**
     * Tier 2: reveal the raw NIK, and nothing else.
     */
    public function revealNik(Request $request, Individual $individual): JsonResponse
    {
        $this->authorize('viewFullNik', $individual);

        return $this->reveal($request, $individual, 'nik', $individual->nik);
    }

    /**
     * Tier 2: reveal the raw NPWP, and nothing else.
     */
    public function revealNpwp(Request $request, Individual $individual): JsonResponse
    {
        $this->authorize('viewFullNpwp', $individual);

        return $this->reveal($request, $individual, 'npwp', $individual->npwp);
    }

    /**
     * One reveal shape for both fields, so they cannot drift apart in what they
     * disclose or in how they are cached.
     */
    private function reveal(Request $request, Individual $individual, string $field, ?string $value): JsonResponse
    {
        // Metadata only. The field name, never its content — a raw identifier in
        // a log line is a raw identifier in a backup, a log aggregator, and
        // whatever ships logs off the host.
        Log::info('PARTY_IDENTITY_REVEALED', [
            'actor_id' => $request->user()->getKey(),
            'party_id' => $individual->party_id,
            'field' => $field,
        ]);

        return response()
            ->json([
                'data' => [
                    'field' => $field,
                    // Null stays null. Fabricating a placeholder would make an
                    // absent identifier indistinguishable from a present one.
                    'value' => $value,
                ],
            ])
            // Keeps the value out of shared caches, browser disk cache, and the
            // back/forward cache. The point of an explicit reveal is that the
            // value exists in one response and nowhere else.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    private function resource(Request $request, Individual $individual): IndividualIdentityResource
    {
        $actor = $request->user();

        // The policy is consulted directly rather than through the Gate facade.
        // Both reach the same decision, but a bare `Gate::allows(` in first-party
        // code reads like the pattern D-048 bans, and a rule that needs a second
        // look to clear is one somebody will eventually copy wrongly.
        return new IndividualIdentityResource(
            $individual,
            $this->policy->viewFullNik($actor, $individual),
            $this->policy->viewFullNpwp($actor, $individual),
        );
    }
}
