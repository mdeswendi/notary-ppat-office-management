<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Ppat\Exceptions\PropertyNotEligible;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retire a land object from the office's active reference list (M7.3, D-121).
 *
 * ## Archiving is the soft delete, and that reading is what makes two canonical facts live
 *
 * M7.1 left this open: *"`properties.archive` is the canonical capability; what it
 * does is M7.3's question."* Two canonical facts constrain the answer, and only one
 * reading satisfies both.
 *
 * - `03_DATABASE_ERD.md` section 16 gives `properties` a **`deleted_at`**, unlike
 *   `notary_deeds` and `ppat_deeds`.
 * - The catalogue gives `properties.archive` and **withholds `properties.delete`** —
 *   verified against the registry, the same check that ruled out `ppat.deeds.delete`
 *   at M7.2 (O-039).
 *
 * Read separately, each is a dead thing: a soft-delete column no capability reaches,
 * and a capability with nothing to do. Read together they are one mechanism —
 * **`properties.archive` is the authority to soft-delete a Property**, and the ERD gave
 * the table the column that act needs. A Property is reference data an office may
 * retire, not a finalized legal record it must never touch.
 *
 * ## What this deliberately does not do
 *
 * **It does not write `status`.** `properties.status` has no vocabulary in the ERD, and
 * `ACTIVE`/`ARCHIVED` is a lifecycle nobody defined — the `notary_minuta.release_status`
 * ruling at M6.3 and the M7 lock's own section 12. Archived-ness is *structural*
 * (`deleted_at IS NOT NULL`), which invents nothing, and the column stays null.
 *
 * **It destroys nothing.** Every `matter_properties` row, every link in the chain of
 * title and every Warkah line survives, so a Matter that names an archived parcel
 * still resolves it. `CLAUDE.md` section 63 asks exactly that of history.
 *
 * **It is not reversible through the product.** There is no `properties.restore` in
 * the catalogue — `projects.restore` exists and this has no counterpart — so M7.3
 * ships no un-archive path rather than authorizing one through a code that does not
 * name the act (O-045).
 *
 * ## The one guard, and it is a product rule rather than a legal one
 *
 * Archiving is refused while a Matter that has not finished names this Property.
 * Retiring a parcel that live work depends on would leave that work pointing at a
 * record the office has taken out of use, which is ordinary data hygiene — it asserts
 * nothing about land law, tax, registration or any other thing `CLAUDE.md` section 62
 * protects, and it clears by itself as the Matter completes.
 *
 * **The terminal set lives here rather than on `MatterStatus`**, deliberately. D-102
 * refused a transition matrix on that enum and says so in its docblock; the three
 * statuses below are this action's own reading of *"still running"*, not a claim about
 * which status may follow which. If a second caller ever needs the same set, that is
 * the moment to promote it — not before.
 */
class ArchiveProperty
{
    /**
     * Statuses in which a Matter is no longer running.
     *
     * @return array<int, string>
     */
    private static function settled(): array
    {
        return [
            MatterStatus::COMPLETED->value,
            MatterStatus::CANCELLED->value,
            MatterStatus::ARCHIVED->value,
        ];
    }

    public function handle(User $actor, Property $property): Property
    {
        return DB::transaction(function () use ($actor, $property): Property {
            $running = $property->matters()
                ->whereNotIn('matters.status', self::settled())
                ->count();

            if ($running > 0) {
                throw PropertyNotEligible::becauseMattersAreStillRunning($running);
            }

            // Stamped before the delete so the row records who retired it. `status`
            // is untouched; see the class docblock.
            $property->updated_by = $actor->getKey();
            $property->save();

            $property->delete();

            return $property;
        });
    }
}
