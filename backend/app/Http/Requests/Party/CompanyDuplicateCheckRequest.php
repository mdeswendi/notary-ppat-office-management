<?php

namespace App\Http\Requests\Party;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields a Company duplicate check may compare on.
 *
 * The mirror of {@see IndividualDuplicateCheckRequest}: everything optional,
 * no legal format validated, `office_id` required on create and prohibited on
 * update, and `exclude_party_id` refused because the subject is excluded
 * server-side.
 */
class CompanyDuplicateCheckRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $forSubject = $this->route('company') !== null;

        return [
            'office_id' => $forSubject
                ? ['prohibited']
                : ['required', 'string', Rule::exists(Office::class, 'id')->where('is_active', true)],

            'tax_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary_email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:50'],

            'exclude_party_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'office_id.prohibited' => 'The office is taken from the record being edited.',
            'exclude_party_id.prohibited' => 'The current record is excluded automatically.',
        ];
    }

    /**
     * The Company tax identifier is the NPWP, so it answers to the NPWP
     * full-view permission — the same canonical code an Individual's does.
     *
     * @return array<int, string>
     */
    public function sensitiveFields(): array
    {
        return trim((string) $this->input('tax_id', '')) !== '' ? ['tax_id'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function comparisonAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(['office_id']));
    }
}
