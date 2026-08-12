<?php

namespace App\Http\Resources;

use App\Domains\Party\MaskedIdentifier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Company as ordinary list and detail responses see it.
 *
 * **Never carries a raw identifier.** `tax_id` appears only as a server-computed
 * mask, and the attribute list is explicit rather than a model dump — the model
 * also hides it, so two independent defences would both have to fail (D-082).
 *
 * That matters more than it looks. A raw tax identifier in this resource would
 * reach the list endpoint, the detail page, the query cache, and every log and
 * proxy in between, and no amount of frontend masking would take it back.
 *
 * **No relationship collection.** `Company::people()` exists on the model, and
 * serializing it here merely because it exists would ship half of M2.4 through
 * the back door — directors and shareholders answer to
 * `companies.management.*` and `companies.shareholders.*`, which this endpoint
 * does not check and M2.3 does not implement (D-083).
 *
 * Office is included because scope-aware interfaces need to show which Office a
 * record belongs to, but only its identifier, code, and name.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $party = $this->resource->party;

        return [
            // The Party ULID: one public identifier for the aggregate, not two.
            'id' => $this->party_id,

            'legal_name' => $this->legal_name,
            'short_name' => $this->short_name,
            'entity_type' => $this->entity_type?->value,
            'registration_number' => $this->registration_number,

            'display_name' => $party?->display_name,
            'primary_phone' => $party?->primary_phone,
            'primary_email' => $party?->primary_email,

            'address' => $this->address,
            'village' => $this->village,
            'district' => $this->district,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,

            // Facts about the identifier, never the identifier. `has_tax_id`
            // exists so an interface can say "recorded" without the value.
            'tax_id_masked' => MaskedIdentifier::mask($this->tax_id),
            'has_tax_id' => $this->tax_id !== null,

            'office' => $party?->relationLoaded('office') && $party->office !== null
                ? [
                    'id' => $party->office->id,
                    'code' => $party->office->code,
                    'name' => $party->office->name,
                ]
                : null,

            'created_at' => $party?->created_at?->toIso8601String(),
            'updated_at' => $party?->updated_at?->toIso8601String(),
        ];
    }
}
