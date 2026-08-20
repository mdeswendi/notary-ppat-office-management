<?php

namespace App\Http\Requests\Matter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for correcting a Matter participation (M4.5, D-105).
 *
 * The same two fields the Action may write, and **`party_id` is refused here
 * even though it is required at creation**. That is the difference worth
 * reading: re-pointing a participation at a different Party is not a correction
 * of that participation, it is a different relationship. Accepting it would let
 * one row silently become another while keeping the `created_by` and
 * `created_at` of the first, and it would bypass the candidate authorization the
 * store path performs. Remove and add instead.
 *
 * `role_code` stays an unconstrained bounded string, for the reasons given in
 * {@see StoreMatterPartyRequest}.
 *
 * The refusal keys on **presence**, not emptiness (D-097).
 */
class UpdateMatterPartyRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'matter_id',
        'party_id',
        'office_id',
        'created_by',
        'created_at',
        'updated_at',
        'updated_by',
        'deleted_at',
        'effective_from',
        'effective_until',
        'sequence_no',
        'represented_by_party_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'role_code' => ['sometimes', 'nullable', 'string', 'max:30'],
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
            'matter_id.prohibited' => 'A participation cannot be moved to another Matter.',
            'office_id.prohibited' => 'A participation takes its Office from the Matter.',
            'sequence_no.prohibited' => 'Participant ordering is not defined yet.',
            'represented_by_party_id.prohibited' => 'Representation between Parties is not defined yet.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participationAttributes(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['role_code', 'notes']),
        );
    }
}
