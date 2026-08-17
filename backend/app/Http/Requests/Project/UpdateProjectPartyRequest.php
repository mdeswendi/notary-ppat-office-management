<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for correcting a participation (M3.4, D-098).
 *
 * The same three fields the Action may write, and **`party_id` is refused here
 * even though it is required at creation**. That is the difference worth
 * reading: re-pointing a participation at a different Party is not a correction
 * of that participation, it is a different relationship. Accepting it would let
 * one row silently become another while keeping the `created_by` and
 * `created_at` of the first, and it would bypass the candidate authorization the
 * store path performs. Remove and add instead.
 *
 * `role_code` stays an unconstrained bounded string and `is_primary` stays a
 * designation with no cardinality rule, for the reasons given in
 * {@see StoreProjectPartyRequest}.
 *
 * The refusal keys on **presence**, not emptiness (D-097).
 */
class UpdateProjectPartyRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'project_id',
        'party_id',
        'office_id',
        'created_by',
        'created_at',
        'updated_at',
        'updated_by',
        'deleted_at',
        'effective_from',
        'effective_until',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'role_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Refuse a system-controlled key that is *present*, whatever its value
     * (D-097).
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::FORBIDDEN as $field) {
                if (! $this->has($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $validator->errors()->add($field, $this->messageFor($field));
            }
        });
    }

    private function messageFor(string $field): string
    {
        return $this->messages()[$field.'.prohibited']
            ?? trans('validation.prohibited', ['attribute' => str_replace('_', ' ', $field)]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'party_id.prohibited' => 'Remove this participation and add the other Party instead.',
            'project_id.prohibited' => 'A participation cannot be moved to another Project.',
            'office_id.prohibited' => 'A participation takes its Office from the Project.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participationAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['role_code', 'is_primary', 'notes']),
        );
    }
}
