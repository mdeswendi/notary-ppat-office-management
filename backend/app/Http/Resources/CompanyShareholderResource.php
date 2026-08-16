<?php

namespace App\Http\Resources;

use App\Models\CompanyPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ownership relationship, as the shareholders surface sees it.
 *
 * The mirror of {@see CompanyManagementResource}, and it carries **no sensitive
 * identity** for the same reason: `companies.shareholders.view` says who may see
 * who owns an organization, and nothing about that person's NIK, NPWP, birth
 * data, or contact details (D-082). No masks either.
 *
 * `position_name` is absent: it belongs to the management surface.
 *
 * `ownership_percentage` is reported exactly as recorded, and **nothing is
 * derived from it**. No total, no remainder, no majority flag, and no inference
 * that a large holding makes somebody a beneficial owner — that determination is
 * a legal one, recorded when an office records it (D-083).
 *
 * @mixin CompanyPerson
 */
class CompanyShareholderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $individual = $this->resource->individual;
        $party = $individual?->party;

        return [
            'id' => $this->id,
            'relationship_type' => $this->relationship_type->value,

            // A string from the decimal cast, or null. Null is not zero: an
            // unrecorded holding and a zero holding are different facts.
            'ownership_percentage' => $this->ownership_percentage,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),

            // Exactly `effective_until IS NULL` — see the management resource.
            'is_current' => $this->effective_until === null,

            'individual' => $individual === null ? null : [
                'id' => $individual->party_id,
                'display_name' => $party?->display_name,
                'is_archived' => $party?->deleted_at !== null,
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
