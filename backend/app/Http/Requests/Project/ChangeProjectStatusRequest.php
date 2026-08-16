<?php

namespace App\Http\Requests\Project;

use App\Domains\Project\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a Project status change (M3.3, D-091).
 *
 * One field. Only the canonical `ProjectStatus` codes are accepted, so a
 * translated label — `Sedang Diproses` — is a 422 rather than a stored value
 * (CLAUDE.md section 12).
 *
 * **No transition is validated, deliberately.** There is no rule that
 * `COMPLETED` may not return to `IN_PROGRESS`, no cancellation lock, and no
 * approval gate. Which status may follow which is an operational rule no
 * canonical document defines; M3 authorizes *who* may change status and leaves
 * *what the change means* to the office (D-091). A matrix invented here would be
 * indistinguishable, to a later reader, from one somebody actually specified.
 */
class ChangeProjectStatusRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ];
    }

    public function status(): ProjectStatus
    {
        return ProjectStatus::from((string) $this->validated()['status']);
    }
}
