<?php

namespace App\Http\Requests\Ppat;

use App\Domains\Ppat\Enums\PropertyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for correcting a land object (M7.3, D-121).
 *
 * **Every field is optional and the required ones stay required when sent.** A partial
 * update that clears `address` or `certificate_number` would leave a parcel nobody can
 * identify, so those two and the two vocabularies are `sometimes|required` rather than
 * `nullable` — sending them means meaning them.
 *
 * ## `property_number` is prohibited, not merely ignored
 *
 * *"`property_number` tidak bisa diubah (immutable)"* is the brief's own constraint and
 * it is right, for the reason D-103 gave `matter_number` and D-116 gave
 * `document_number`: **a reference belongs to the record that received it.** Somebody
 * holding a file with `PROP-000014` written on it must find the same parcel a year
 * later.
 *
 * `Property::booted()` enforces this regardless — it throws on any change to a
 * non-null value — so the rule here is not the guard. It exists so the caller gets a
 * 422 naming the field instead of a 500 naming a `RuntimeException`.
 *
 * ## Two things this cannot reach, by construction
 *
 * **`office_id`** is the security boundary; the model refuses it and so does this.
 *
 * **Ownership.** The chain of title answers to `properties.ownership.update`, its own
 * canonical capability, and lives on its own endpoints. `party_id`,
 * `ownership_percentage` and the period fields mean nothing here and are not accepted,
 * so correcting an address can never rewrite who owns the land.
 *
 * **`status` and `deleted_at`** are likewise refused: the first has no vocabulary and
 * the second is `properties.archive`'s to write.
 */
class UpdatePropertyRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',

        // A reference belongs to the record that received it (D-103). See the
        // class docblock.
        'property_number',

        // No canonical vocabulary; archiving is structural and has its own endpoint.
        'status',
        'deleted_at',

        'created_by',
        'updated_by',

        // Ownership is a separate capability on a separate surface.
        'party_id',
        'ownership_percentage',
        'effective_from',
        'effective_until',
        'is_current',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            // `sometimes|required`: absent is fine, blank is not.
            'property_type' => ['sometimes', 'required', 'string', Rule::in(PropertyType::values())],
            'right_type' => ['sometimes', 'required', 'string', 'max:30'],
            'certificate_number' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string', 'max:2000'],

            'certificate_date' => ['nullable', 'date'],
            'land_area' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'building_area' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'measurement_letter_number' => ['nullable', 'string', 'max:100'],
            'measurement_letter_date' => ['nullable', 'date'],
            'village' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function propertyAttributes(): array
    {
        return collect($this->validated())
            ->only([
                'property_type',
                'right_type',
                'certificate_number',
                'certificate_date',
                'land_area',
                'building_area',
                'measurement_letter_number',
                'measurement_letter_date',
                'address',
                'village',
                'district',
                'city',
                'province',
                'postal_code',
                'latitude',
                'longitude',
            ])
            ->all();
    }
}
