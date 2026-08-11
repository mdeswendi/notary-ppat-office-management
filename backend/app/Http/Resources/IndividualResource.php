<?php

namespace App\Http\Resources;

use App\Domains\Party\MaskedIdentifier;
use App\Models\Individual;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Individual as ordinary list and detail responses see it.
 *
 * **Never carries a raw identifier.** `nik` and `npwp` appear only as
 * server-computed masks, and the attribute list is explicit rather than a model
 * dump — the model also hides them, so two independent defences would both have
 * to fail (D-082).
 *
 * That matters more than it looks. A raw identifier in this resource would reach
 * the list endpoint, the detail page, the query cache, and every log and proxy in
 * between, and no amount of frontend masking would take it back.
 *
 * Office is included because scope-aware interfaces need to show which Office a
 * record belongs to, but only its identifier, code, and name — not the rest of
 * the Office record.
 *
 * @mixin Individual
 */
class IndividualResource extends JsonResource
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

            'full_name' => $this->full_name,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,

            'display_name' => $party?->display_name,
            'primary_phone' => $party?->primary_phone,
            'primary_email' => $party?->primary_email,

            'birth_place' => $this->birth_place,
            'birth_date' => $this->birth_date?->toDateString(),
            'gender' => $this->gender,
            'occupation' => $this->occupation,
            'nationality' => $this->nationality,
            'marital_status' => $this->marital_status,

            'address' => $this->address,
            'village' => $this->village,
            'district' => $this->district,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,

            // Facts about the identifiers, never the identifiers. `has_nik`
            // exists so an interface can say "recorded" without the value.
            'nik_masked' => MaskedIdentifier::mask($this->nik),
            'npwp_masked' => MaskedIdentifier::mask($this->npwp),
            'has_nik' => $this->nik !== null,
            'has_npwp' => $this->npwp !== null,

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
