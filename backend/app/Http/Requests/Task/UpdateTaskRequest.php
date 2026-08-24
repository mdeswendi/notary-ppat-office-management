<?php

namespace App\Http\Requests\Task;

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Actions\UpdateTask;
use App\Domains\Task\Enums\TaskStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for correcting a Task (M5.4, D-119).
 *
 * **`status` is accepted here and constrained to the three live values.** `OPEN`,
 * `IN_PROGRESS` and `WAITING` describe how work is going, which is what an
 * ordinary edit is for; `COMPLETED` and `CANCELLED` are decisions answering to
 * `tasks.complete` and `tasks.delete`, so accepting either would make
 * `tasks.update` a silent superset of another capability (D-091).
 *
 * Whether the Task's *current* status permits an edit at all is a record question
 * rather than a shape one, so
 * {@see UpdateTask} decides it and answers 422. This
 * class validates shape; the Action validates state.
 *
 * **The parents and the assignee are `prohibited`.** Re-parenting a Task would
 * move it between engagements and, through the composite keys, between Offices;
 * reassigning answers to `tasks.assign` on its own endpoint. A `PATCH` that
 * appeared to accept either and silently ignored it would be worse than one that
 * refuses.
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * System-controlled or handled elsewhere, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',
        'project_id',
        'matter_id',
        'assigned_to',
        'assigned_by',
        'created_by',
        'completed_at',
        'completed_by',
        'deleted_at',
        'workflow_stage_instance_id',
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
            'due_at' => ['sometimes', 'nullable', 'date'],

            // The three live statuses only. `Rule::enum` would accept COMPLETED
            // and CANCELLED, which is the whole point of not using it here.
            'status' => [
                'sometimes',
                'required',
                Rule::in(array_map(
                    static fn (TaskStatus $case): string => $case->value,
                    TaskStatus::settableByUpdate(),
                )),
            ],
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
            'office_id.prohibited' => 'A task cannot be moved between offices.',
            'project_id.prohibited' => 'A task cannot be moved to another project.',
            'matter_id.prohibited' => 'A task cannot be moved to another matter.',
            'assigned_to.prohibited' => 'Use the assign action to change who holds this task.',
            'created_by.prohibited' => 'The creator of a task is permanent.',
            'completed_at.prohibited' => 'Use the complete action to finish a task.',
            'status.in' => 'Use the complete or delete action to finish or cancel a task.',
        ];
    }

    /**
     * The correctable fields, and only those.
     *
     * `array_intersect_key` against the **validated** payload, so a field the
     * caller did not send stays absent rather than arriving as null — a `PATCH`
     * that omits `description` must not erase it.
     *
     * @return array<string, mixed>
     */
    public function taskAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'title',
            'description',
            'priority',
            'due_at',
            'status',
        ]));
    }
}
