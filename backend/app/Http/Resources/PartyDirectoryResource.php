<?php

namespace App\Http\Resources;

use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the unified Party Directory.
 *
 * **Discovery, not detail.** Enough to recognize a record and navigate to it,
 * and deliberately nothing more: no NIK, no NPWP, no `tax_id`, no mask, no
 * fingerprint, no birth details, no relationships. The directory spans two
 * subtypes and is the widest-reach read surface in the Party domain, which is
 * exactly why it carries the least.
 *
 * The subtype summary is the small handful of ordinary fields that make a row
 * identifiable — a person's full name, an organization's legal and short names
 * and its legal form. Both are already ordinary data on their own detail
 * surfaces.
 *
 * @mixin Party
 */
class PartyDirectoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $party = $this->resource;

        return [
            'id' => $party->getKey(),
            'party_type' => $party->party_type?->value,

            'display_name' => $party->display_name,
            'primary_phone' => $party->primary_phone,
            'primary_email' => $party->primary_email,

            'office' => $party->relationLoaded('office') && $party->office !== null
                ? [
                    'id' => $party->office->id,
                    'code' => $party->office->code,
                    'name' => $party->office->name,
                ]
                : null,

            // Exactly one of these is present, matching `party_type`.
            'individual' => $party->individual === null ? null : [
                'full_name' => $party->individual->full_name,
            ],

            'company' => $party->company === null ? null : [
                'legal_name' => $party->company->legal_name,
                'short_name' => $party->company->short_name,
                'entity_type' => $party->company->entity_type?->value,
            ],

            'created_at' => $party->created_at?->toIso8601String(),
        ];
    }
}
