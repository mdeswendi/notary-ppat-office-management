<?php

namespace App\Http\Requests\Party;

use App\Domains\Party\Enums\CompanyRelationshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for recording a management relationship.
 *
 * `relationship_type` is restricted to the three management codes. A
 * `SHAREHOLDER` or `BENEFICIAL_OWNER` submitted here is a **422**, not a silent
 * re-categorisation: the two surfaces answer to different permissions, and an
 * endpoint that accepted the other category's types would let
 * `companies.management.update` write ownership data (D-083).
 *
 * `ownership_percentage` is prohibited. That is a surface decision rather than a
 * legal one — the schema would hold the column for any row — but the management
 * surface neither displays nor collects ownership, and accepting a value the
 * interface then never shows would be a field that silently disappears.
 *
 * **No corporate-law rule appears here.** Nothing caps directors, requires a
 * commissioner, forbids one person holding two roles, or attaches meaning to
 * `AUTHORIZED_PERSON`. `position_name` stays optional descriptive metadata and
 * is never the canonical type.
 *
 * `effective_from` is optional and unconstrained relative to any other date:
 * `12_M2_PARTY_ARCHITECTURE.md` section 13 states that no date-transition rules
 * are imposed, so none is invented here either.
 */
class StoreCompanyManagementRequest extends FormRequest
{
    private const FORBIDDEN = [
        'ownership_percentage',
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
                    CompanyRelationshipType::DIRECTOR,
                    CompanyRelationshipType::COMMISSIONER,
                    CompanyRelationshipType::AUTHORIZED_PERSON,
                ]),
            ],
            'position_name' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'relationship_type.Illuminate\Validation\Rules\Enum' => 'This endpoint records management relationships only.',
            'ownership_percentage.prohibited' => 'Ownership is recorded from the shareholders section.',
            'effective_until.prohibited' => 'A relationship is ended from its own action, not at creation.',
        ];
    }

    /**
     * Attributes belonging to the relationship row.
     *
     * The endpoints are deliberately absent: the Company comes from the route
     * and the Individual is resolved and checked separately, so neither reaches
     * the model through mass assignment.
     *
     * @return array<string, mixed>
     */
    public function relationshipAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['relationship_type', 'position_name', 'effective_from']),
        );
    }
}
