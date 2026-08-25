<?php

namespace App\Http\Resources;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One land object, as the list and detail endpoints return it (M7.3, D-121).
 *
 * ## `current_owners` is plural, and that is not a style choice
 *
 * The M7.3 brief specified `current_owner: PropertyOwnerSummary | null`. The M7 lock
 * section 7.2 rules a singular field out by name:
 *
 * > *"a Property legitimately has **several** current owners at once, each with an
 * > `ownership_percentage`."*
 *
 * `Property::currentOwners()` is deliberately plural for the same reason, and its
 * docblock says a singular relation *"would misrepresent a jointly-held parcel"*.
 * Co-ownership is ordinary for Indonesian land, so a singular field would silently
 * show one of two co-owners and drop the other — a wrong answer, not a simpler one.
 *
 * `current_ownership_total` is the arithmetic sum of the current shares, exposed so the
 * interface can display it. **It is not validated against 100 anywhere**: whether
 * shares must total 100 is a rule about Indonesian co-ownership and `CLAUDE.md`
 * section 62 forbids inventing it. The number is shown; no judgement is attached.
 *
 * ## What is absent, and why
 *
 * **No `document_count` and no attached documents.** `property_documents` **does not
 * exist** — `DocumentRelationType` carries `party`, `project` and `matter` only, and
 * names `property_documents` as *"blocked — batch 8, M7"*. Building it is *"adding a
 * case and a migration"*, and M7.3 was scoped without a migration. A count of zero
 * would be a lie about a junction that has no rows because it has no table (O-046).
 *
 * **No ownership history array in the list payload.** The chain of title answers to
 * `properties.ownership.view`, its own canonical capability, so embedding it here
 * would make `properties.view` a way to read who owns the office's land. The detail
 * page asks `GET /properties/{property}/owners` separately, and a caller holding one
 * capability and not the other sees the parcel and not its title.
 *
 * `current_owners` is the deliberate exception, and it is gated the same way: it is
 * present **only** when the caller holds `properties.ownership.view` for this record,
 * and absent otherwise. A property list with no owner column is close to unusable, and
 * the gate is what keeps that convenience from becoming an escalation.
 *
 * **No `status`.** `properties.status` has no vocabulary in the ERD and nothing writes
 * it (D-121 section 12). Emitting a permanently-null key would invite an interface to
 * render a lifecycle the product does not have. **Archived-ness is `is_archived`**,
 * computed from `deleted_at`, which is structural rather than invented vocabulary.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, with
 * record state folded in, so no control is offered that the endpoint would refuse.
 * They are not an authorization surface: every endpoint authorizes again (D-113).
 *
 * @mixin Property
 */
class PropertyResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        Property $resource,
        private readonly array $capabilities = [],
        private readonly bool $showsOwnership = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_number' => $this->property_number,
            'property_type' => $this->property_type->value,

            // Open vocabulary, rendered verbatim. No catalogue backs it and none is
            // invented (`CLAUDE.md` section 9).
            'right_type' => $this->right_type,

            // The legal identifier. Deliberately not unique — two offices may hold
            // records of the same certificate, and a certificate may be reissued.
            'certificate_number' => $this->certificate_number,
            'certificate_date' => $this->certificate_date?->toDateString(),

            'land_area' => $this->land_area === null ? null : (float) $this->land_area,
            'building_area' => $this->building_area === null ? null : (float) $this->building_area,

            'measurement_letter_number' => $this->measurement_letter_number,
            'measurement_letter_date' => $this->measurement_letter_date?->toDateString(),

            'address' => $this->address,
            'village' => $this->village,
            'district' => $this->district,
            'city' => $this->city,
            'province' => $this->province,
            'postal_code' => $this->postal_code,

            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,

            // Structural, not vocabulary. `properties.archive` writes `deleted_at`;
            // `status` stays null because the ERD gives it no values.
            'is_archived' => $this->deleted_at !== null,
            'archived_at' => $this->deleted_at?->toIso8601String(),

            'office' => $this->whenLoaded('office', fn (): array => [
                'id' => $this->office->id,
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),

            // Plural. See the class docblock — and present only for a caller who
            // holds `properties.ownership.view` on this record.
            'current_owners' => $this->showsOwnership ? $this->currentOwnerStubs() : null,
            'current_ownership_total' => $this->showsOwnership ? $this->currentTotal() : null,

            // How much work names this parcel. A count, not the Matters themselves:
            // which of them a caller may see answers to `ppat.matters.view` or
            // `notary.matters.view` and their own Data Scope (D-101), so the list
            // lives on the Matter surface and this says only "some".
            'matter_count' => $this->whenCounted('matters', fn (): int => (int) $this->matters_count),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->userStub('createdBy'),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->userStub('updatedBy'),

            ...$this->capabilities,
        ];
    }

    /**
     * Who owns it now, possibly several at once.
     *
     * A stub per holder — a name and a share, never Party identity (D-082).
     *
     * @return array<int, array<string, mixed>>
     */
    private function currentOwnerStubs(): array
    {
        if (! $this->resource->relationLoaded('currentOwners')) {
            return [];
        }

        return $this->resource->currentOwners
            ->map(fn ($owner): array => [
                'id' => $owner->getKey(),
                'party_id' => $owner->party_id,
                'display_name' => $owner->party?->display_name,
                'ownership_percentage' => $owner->ownership_percentage === null
                    ? null
                    : (float) $owner->ownership_percentage,
                'effective_from' => $owner->effective_from?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * The arithmetic sum of the current shares.
     *
     * Null when the relation is not loaded. **Not validated against 100** — see the
     * class docblock.
     */
    private function currentTotal(): ?float
    {
        if (! $this->resource->relationLoaded('currentOwners')) {
            return null;
        }

        return (float) $this->resource->currentOwners
            ->sum(fn ($owner): float => (float) ($owner->ownership_percentage ?? 0));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function userStub(string $relation): ?array
    {
        $user = $this->whenLoaded($relation);

        if (! $user instanceof User) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }
}
