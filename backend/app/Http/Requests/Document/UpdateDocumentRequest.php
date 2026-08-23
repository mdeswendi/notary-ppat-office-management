<?php

namespace App\Http\Requests\Document;

use App\Domains\Document\Actions\UpdateDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for correcting a Document's metadata (M5.2, D-117).
 *
 * **Metadata only, and no file.** A correction to the bytes is a new version, not
 * an edit — `CLAUDE.md` section 19 forbids overwriting one — so `file` is
 * `prohibited` here rather than optional. A caller who sends one is told, which is
 * better than a `PATCH` that appears to accept a replacement and silently keeps
 * the old bytes.
 *
 * **`is_sensitive` is accepted but guarded.** Whether it may actually change
 * depends on the Document's status, which is a record question rather than a
 * shape one, so {@see UpdateDocument} decides it and
 * answers 422. This class validates shape; the Action validates state.
 *
 * `status` is `prohibited` for the D-091 reason every domain here follows:
 * verification and archiving are separate capabilities with separate endpoints,
 * and letting ordinary update write the column would make `documents.update` a
 * silent superset of both.
 */
class UpdateDocumentRequest extends FormRequest
{
    /**
     * System-controlled, refused outright.
     */
    private const FORBIDDEN = [
        'id',
        'office_id',
        'document_number',
        'status',
        'current_version_id',
        'created_by',
        'updated_by',
        'archived_at',
        'archived_by',
        'deleted_at',
        'file',
        'related_to',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'document_type_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'document_date' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
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
            'office_id.prohibited' => 'A document cannot be moved between offices.',
            'document_number.prohibited' => 'The document number is permanent.',
            'status.prohibited' => 'Use the verify or archive action to change the status.',
            'current_version_id.prohibited' => 'Upload a new version to change the current file.',
            'file.prohibited' => 'A replacement file is a new version, not an edit.',
            'related_to.prohibited' => 'Attachments are managed on their own surface.',
        ];
    }

    /**
     * The correctable fields, and only those.
     *
     * `array_intersect_key` against the **validated** payload, so a field the
     * caller did not send stays absent rather than arriving as null — a `PATCH`
     * that omits `notes` must not erase them.
     *
     * @return array<string, mixed>
     */
    public function documentAttributes(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'title',
            'document_type_code',
            'is_sensitive',
            'document_date',
            'expiry_date',
            'notes',
        ]));
    }
}
