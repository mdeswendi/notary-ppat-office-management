<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for correcting or closing one link in a chain of title (M7.3, D-121).
 *
 * **This is also how a link is ended**, because there is no delete route: stamping
 * `effective_until` and clearing `is_current` is what a chain of title does when land
 * changes hands. `property_owners` carries no `deleted_at` in the ERD, so a `DELETE`
 * could only be a hard one, and hard-deleting a link destroys the history the table
 * exists to keep (`CLAUDE.md` sections 30 and 63).
 *
 * **`party_id` and `property_id` are prohibited, not merely absent.** A different party
 * is a different link, and a different property is a different chain — correcting who
 * owned what by editing a row is precisely the overwrite section 63 forbids.
 * `PropertyOwner::booted()` refuses all three regardless; the rules here turn a
 * `RuntimeException` into a 422 that names the field.
 *
 * `after_or_equal` matches the model guard and the PostgreSQL CHECK: a period runs
 * forwards. Sending `effective_until` without `is_current` clears the flag in the
 * Action, so the pair cannot be saved in contradiction.
 */
class UpdatePropertyOwnerRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'property_id',
        'office_id',

        // A different owner is a different link (M7.1, D-121).
        'party_id',

        // The transfer that produced a link is a fact about when it was created.
        // Re-attributing it later would rewrite provenance rather than correct it.
        'source_matter_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'ownership_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_until' => ['nullable', 'date'],
            'is_current' => ['nullable', 'boolean'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function ownerAttributes(): array
    {
        return collect($this->validated())
            ->only(['ownership_percentage', 'effective_from', 'effective_until', 'is_current'])
            ->all();
    }
}
