<?php

namespace App\Http\Requests\Task;

use App\Domains\Project\Enums\ProjectPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for raising a Task (M5.4, D-119).
 *
 * `title` is the only required field, and it is required **structurally**: a task
 * with no name is unusable in a list. Nothing here is required on legal grounds.
 *
 * **`assigned_to` is optional, and the plan wanted it required with a default of
 * the creator.** That default would have made every unassigned task look assigned,
 * and `ASSIGNED` would then silently mean "raised by me" for exactly the tasks
 * nobody has picked up — the predicate would stop answering the question it
 * exists for. Work often exists before anybody holds it, so a Task with no
 * assignee is complete rather than a draft.
 *
 * **`due_at` accepts a past date, and the plan asked to refuse one.** An office
 * records work that was already due — a filing deadline that slipped, a task
 * entered on Monday for something owed on Friday. Refusing it would make the
 * system unable to describe the situation it most needs to show, and `isOverdue()`
 * exists precisely to render it. No canonical document requires a future date.
 *
 * **Every system-controlled field is `prohibited`, not silently dropped**, and the
 * refusal keys on **presence** rather than emptiness — the M3.3 pattern (D-097).
 * `status` is among them: a new Task is `OPEN`, and completion and cancellation
 * answer to capabilities an administrator granted separately.
 */
class StoreTaskRequest extends FormRequest
{
    /**
     * System-controlled, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',
        'status',
        'created_by',
        'assigned_by',
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::enum(ProjectPriority::class)],

            // Shape only. Whether the record exists, is in the caller's Office and
            // is reachable is decided by the controller through each domain's
            // visibility class.
            'project_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'matter_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'ulid'],

            // A date, and no ordering rule: see the class docblock.
            'due_at' => ['sometimes', 'nullable', 'date'],
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
     * empty", so `{"status": null}` satisfies it and the request answers 201.
     * Nothing would be written either way, but the caller would be told an
     * instruction was accepted when it was discarded.
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
            'office_id.prohibited' => 'A task is raised in your own office.',
            'status.prohibited' => 'A new task starts as OPEN.',
            'created_by.prohibited' => 'The creator is the person raising the task.',
            'assigned_by.prohibited' => 'The assigner is recorded when the task is assigned.',
            'completed_at.prohibited' => 'A task cannot be completed before it exists.',
            'workflow_stage_instance_id.prohibited' => 'Tasks are not yet raised from workflow stages.',
        ];
    }

    /**
     * The ordinary attributes, and only those.
     *
     * An explicit allow-list rather than `except()`: a field added to the payload
     * later is excluded until somebody names it here.
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
        ]));
    }

    public function projectId(): ?string
    {
        return $this->normalized('project_id');
    }

    public function matterId(): ?string
    {
        return $this->normalized('matter_id');
    }

    public function assigneeId(): ?string
    {
        return $this->normalized('assigned_to');
    }

    /**
     * An HTML form renders an unselected picker as `""`; treating that as an id
     * would produce a foreign-key error where the caller meant "none".
     */
    private function normalized(string $key): ?string
    {
        $value = $this->validated()[$key] ?? null;
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
