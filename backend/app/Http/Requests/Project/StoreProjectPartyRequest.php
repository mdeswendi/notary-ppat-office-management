<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for linking a Party to a Project (M3.4, D-098).
 *
 * **`role_code` is a free string with a length bound and nothing else.** No
 * enum, no `Rule::in`, no catalogue. `03_DATABASE_ERD.md` section 7 offers six
 * example codes and explicitly labels them examples rather than a catalogue, so
 * constraining the column here would invent the participant-role vocabulary M3
 * has no authority to write (CLAUDE.md section 62). It is nullable because a
 * participation may legitimately be recorded before anybody has classified it.
 *
 * **`is_primary` is a designation and carries no cardinality rule.** Nothing
 * checks that exactly one participant is primary, that at least one is, that a
 * primary has a role, or that primary means client authority. Several rows may
 * be primary at once. That is deliberate pending domain authority (D-092), and
 * the absence is recorded here so a later reader does not mistake it for an
 * oversight.
 *
 * `party_id` is required but is **not trusted**: the controller re-resolves it
 * through the authorized candidate query, so an id obtained elsewhere cannot
 * become a participation (D-098). Validation here only shapes the input.
 *
 * The refusal keys on **presence**, not emptiness, following D-097 — the defect
 * M3.3 shipped and fixed. `{"office_id": null}` is still an instruction about
 * `office_id`, and this endpoint does not take one.
 */
class StoreProjectPartyRequest extends FormRequest
{
    /**
     * System-controlled or structurally fixed, refused outright.
     *
     * `project_id` comes from the route and `office_id` is copied from the
     * Project as the constraint carrier; neither is an input. `created_at` and
     * `created_by` are actor metadata written by the application.
     */
    private const FORBIDDEN = [
        'id',
        'project_id',
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
            'party_id' => ['required', 'string', 'ulid'],
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
            'project_id.prohibited' => 'The Project comes from the address, not the body.',
            'office_id.prohibited' => 'A participation takes its Office from the Project.',
            'effective_from.prohibited' => 'Project participation records current involvement, not a period.',
            'effective_until.prohibited' => 'Project participation records current involvement, not a period.',
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
            array_flip(['role_code', 'is_primary', 'notes']),
        );
    }
}
