<?php

namespace App\Http\Requests\Party;

use App\Domains\Party\Enums\CompanyEntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a Company's ordinary profile.
 *
 * Every field is `sometimes`, so a partial PATCH is meaningful. `legal_name` and
 * `entity_type` add `required` on top, which means "if you send it, send a
 * value" — they may be omitted, but they may not be emptied.
 *
 * `short_name` is `nullable` on purpose and that is load-bearing: sending null
 * removes the short name, and the display name falls back to the legal name in
 * the same transaction. Removing a short name is a real thing an office does,
 * and a rule that only allowed setting one would make it impossible.
 *
 * Three groups are **refused rather than ignored**, because silently discarding
 * them would let an interface appear to accept a change that never happened:
 *
 * `tax_id`            — a different capability (`parties.identity.update`).
 *                       Accepting it here would make `companies.update` a
 *                       superset of it (D-082).
 * `office_id`         — Party Office transfer is not designed. It moves a record
 *                       across a security boundary and would strand any company
 *                       relationship pinned to the old Office (D-080). M2.3
 *                       refuses it rather than invent semantics.
 * `party_type` etc.   — immutable or application-owned.
 */
class UpdateCompanyRequest extends FormRequest
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
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entity_type' => ['sometimes', 'required', Rule::enum(CompanyEntityType::class)],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],

            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'primary_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'village' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],

            'tax_id' => ['prohibited'],
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
            'tax_id.prohibited' => 'Tax details are changed from the identity section.',
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
    public function companyAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(['primary_phone', 'primary_email']));
    }
}
