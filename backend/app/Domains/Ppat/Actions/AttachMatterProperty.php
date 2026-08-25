<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Matter;
use App\Models\Property;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Name a Property as land this Matter concerns (M7.3, D-121).
 *
 * ## Which capability authorizes this, and why it is the Matter's
 *
 * **There is no `properties.matters.*` code and no `*.matters.properties.*` code** —
 * verified against the registry. The junction row is *Matter composition*: it says
 * which land this piece of work is about, the way participation says which people it
 * is about. So the authority is `ppat.matters.update`, the capability that already
 * lets somebody change what a Matter is.
 *
 * That reading widens nothing on its own, but it would if the Property were taken on
 * trust: `ppat.matters.update` would become a way to find out which Properties exist.
 * So the controller resolves the Property through **canonical `properties.view`
 * visibility** first, and an unreachable one is refused with the same field error a
 * nonexistent one gets — the M7.2 `store` construction (D-118's two-question rule).
 *
 * ## `role_code` is free text
 *
 * `03_DATABASE_ERD.md` section 16 says *"Example role codes"* before
 * `TRANSACTION_OBJECT`, `COLLATERAL`, `RELATED_PROPERTY`, so M7.1 stored it as a
 * CHECK-free `VARCHAR` and this accepts anything. The interface suggests the three and
 * accepts the rest, exactly as it does for `right_type`.
 *
 * ## One row per pair
 *
 * `matter_properties` is unique on `(matter_id, property_id)` (M7.1): naming the same
 * Property twice on one Matter says nothing a second time, and `role_code` is what
 * would differ. Re-attaching therefore **updates the role** rather than erroring,
 * which is what an office means when it corrects one.
 *
 * `office_id` is the Matter's, and the composite foreign keys make a cross-Office pair
 * unrepresentable rather than merely validated.
 */
class AttachMatterProperty
{
    public function handle(Matter $matter, Property $property, ?string $roleCode): void
    {
        DB::transaction(function () use ($matter, $property, $roleCode): void {
            $existing = $matter->properties()
                ->wherePivot('property_id', $property->getKey())
                ->exists();

            if ($existing) {
                $matter->properties()->updateExistingPivot($property->getKey(), [
                    'role_code' => $roleCode,
                ]);

                return;
            }

            $matter->properties()->attach($property->getKey(), [
                // The junction carries a ULID primary key and `created_at` only —
                // the ERD names no `updated_at`, so none is written.
                'id' => (string) Str::ulid(),
                'office_id' => $matter->office_id,
                'role_code' => $roleCode,
                'created_at' => Date::now(),
            ]);
        });
    }
}
