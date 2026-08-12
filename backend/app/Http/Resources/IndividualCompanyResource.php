<?php

namespace App\Http\Resources;

use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Models\CompanyPerson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One company a person is involved with, seen from the person's side (M2.5).
 *
 * **No sensitive identity of either party.** Not the person's NIK or NPWP — this
 * is reached from an Individual the caller may already view, and a relationship
 * permission is not an identity permission — and not the company's `tax_id` or
 * its mask, for the same reason on the other side (D-082).
 *
 * Category-appropriate detail only: a management row carries its position, an
 * ownership row carries its percentage, and neither carries the other's field.
 * `ownership_percentage` is reported exactly as recorded, with nothing derived
 * from it.
 *
 * `can_view_company` is set by the controller from the real Company policy,
 * scope included. A company in an Office the caller cannot reach is still named
 * — the person's history is about it — but it is not linkable, and the flag is
 * how the interface knows the difference without guessing from a permission code.
 *
 * @mixin CompanyPerson
 */
class IndividualCompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $company = $this->resource->company;
        $party = $company?->party;
        $isManagement = $this->relationship_type->category() === CompanyRelationshipCategory::MANAGEMENT;

        return array_filter([
            'id' => $this->id,
            'relationship_type' => $this->relationship_type->value,

            'position_name' => $isManagement ? $this->position_name : null,
            'ownership_percentage' => $isManagement ? null : $this->ownership_percentage,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            // Exactly `effective_until IS NULL`, never a comparison with today.
            'is_current' => $this->effective_until === null,

            'company' => $company === null ? null : [
                'id' => $company->party_id,
                'display_name' => $party?->display_name,
                'entity_type' => $company->entity_type?->value,
                'is_archived' => $party?->deleted_at !== null,
                'can_view_company' => (bool) $this->getAttribute('can_view_company'),
            ],
        ], fn (string $key): bool => $key !== ($isManagement ? 'ownership_percentage' : 'position_name'),
            ARRAY_FILTER_USE_KEY);
    }
}
