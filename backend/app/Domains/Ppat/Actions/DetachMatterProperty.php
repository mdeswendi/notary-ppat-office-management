<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Matter;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Stop naming a Property as land this Matter concerns (M7.3, D-121).
 *
 * **This deletes the junction row and nothing else** — never the Matter, never the
 * Property, never a link in the chain of title. The same sentence `MatterPartiesSection`
 * carries about participation (M4.5, D-105), and true for the same reason: the row
 * records a relationship the office asserted, and withdrawing the assertion is the
 * whole act.
 *
 * **`matter_properties` has no `deleted_at`** — the ERD gives it `id`, `matter_id`,
 * `property_id`, `role_code` and `created_at`, nothing more — so this is a hard delete
 * and the interface says so rather than implying an undo the product does not have.
 *
 * That is not a loss of history in the sense `CLAUDE.md` section 63 protects: the
 * Property, its chain of title and every Matter still exist. What disappears is one
 * office's statement that this parcel was the object of this piece of work, which is
 * the thing being corrected.
 */
class DetachMatterProperty
{
    public function handle(Matter $matter, Property $property): void
    {
        DB::transaction(function () use ($matter, $property): void {
            $matter->properties()->detach($property->getKey());
        });
    }
}
