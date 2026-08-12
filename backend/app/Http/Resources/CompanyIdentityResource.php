<?php

namespace App\Http\Resources;

use App\Domains\Party\MaskedIdentifier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Company sensitive identity surface — tier 1 of D-082.
 *
 * Opening this surface is **not** seeing the value. `parties.identity.view` gets
 * here; the tax identifier is still masked, and revealing it requires
 * `parties.identity.npwp.view_full` through a separate explicit operation.
 *
 * One field rather than the Individual surface's two, and that asymmetry is the
 * schema's rather than the design's: a Company has one sensitive identifier. The
 * permission is the same canonical NPWP code an Individual's uses, because the
 * identity surface belongs to the aggregate and a corporate tax identifier is no
 * less sensitive for belonging to an organization. No `companies.identity.*`
 * family exists.
 *
 * `can_reveal_tax_id` reports the caller's effective authorization so the
 * interface knows whether to offer a reveal control. It is presentation metadata
 * — the reveal endpoint authorizes independently, and a client that lies to
 * itself about this flag gains nothing.
 *
 * @mixin Company
 */
class CompanyIdentityResource extends JsonResource
{
    public function __construct(
        Company $resource,
        private readonly bool $canRevealTaxId = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->party_id,

            'tax_id_masked' => MaskedIdentifier::mask($this->tax_id),
            'has_tax_id' => $this->tax_id !== null,

            'can_reveal_tax_id' => $this->canRevealTaxId,
        ];
    }
}
