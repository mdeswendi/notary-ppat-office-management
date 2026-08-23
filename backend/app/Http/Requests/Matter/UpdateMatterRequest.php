<?php

namespace App\Http\Requests\Matter;

use App\Domains\Project\Enums\ProjectPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for correcting a Matter (M4.4, D-109).
 *
 * **Ordinary attributes only.** Everything governed by its own capability is
 * `prohibited` and refused on presence, so a generic edit cannot become a way
 * around a boundary somebody granted separately:
 *
 *   pic_user_id   answers to `*.matters.assign`
 *   status        answers to `*.matters.complete` / `*.matters.cancel`
 *   completed_at  is stamped by completion, not typed by a caller
 *
 * `project_id`, `office_id`, `domain` and `matter_number` are refused because they
 * are identity: the model guards all four, and re-parenting would move a Matter
 * between Offices and invalidate the Office-scoped reference namespace (D-096's
 * reasoning, D-107, D-109).
 *
 * `service_type_id` **is** editable — reclassifying work is an ordinary
 * correction, and the database still refuses a Service Type from another Office or
 * the other domain, so the invariant does not depend on this validator (D-107).
 */
class UpdateMatterRequest extends FormRequest
{
    /**
     * Governed elsewhere, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'project_id',
        'office_id',
        'domain',
        'matter_number',
        'status',
        'pic_user_id',
        'created_by',
        'updated_by',
        'deleted_at',
        'completed_at',
        'current_stage_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'service_type_id' => ['sometimes', 'nullable', 'string'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'priority' => ['sometimes', 'nullable', Rule::enum(ProjectPriority::class)],
            'opened_at' => ['sometimes', 'nullable', 'date'],
            'target_completion_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

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
            'project_id.prohibited' => 'A Matter cannot be moved to another Project.',
            'office_id.prohibited' => 'A Matter belongs to the Office of its Project.',
            'domain.prohibited' => 'A Matter cannot change domain.',
            'matter_number.prohibited' => 'The Matter number is permanent.',
            'status.prohibited' => 'Use the complete or cancel action to change the status.',
            'pic_user_id.prohibited' => 'Use the assignment action to change who is in charge.',
            'completed_at.prohibited' => 'The completion time is recorded when a Matter is completed.',
        ];
    }

    /**
     * The ordinary attributes, and only those.
     *
     * @return array<string, mixed>
     */
    public function matterAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'title',
            'priority',
            'opened_at',
            'target_completion_date',
            'notes',
        ]));
    }

    public function hasServiceType(): bool
    {
        return array_key_exists('service_type_id', $this->validated());
    }

    public function serviceTypeId(): ?string
    {
        $value = $this->validated()['service_type_id'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
