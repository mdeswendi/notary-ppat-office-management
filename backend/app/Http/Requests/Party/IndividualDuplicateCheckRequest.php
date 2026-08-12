<?php

namespace App\Http\Requests\Party;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields an Individual duplicate check may compare on.
 *
 * Everything is optional: the check runs on whatever the form has so far, which
 * is the point of offering it before submission. Nothing is validated for legal
 * format — this is the same data the create endpoint accepts, and the same
 * refusal to invent NIK or NPWP rules applies (D-082).
 *
 * `office_id` is required on the create variant and **prohibited** on the update
 * variant, where the subject Party decides the Office. Letting a caller name the
 * Office while editing an existing record would be handing them a search box for
 * a different Office (D-084).
 *
 * `exclude_party_id` is prohibited outright. The record being edited is excluded
 * server-side from the route's subject; accepting a client-supplied id would let
 * a caller suppress an inconvenient candidate from somebody else's review.
 */
class IndividualDuplicateCheckRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $forSubject = $this->route('individual') !== null;

        return [
            'office_id' => $forSubject
                ? ['prohibited']
                : ['required', 'string', Rule::exists(Office::class, 'id')->where('is_active', true)],

            'nik' => ['sometimes', 'nullable', 'string', 'max:100'],
            'npwp' => ['sometimes', 'nullable', 'string', 'max:100'],
            'full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
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
     * Whether the caller asked for a signal that discloses sensitive identity.
     *
     * @return array<int, string>
     */
    public function sensitiveFields(): array
    {
        $requested = [];

        foreach (['nik', 'npwp'] as $field) {
            if (trim((string) $this->input($field, '')) !== '') {
                $requested[] = $field;
            }
        }

        return $requested;
    }

    /**
     * @return array<string, mixed>
     */
    public function comparisonAttributes(): array
    {
        return array_diff_key($this->validated(), array_flip(['office_id']));
    }
}
