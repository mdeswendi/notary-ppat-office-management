<?php

namespace App\Http\Requests\Party;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for updating an Individual's ordinary profile.
 *
 * Every field is `sometimes`, so a partial PATCH is meaningful.
 *
 * Three groups are **refused rather than ignored**, because silently discarding
 * them would let an interface appear to accept a change that never happened:
 *
 * `nik` / `npwp`      — a different capability (`parties.identity.update`).
 *                       Accepting them here would make `parties.update` a
 *                       superset of it (D-082).
 * `office_id`         — Party Office transfer is not designed. It moves a record
 *                       across a security boundary and would strand any company
 *                       relationship pinned to the old Office (D-080). M2.2
 *                       refuses it rather than invent semantics.
 * `party_type` etc.   — immutable or application-owned.
 */
class UpdateIndividualRequest extends FormRequest
{
    private const FORBIDDEN = [
        'party_type',
        'display_name',
        'office_id',
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
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
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
            'nik.prohibited' => 'Identity details are changed from the identity section.',
            'npwp.prohibited' => 'Identity details are changed from the identity section.',
            'office_id.prohibited' => 'Moving a record between offices is not supported.',
            'party_type.prohibited' => 'The record type cannot be changed.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['primary_phone', 'primary_email']));
    }

    /**
     * @return array<string, mixed>
     */
    public function individualAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(['primary_phone', 'primary_email']));
    }
}
