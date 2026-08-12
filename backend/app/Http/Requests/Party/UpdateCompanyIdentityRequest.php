<?php

namespace App\Http\Requests\Party;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the Company sensitive identity surface.
 *
 * Accepts only the tax identifier, and refuses ordinary profile fields so that
 * the two capabilities stay genuinely separate in both directions:
 * `companies.update` cannot write tax identity, and `parties.identity.update`
 * cannot write a legal name.
 *
 * **No format validation.** M2.0 deferred NPWP format rules pending domain
 * authority, and this is exactly the file where somebody would be tempted to add
 * one from memory. Indonesian NPWP formats have changed; a guess encoded here
 * would reject genuine identifiers and be discovered only by the person whose
 * record could not be saved. `registration_number` is not here either — it is an
 * ordinary field, and no uniqueness rule is invented for it (D-084).
 *
 * Only technical bounds apply: a string, within a length the storage can hold.
 * Nothing normalizes the value, because aggressive normalization destroys the
 * original semantic content.
 */
class UpdateCompanyIdentityRequest extends FormRequest
{
    private const FORBIDDEN = [
        'legal_name', 'short_name', 'entity_type', 'registration_number',
        'primary_phone', 'primary_email',
        'party_type', 'display_name', 'office_id', 'created_by', 'updated_by',
        'deleted_at', 'id', 'party_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
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
            'legal_name.prohibited' => 'Company details are changed from the profile form.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function identityAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['tax_id']));
    }
}
