<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for editing a PPAT Deed (M7.2, D-121).
 *
 * Every field is optional — a partial edit is the normal case — but `title` may not
 * be sent empty, because a deed with no name is unusable in a list and clearing it
 * is never what somebody meant.
 *
 * **The same fields are prohibited as at creation**, plus `matter_id`: a deed is the
 * output of one Matter and its `OWN` and `ASSIGNED` reach resolve through that Matter
 * (D-121), so moving it would move the deed between people's reach without anybody
 * deciding it. The model refuses it too; this turns a 500 into a field error.
 *
 * **Whether the deed's status permits an edit at all is the Action's**, and it
 * answers 422 rather than 403 — the caller is authorized and would succeed on a deed
 * in a different state.
 */
class UpdatePpatDeedRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'matter_id',
        'status',
        'deed_number',
        'reviewed_at',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'finalized_at',
        'finalized_by',
        'locked_at',
        'locked_by',

        // One document pointer, not three — see StorePpatDeedRequest.
        'draft_document_id',
        'minuta_document_id',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'deed_date' => ['sometimes', 'nullable', 'date'],
            'deed_type_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'final_document_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function deedAttributes(): array
    {
        return collect($this->validated())
            ->only(['title', 'deed_date', 'deed_type_code', 'final_document_id'])
            ->all();
    }
}
