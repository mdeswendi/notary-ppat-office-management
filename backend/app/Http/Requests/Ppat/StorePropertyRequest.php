<?php

namespace App\Http\Requests\Ppat;

use App\Domains\Ppat\Actions\CreateProperty;
use App\Domains\Ppat\Enums\PropertyType;
use App\Policies\PropertyPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for recording a land object (M7.3, D-121).
 *
 * ## What is required, and on whose authority
 *
 * ```text
 * property_number      the office's own reference; unique within the Office
 * property_type        four values, a closed list the ERD gives flat
 * right_type           NOT NULL in the schema; open vocabulary, free text
 * certificate_number   the legal identifier the record exists to hold
 * address              a parcel nobody can locate is not a record of a parcel
 * ```
 *
 * **`property_number` is required here and nullable in the schema**, and the two are
 * not in conflict. M7.1 left the column nullable because no creation path existed to
 * fill it; M7.3 is that path. Nullability stays because it is the ERD's state and
 * because a row imported ahead of its reference must remain representable.
 *
 * Uniqueness is **within the Office** (D-103): an internal reference identifies a
 * record inside its own office and says nothing globally. The rule is scoped to the
 * actor's Office because that is the only Office creation may target — `ALL` is reach
 * over records that exist, never authority to place a new one elsewhere.
 *
 * **No format is validated.** The ERD gives none; `CLAUDE.md` section 38 shows
 * `PROP-000001` as an example internal reference and section 62 forbids inventing
 * numbering rules. The office supplies whatever it uses, the way it supplies a deed
 * number.
 *
 * **`certificate_number` is deliberately not unique.** Two offices may hold records of
 * the same certificate and a certificate may be reissued — M7.1's migration says so,
 * and a unique rule here would refuse a legitimate second record.
 *
 * ## Two vocabularies, treated differently on purpose
 *
 * `property_type` is validated against {@see PropertyType} because the ERD gives four
 * values as a flat closed list. `right_type` is free text with a length limit, because
 * the ERD says *"may use stable machine codes, for example"* — a `Rule::in` on the six
 * examples would assert that Indonesian land law has six kinds of right.
 *
 * ## Refused outright rather than ignored
 *
 * `office_id` is the actor's own and `status` has no canonical vocabulary at all, so
 * neither is accepted. The refusal keys on **presence** (the D-097 pattern): an
 * interface that appears to take `status` and then drops it is worse than one that
 * says no.
 *
 * Whether this actor may create a Property, and in which Office, is
 * {@see PropertyPolicy}'s question. This class validates shape.
 */
class StorePropertyRequest extends FormRequest
{
    /**
     * System-controlled, or vocabulary that does not exist.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',

        // No canonical vocabulary — the ERD names the column and gives it no values,
        // so nothing writes it and nothing may send it (D-121 section 12).
        'status',

        'created_by',
        'updated_by',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $officeId = $this->user()?->office_id;

        $rules = [
            'property_number' => [
                'required',
                'string',
                'max:50',
                // Scoped to the actor's Office, and **not** `withoutTrashed()`: an
                // archived parcel keeps its reference, so its number is not handed
                // back for reuse. The database's own unique index agrees — it does
                // not exclude soft-deleted rows either, so a `withoutTrashed()` rule
                // here would pass validation and then hit a constraint violation.
                Rule::unique('properties', 'property_number')
                    ->where(fn ($query) => $query->where('office_id', $officeId)),
            ],

            'property_type' => ['required', 'string', Rule::in(PropertyType::values())],

            // Open vocabulary. Length only — see the class docblock.
            'right_type' => ['required', 'string', 'max:30'],

            'certificate_number' => ['required', 'string', 'max:100'],
            'certificate_date' => ['nullable', 'date'],

            // Areas are measurements somebody read off a certificate. Non-negative is
            // arithmetic, not a legal rule.
            'land_area' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'building_area' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],

            'measurement_letter_number' => ['nullable', 'string', 'max:100'],
            'measurement_letter_date' => ['nullable', 'date'],

            'address' => ['required', 'string', 'max:2000'],
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
     * Everything the Action writes, the reference included.
     *
     * `property_number` travels in this array although `Property` does not mark it
     * fillable: {@see CreateProperty} assigns it explicitly, because a reference is
     * stamped once and then refused by the model for good (D-103).
     *
     * @return array<string, mixed>
     */
    public function propertyAttributes(): array
    {
        return collect($this->validated())
            ->only([
                'property_number',
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
