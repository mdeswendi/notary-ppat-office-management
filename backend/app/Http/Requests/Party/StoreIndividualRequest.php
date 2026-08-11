<?php

namespace App\Http\Requests\Party;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating an Individual.
 *
 * `full_name` is the only required field, and it is required **structurally** —
 * a directory row with no name is unusable. Nothing here is required on legal
 * grounds, and no legal format is validated: M2.0 deferred NIK and NPWP format
 * rules because no canonical document freezes them, and encoding a guess would
 * reject real identifiers (D-082).
 *
 * Validation is therefore technical only: types, storage lengths taken from the
 * schema, email syntax, and foreign-key existence. `office_id` must name a real,
 * active Office; whether *this actor* may create there is a Policy question the
 * controller asks separately, because validation answers "is this coherent" and
 * authorization answers "are you allowed".
 *
 * `party_type` is deliberately absent and prohibited. The endpoint decides what
 * it creates; a caller who could choose would be able to create a Company through
 * the Individual route.
 */
class StoreIndividualRequest extends FormRequest
{
    /**
     * Fields refused outright rather than silently dropped, so an interface
     * cannot appear to accept a change that never happened.
     */
    private const FORBIDDEN = [
        'party_type',
        'display_name',
        'created_by',
        'updated_by',
        'deleted_at',
        'id',
        'party_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'office_id' => [
                'required', 'string',
                Rule::exists(Office::class, 'id')->where('is_active', true),
            ],

            'full_name' => ['required', 'string', 'max:255'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:50'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:50'],

            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'primary_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            'birth_place' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:30'],

            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'village' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Sensitive identity is created through its own surface, under its
            // own permission. Accepting them here would let `parties.create`
            // acquire `parties.identity.update` (D-082).
            'nik' => ['prohibited'],
            'npwp' => ['prohibited'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nik.prohibited' => 'Identity details are set from the identity section, not here.',
            'npwp.prohibited' => 'Identity details are set from the identity section, not here.',
            'party_type.prohibited' => 'This endpoint creates individuals only.',
            'display_name.prohibited' => 'The display name is derived from the full name.',
        ];
    }

    /**
     * Attributes belonging to the Party aggregate root.
     *
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['primary_phone', 'primary_email']));
    }

    /**
     * Attributes belonging to the Individual subtype.
     *
     * @return array<string, mixed>
     */
    public function individualAttributes(): array
    {
        return array_diff_key(
            $this->validated(),
            array_flip(['office_id', 'primary_phone', 'primary_email']),
        );
    }
}
