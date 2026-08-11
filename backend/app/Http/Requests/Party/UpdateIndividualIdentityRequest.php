<?php

namespace App\Http\Requests\Party;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the sensitive identity surface.
 *
 * Accepts only the two sensitive identifiers, and refuses ordinary profile fields
 * so that the two capabilities stay genuinely separate in both directions:
 * `parties.update` cannot write identity, and `parties.identity.update` cannot
 * write a display name.
 *
 * **No format validation.** M2.0 deferred NIK and NPWP formats pending domain
 * authority, and this is exactly the file where somebody would be tempted to add
 * `digits:16` from memory. Indonesian NPWP formats have changed; a guess encoded
 * here would reject genuine identifiers and be discovered only by the person
 * whose record could not be saved.
 *
 * Only technical bounds apply: a string, within a length the storage can hold.
 * Nothing normalizes the value, because aggressive normalization destroys the
 * original semantic content.
 */
class UpdateIndividualIdentityRequest extends FormRequest
{
    private const FORBIDDEN = [
        'full_name', 'prefix', 'suffix', 'primary_phone', 'primary_email',
        'party_type', 'display_name', 'office_id', 'created_by', 'updated_by',
        'deleted_at', 'id', 'party_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'nik' => ['sometimes', 'nullable', 'string', 'max:100'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:100'],
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
            'full_name.prohibited' => 'Profile details are changed from the profile form.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function identityAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip(['nik', 'npwp']));
    }
}
