<?php

namespace App\Http\Requests\Matter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for linking a Party to a Matter (M4.5, D-105).
 *
 * **`role_code` is a free string with a length bound and nothing else.** No
 * enum, no `Rule::in`, no catalogue. `03_DATABASE_ERD.md` section 9 offers
 * `SELLER`, `BUYER`, `SELLER_SPOUSE`, `AUTHORIZED_PERSON`, `WITNESS`,
 * `DIRECTOR`, `COMMISSIONER` and `SHAREHOLDER` and explicitly labels them
 * *example* role codes, so constraining the column here would invent the
 * participant-role vocabulary M4 has no authority to write (CLAUDE.md section
 * 62, D-105). It is nullable because a participation may legitimately be
 * recorded before anybody has classified it.
 *
 * **No cardinality rule is checked either.** Nothing requires a seller, caps the
 * participants, or refuses the same Party twice — a Party may legitimately hold
 * two classifications in one Matter, and deciding otherwise is a domain question
 * with no canonical answer here.
 *
 * `party_id` is required but is **not trusted**: the controller re-resolves it
 * through the authorized candidate query, so an id obtained elsewhere cannot
 * become a participation (D-105). Validation here only shapes the input.
 *
 * The refusal keys on **presence**, not emptiness, following D-097 — the defect
 * M3.3 shipped and fixed. `{"office_id": null}` is still an instruction about
 * `office_id`, and this endpoint does not take one.
 */
class StoreMatterPartyRequest extends FormRequest
{
    /**
     * System-controlled, structurally fixed, or deferred — all refused outright.
     *
     * `matter_id` comes from the route and `office_id` is copied from the Matter
     * as the constraint carrier; neither is an input. `created_at` and
     * `created_by` are actor metadata written by the application.
     *
     * `sequence_no` and `represented_by_party_id` are refused because they are
     * **deferred pending domain validation** (D-105), not because they are
     * system-controlled. Accepting and dropping them would teach a caller that
     * the fields work; refusing them says plainly that the concepts are not
     * built.
     */
    private const FORBIDDEN = [
        'id',
        'matter_id',
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
            'party_id' => ['required', 'string', 'ulid'],
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
            'matter_id.prohibited' => 'The Matter comes from the address, not the body.',
            'office_id.prohibited' => 'A participation takes its Office from the Matter.',
            'effective_from.prohibited' => 'Matter participation records current involvement, not a period.',
            'effective_until.prohibited' => 'Matter participation records current involvement, not a period.',
            'sequence_no.prohibited' => 'Participant ordering is not defined yet.',
            'represented_by_party_id.prohibited' => 'Representation between Parties is not defined yet.',
        ];
    }

    /**
     * The relationship metadata the Action may fill.
     *
     * `party_id` is deliberately absent: it is resolved and authorized
     * separately, so it never reaches the model through mass assignment.
     *
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
