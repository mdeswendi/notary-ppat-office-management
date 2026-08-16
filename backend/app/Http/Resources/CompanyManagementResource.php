<?php

namespace App\Http\Resources;

use App\Models\CompanyPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One management relationship, as the management surface sees it.
 *
 * **Carries no sensitive identity of any kind.** `companies.management.view`
 * says who may see who acts for an organization; it says nothing about that
 * person's NIK, NPWP, birth data, or contact details, and a relationship
 * resource that reached into the Individual for them would make the two
 * permissions equivalent by accident (D-082).
 *
 * So the Individual appears as an identifier and a display name, and that is
 * deliberately the whole of it. No masks either — a mask is still a statement
 * about a sensitive value, and this surface has no business making one.
 *
 * `ownership_percentage` is absent: it belongs to the ownership surface, under
 * its own permission.
 *
 * The person's name comes from the Party even when that Party is archived, so a
 * historical relationship stays readable after somebody is retired from the
 * directory. `individual.is_archived` says so plainly rather than leaving a
 * blank the interface would have to guess about.
 *
 * @mixin CompanyPerson
 */
class CompanyManagementResource extends JsonResource
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
            'position_name' => $this->position_name,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),

            // Exactly `effective_until IS NULL`, which is what the schema
            // defines current-ness to be. Never a comparison against today: no
            // canonical document says how a future-dated period should be
            // classified, so none is invented here.
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
