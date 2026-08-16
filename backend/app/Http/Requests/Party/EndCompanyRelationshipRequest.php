<?php

namespace App\Http\Requests\Party;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for closing a relationship.
 *
 * **`effective_until` is required, never defaulted.** Defaulting it to today
 * would be the application inventing a legal fact about when an appointment
 * ceased, which is exactly the kind of thing M2 has no authority to decide. The
 * person recording it knows the date; the software asks.
 *
 * Nothing else is accepted. Ending a relationship changes when it ended and
 * nothing about what it was — the Company, the Individual, and the type are the
 * historical fact (D-083).
 *
 * No relationship between `effective_until` and `effective_from` is enforced.
 * `12_M2_PARTY_ARCHITECTURE.md` section 13 states that no date-transition rules
 * are imposed, and an `after_or_equal` rule here would be exactly such a rule —
 * plausible, undocumented, and capable of refusing a correction an office
 * legitimately needs to record.
 */
class EndCompanyRelationshipRequest extends FormRequest
{
    private const FORBIDDEN = [
        'relationship_type',
        'position_name',
        'ownership_percentage',
        'effective_from',
        'company_party_id',
        'individual_party_id',
        'individual_id',
        'office_id',
        'created_by',
        'updated_by',
        'id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'effective_until' => ['required', 'date'],
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
            'relationship_type.prohibited' => 'Ending a relationship does not change what it was.',
            'individual_id.prohibited' => 'Ending a relationship does not change who it was.',
        ];
    }
}
