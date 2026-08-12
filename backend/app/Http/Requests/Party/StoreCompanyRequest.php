<?php

namespace App\Http\Requests\Party;

use App\Domains\Party\Enums\CompanyEntityType;
use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a Company.
 *
 * `legal_name` and `entity_type` are the only required fields, and both are
 * required **structurally**: a directory row with no name is unusable, and an
 * organization with no legal form cannot be displayed correctly. Nothing here is
 * required on legal grounds. In particular there is no rule that a `PT` must have
 * a director, that a `registration_number` must be present or unique, or that a
 * `tax_id` follows any format — those are corporate-law questions this milestone
 * has no authority to answer (D-083, D-084).
 *
 * `entity_type` is validated against the live enum, which holds exactly the seven
 * values `03_DATABASE_ERD.md` names. No eighth value is accepted, and none is
 * added here from general legal knowledge — `OTHER` exists precisely so an
 * unforeseen form is recorded honestly rather than forced into the nearest wrong
 * category.
 *
 * `party_type` is deliberately absent and prohibited. The endpoint decides what it
 * creates; a caller who could choose would be able to create an Individual through
 * the Company route.
 */
class StoreCompanyRequest extends FormRequest
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

            'legal_name' => ['required', 'string', 'max:255'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entity_type' => ['required', Rule::enum(CompanyEntityType::class)],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],

            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'primary_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],

            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'village' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],

            // The Company NPWP is created through its own surface, under its own
            // permission. Accepting it here would let `companies.create` acquire
            // `parties.identity.update` (D-082) — and the create response would
            // then be the first place a raw identifier could escape.
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
            'tax_id.prohibited' => 'Tax details are set from the identity section, not here.',
            'party_type.prohibited' => 'This endpoint creates companies only.',
            'display_name.prohibited' => 'The display name is derived from the company name.',
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
     * Attributes belonging to the Company subtype.
     *
     * @return array<string, mixed>
     */
    public function companyAttributes(): array
    {
        return array_diff_key(
            $this->validated(),
            array_flip(['office_id', 'primary_phone', 'primary_email']),
        );
    }
}
