<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Ppat\AllocatePropertyReference;
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
 * `property_number` is allocated by the system as `PROP-NNNNNN`, per Office and
 * without an annual reset. It is an internal filing reference and has no legal
 * meaning; `certificate_number` remains the land identifier issued externally.
 *
 * **`status` is not written.** `properties.status` has no vocabulary in the ERD, and a
 * default of `ACTIVE` would assert a lifecycle nobody defined (D-121 section 12). A
 * new Property has a null status, and archiving does not change that — see
 * {@see ArchiveProperty}.
 *
 * A transaction, because creation stamps attribution alongside the row and
 * `AGENTS.md` section 37 asks that multi-step writes be atomic even when the step
 * count is currently one.
 */
class CreateProperty
{
    public function __construct(
        private readonly AllocatePropertyReference $allocator,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, array $attributes, ?Office $office = null): Property
    {
        return DB::transaction(function () use ($actor, $attributes, $office): Property {
            $property = new Property;

            $property->fill($attributes);

            $property->office_id = $office?->getKey() ?? $actor->office_id;

            // Not fillable: the system stamps the Office-scoped reference once,
            // and `Property::booted()` refuses every later change.
            $property->property_number = $this->allocator->forOffice($property->office_id);

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
