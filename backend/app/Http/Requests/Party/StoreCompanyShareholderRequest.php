<?php

namespace App\Http\Requests\Party;

use App\Domains\Party\Enums\CompanyRelationshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for recording an ownership relationship.
 *
 * `relationship_type` is restricted to the two ownership codes. A management
 * code submitted here is a **422**, for the mirror of the reason the management
 * surface refuses ownership codes: the categories answer to different
 * permissions, and either surface accepting the other's types would collapse
 * the split (D-083).
 *
 * **`ownership_percentage` is recorded data, and nothing more.** The only bounds
 * are the column's: `decimal(7,4)`, so at most four decimal places and a
 * magnitude the storage can hold. There is deliberately **no cap at 100, no
 * requirement that a company's shareholdings total 100, no minimum, and no
 * threshold above which somebody becomes a beneficial owner** — those are legal
 * rules, and M2 has no authority to invent them (D-083, D-084). `min:0` is the
 * one bound kept, because a negative proportion is not data the field can
 * meaningfully hold rather than a claim about Indonesian company law.
 *
 * `BENEFICIAL_OWNER` is never inferred. It is recorded when somebody records it,
 * and holding `SHAREHOLDER` implies nothing about it.
 *
 * `position_name` is prohibited: a surface decision, not a legal one — the
 * ownership surface neither displays nor collects a position, and accepting a
 * value the interface never shows would be a field that silently disappears.
 */
class StoreCompanyShareholderRequest extends FormRequest
{
    private const FORBIDDEN = [
        'position_name',
        'company_party_id',
        'individual_party_id',
        'office_id',
        'effective_until',
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
            'individual_id' => ['required', 'string', 'ulid'],
            'relationship_type' => [
                'required',
                Rule::enum(CompanyRelationshipType::class)->only([
                    CompanyRelationshipType::SHAREHOLDER,
                    CompanyRelationshipType::BENEFICIAL_OWNER,
                ]),
            ],
            // Storage bounds only: decimal(7,4) holds up to 999.9999.
            'ownership_percentage' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9999', 'decimal:0,4'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
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
            'relationship_type.Illuminate\Validation\Rules\Enum' => 'This endpoint records ownership relationships only.',
            'position_name.prohibited' => 'A position is recorded from the management section.',
            'effective_until.prohibited' => 'A relationship is ended from its own action, not at creation.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function relationshipAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['relationship_type', 'ownership_percentage', 'effective_from']),
        );
    }
}
