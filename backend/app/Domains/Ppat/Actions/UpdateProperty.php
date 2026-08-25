<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Property;
use App\Models\User;
use App\Policies\PropertyPolicy;

/**
 * Correct a Property's own fields (M7.3, D-121).
 *
 * **Reaches only what `Property` marks fillable** — type, right type, certificate,
 * areas, measurement letter, address and coordinates. Four things are deliberately
 * outside that set and each is refused by the model itself rather than filtered here:
 *
 * ```text
 * office_id         the security boundary; immutable (M7.1)
 * property_number   a reference belongs to the record that received it (D-103)
 * status            no canonical vocabulary; nothing writes it
 * deleted_at        archiving is its own capability — see ArchiveProperty
 * ```
 *
 * **This never touches the chain of title.** Ownership answers to
 * `properties.ownership.update`, a separate canonical code, so correcting an address
 * cannot rewrite who owns the land — the split the catalogue drew and
 * {@see PropertyPolicy} honours.
 *
 * **An archived Property is not editable**, and the Policy says so before this runs
 * (403, not 422): archived-ness is a property of the record, the way
 * `CLAUDE.md` section 29 makes read-only a property of a finalized deed.
 */
class UpdateProperty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, Property $property, array $attributes): Property
    {
        $property->fill($attributes);

        $property->updated_by = $actor->getKey();

        $property->save();

        return $property;
    }
}
