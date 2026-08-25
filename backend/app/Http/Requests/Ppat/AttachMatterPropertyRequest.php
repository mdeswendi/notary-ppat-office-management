<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for naming a Property as land a Matter concerns (M7.3, D-121).
 *
 * **`role_code` is free text**, because `03_DATABASE_ERD.md` section 16 introduces
 * `TRANSACTION_OBJECT`, `COLLATERAL` and `RELATED_PROPERTY` with the words *"Example
 * role codes"*. M7.1 stored the column CHECK-free for that reason, and a `Rule::in`
 * here would re-impose the closed list the ERD declined to give — the same treatment
 * `right_type` gets, and `matters.role_code` for participation before it (M4.5).
 *
 * The interface suggests the three and accepts anything typed.
 *
 * `office_id` is the Matter's and is never accepted: the composite foreign keys make a
 * cross-Office pair unrepresentable, and a field here would imply a choice that does
 * not exist.
 */
class AttachMatterPropertyRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'matter_id',
        'created_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'property_id' => ['required', 'string', 'size:26'],
            'role_code' => ['nullable', 'string', 'max:50'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function propertyId(): string
    {
        return (string) $this->validated('property_id');
    }

    public function roleCode(): ?string
    {
        $value = $this->validated('role_code');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
