<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Models\Office;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record a land object (M7.3, D-121).
 *
 * **Office is the actor's own, never a field.** `PropertyVisibility::permitsCreationIn()`
 * already refused any other destination — `ALL` is reach over records that exist, not
 * authority to decide which Office a new one joins (D-097, D-098, D-107, D-119) — so
 * the value is taken from the actor rather than accepted and re-checked.
 *
 * ## `property_number` is office-supplied, and that is M7.1's open question answered
 *
 * The M7 lock section 15 recorded *"whether `property_number` is allocated or
 * office-supplied"* as a question somebody had to settle explicitly. **M7.3 settles it:
 * the office supplies it.** Three reasons, and none of them is convenience:
 *
 * - `03_DATABASE_ERD.md` gives the column **no format**. D-103's allocator exists
 *   because `PRJ-YYYY-NNNNNN` and `N-YYYY-NNNNNN` are formats a canonical document
 *   states; nothing states one here.
 * - `CLAUDE.md` section 38 shows `PROP-000001` **without a year**, alone among the
 *   internal references it lists, so D-108's Office+year counter does not fit — and a
 *   land parcel is not a yearly thing.
 * - An allocator needs a counter table, which is a migration, and M7.1 built the
 *   schema this milestone was told is complete.
 *
 * So the software validates **uniqueness within the Office** and nothing else: no
 * format, no prefix, no sequence. That is the same shape `ppat.deeds.number` has, for
 * the same reason — the office decides what its own references look like.
 *
 * **`status` is not written.** `properties.status` has no vocabulary in the ERD, and a
 * default of `ACTIVE` would assert a lifecycle nobody defined (D-121 section 12). A
 * new Property has a null status, and archiving does not change that — see
 * {@see ArchiveProperty}.
 *
 * A transaction, because creation stamps attribution alongside the row and
 * `CLAUDE.md` section 37 asks that multi-step writes be atomic even when the step
 * count is currently one.
 */
class CreateProperty
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, array $attributes, ?Office $office = null): Property
    {
        return DB::transaction(function () use ($actor, $attributes, $office): Property {
            $property = new Property;

            $property->fill($attributes);

            $property->office_id = $office?->getKey() ?? $actor->office_id;

            // Not fillable: a reference belongs to the record that received it, and
            // `Property::booted()` refuses every later change (M7.1, D-103).
            $property->property_number = $attributes['property_number'];

            // Attribution must survive the person who typed it (D-050).
            $property->created_by = $actor->getKey();
            $property->updated_by = $actor->getKey();

            $property->save();

            $this->events->created($property, $actor, ActivityType::PROPERTY_CREATED, [
                'reference' => $property->property_number,
            ]);

            return $property;
        });
    }
}
