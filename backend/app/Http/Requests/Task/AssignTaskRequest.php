<?php

namespace App\Http\Requests\Task;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for handing a Task over, or taking it back (M5.4, D-119).
 *
 * **`assigned_to` is required but may be null**, which is the difference between
 * `required` and `present`: the key must be sent, and its value may be `null` to
 * unassign. That way a caller who omits it entirely gets a field error rather than
 * silently clearing the assignee — the failure mode a plain `nullable` would
 * invite, where a malformed payload quietly takes the work off somebody.
 *
 * Whether the person exists, is active, and works in this Task's Office is decided
 * by the controller through User visibility; an id that fails any of those
 * produces one indistinguishable 422, because telling them apart would answer a
 * question the caller has no permission to ask.
 */
class AssignTaskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['present', 'nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to.present' => 'Send assigned_to, using null to unassign the task.',
        ];
    }

    /**
     * The person to hand the work to, or null to take it back.
     */
    public function assigneeId(): ?string
    {
        $value = $this->validated()['assigned_to'] ?? null;
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
