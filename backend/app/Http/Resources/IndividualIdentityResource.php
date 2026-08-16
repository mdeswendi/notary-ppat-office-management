<?php

namespace App\Http\Resources;

use App\Domains\Party\MaskedIdentifier;
use App\Models\Individual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The sensitive identity surface — tier 1 of D-082.
 *
 * Opening this surface is **not** seeing the values. `parties.identity.view`
 * gets here; the identifiers are still masked, and revealing either one requires
 * its own field-specific permission through a separate explicit operation.
 *
 * So this resource looks deliberately similar to the masked half of
 * {@see IndividualResource}. That is the design, not duplication: the surface
 * exists so that a reveal has somewhere to belong and so an interface can show
 * what is recorded, not so that reaching it discloses anything.
 *
 * `can_reveal_*` reports the caller's effective authorization so the interface
 * knows whether to offer a reveal control. It is presentation metadata — the
 * reveal endpoint authorizes independently, and a client that lies to itself
 * about these flags gains nothing.
 *
 * @mixin Individual
 */
class IndividualIdentityResource extends JsonResource
{
    public function __construct(
        Individual $resource,
        private readonly bool $canRevealNik = false,
        private readonly bool $canRevealNpwp = false,
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

            'nik_masked' => MaskedIdentifier::mask($this->nik),
            'npwp_masked' => MaskedIdentifier::mask($this->npwp),
            'has_nik' => $this->nik !== null,
            'has_npwp' => $this->npwp !== null,

            'can_reveal_nik' => $this->canRevealNik,
            'can_reveal_npwp' => $this->canRevealNpwp,
        ];
    }
}
