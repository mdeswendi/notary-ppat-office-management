<?php

namespace App\Http\Requests\Ppat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for filing a Document against a line of a Warkah (M7.4, D-121).
 *
 * One field, and **no `exists` rule on it**. Existence is not the question the caller
 * is entitled to an answer to: the controller resolves the Document through canonical
 * `documents.view` visibility and answers one indistinguishable field error for an
 * unreachable, wrong-Office or nonexistent id. An `exists` rule here would answer
 * "that document is real but not yours", which is the existence oracle every resolve-
 * through-visibility path in this repository exists to avoid.
 *
 * `attached_at` and `attached_by` are stamped by the server; the junction has no `id`
 * for a caller to supply.
 */
class AttachWarkahDocumentRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'warkah_item_id',
        'attached_at',
        'attached_by',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'document_id' => ['required', 'string', 'size:26'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function documentId(): string
    {
        return (string) $this->validated('document_id');
    }
}
