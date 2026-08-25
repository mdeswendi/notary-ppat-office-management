<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for recording a Deed's legal number (M7.2, D-121).
 *
 * **One field, and no format.** *"What are the deed numbering rules, and who assigns
 * the number?"* is open question one in `09_PPAT_WORKFLOW.md` section 6, and
 * `CLAUDE.md` section 62 names deed numbering rules explicitly among the things not
 * to invent. There is no regular expression here, no length convention beyond a
 * storage bound, and no derived segment — the office types what its own numbering
 * produces.
 *
 * `required` rather than nullable: clearing a number is not this endpoint's job. A
 * deed that should never have been numbered is a correction mechanism, which is open
 * question five.
 *
 * **Uniqueness is checked in the controller, not here, and the ordering is the
 * reason.** A scoped `Rule::unique` needs the deed's `office_id`, and the deed is
 * resolved through canonical visibility so an unreachable one answers 404 — that
 * resolution happens after Form Request validation. Checking uniqueness here would
 * mean either resolving the deed twice or reading an Office from a record the caller
 * may not be allowed to know exists. The controller has the resolved deed and raises
 * the same 422 field error.
 */
class RecordDeedNumberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'deed_number' => ['required', 'string', 'max:100'],
        ];
    }

    public function deedNumber(): string
    {
        return trim((string) $this->validated('deed_number'));
    }
}
