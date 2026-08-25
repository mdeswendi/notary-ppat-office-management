<?php

namespace App\Http\Resources;

use App\Models\Matter;
use App\Models\Party;
use App\Models\PropertyOwner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One link in a chain of title (M7.3, D-121).
 *
 * **A closed link is not a deleted one.** Every row the office ever recorded appears
 * here, current or ended, because that is what makes this a chain rather than a
 * current state somebody keeps editing (`CLAUDE.md` section 63). `is_current` and
 * `effective_until` say which is which.
 *
 * **The Party is a stub.** Enough to say who, never a way to read a Party record the
 * caller could not open directly — the `MatterPartyResource` construction (D-105).
 * `can_view_party` is computed from real Party visibility and is presentation only:
 * the Party endpoints authorize again. A Party the actor cannot open **still appears**,
 * because the link is a fact about the land and hiding it would misreport the title to
 * somebody authorized to read it.
 *
 * **No NIK and no NPWP.** Identity stays behind the surfaces that already authorize it
 * (D-082). A chain of title names people; it does not carry their identity documents.
 *
 * **`source_matter` is a stub too**, and null unless the caller may reach that Matter
 * — the transfer that produced a link is recorded, but reading a link is not authority
 * to read the work behind it (D-100).
 *
 * @mixin PropertyOwner
 */
class PropertyOwnerResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        PropertyOwner $resource,
        private readonly bool $canViewParty = false,
        private readonly array $capabilities = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $party = $this->resource->party;
        $sourceMatter = $this->resource->relationLoaded('sourceMatter')
            ? $this->resource->sourceMatter
            : null;

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,

            // Null where the office recorded a name and no figure. No sum across
            // co-owners is asserted — see `AddPropertyOwner`.
            'ownership_percentage' => $this->ownership_percentage === null
                ? null
                : (float) $this->ownership_percentage,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),

            // A flag on many rows, not a pointer to one: co-ownership is ordinary,
            // and several links may be current at once (M7 lock section 7.2).
            'is_current' => (bool) $this->is_current,

            'party' => $party instanceof Party ? [
                'id' => $party->getKey(),
                'display_name' => $party->display_name,
                'party_type' => $party->party_type->value,
                'is_archived' => $party->deleted_at !== null,
                'can_view_party' => $this->canViewParty,
            ] : null,

            'source_matter' => $sourceMatter instanceof Matter ? [
                'id' => $sourceMatter->getKey(),
                'matter_number' => $sourceMatter->matter_number,
                'title' => $sourceMatter->title,
                'domain' => $sourceMatter->domain->value,
            ] : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }
}
