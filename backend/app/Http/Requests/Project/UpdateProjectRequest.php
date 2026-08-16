<?php

namespace App\Http\Requests\Project;

use App\Domains\Project\Enums\ProjectPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for an ordinary Project update (M3.3, D-091).
 *
 * The same shape as create, minus the requirement — a partial `PATCH` may send
 * one field — and with the same refusals.
 *
 * **`status` and `pic_user_id` are prohibited here specifically**, and that is
 * the point of the whole request class. Each has its own capability
 * (`projects.change_status`, `projects.assign`) and its own endpoint. Accepting
 * either in the ordinary update body would make `projects.update` a silent
 * superset of both — the failure D-082 guards against for identity, one domain
 * removed, and the reason `06_API_CONVENTIONS.md` now states the rule generally.
 *
 * `completed_at` is permitted here, unlike at create, because a Project that has
 * finished may legitimately record when. Nothing couples it to the `COMPLETED`
 * status: that would be a business rule nobody has stated, and it would be wrong
 * the first time a status is corrected.
 *
 * The refusal keys on **presence**, not on emptiness — see `withValidator()`.
 * `{"pic_user_id": null}` is an unassign instruction, and it belongs to the
 * assignment endpoint like any other.
 */
class UpdateProjectRequest extends FormRequest
{
    /**
     * System-controlled or separately-governed, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',
        'project_number',
        'status',
        'pic_user_id',
        'created_by',
        'updated_by',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::enum(ProjectPriority::class)],
            'opened_at' => ['sometimes', 'nullable', 'date'],
            'target_completion_date' => ['sometimes', 'nullable', 'date'],
            'completed_at' => ['sometimes', 'nullable', 'date'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Refuse a system-controlled key that is *present*, whatever its value.
     *
     * `prohibited` is not sufficient on its own: Laravel reads it as "missing or
     * empty", so `{"pic_user_id": null}` satisfies it and the request answers
     * 200. Nothing would be written either way — `projectAttributes()` intersects
     * an explicit allow-list and the model keeps these columns out of
     * `$fillable` — but a caller asking to unassign would be told the
     * instruction was accepted when it was discarded. D-091 says the ordinary
     * update refuses status and PIC, and a silent no-op is not a refusal.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::FORBIDDEN as $field) {
                // `prohibited` has already spoken for a non-empty value; adding
                // a second message for the same field would only be noise.
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
            'office_id.prohibited' => 'A Project cannot be moved between Offices.',
            'project_number.prohibited' => 'The Project number cannot be changed once allocated.',
            'status.prohibited' => 'Change the status from the status action.',
            'pic_user_id.prohibited' => 'Change the person in charge from the assignment action.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'title', 'description', 'priority', 'opened_at', 'target_completion_date', 'completed_at',
        ]));
    }
}
