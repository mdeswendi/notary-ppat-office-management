<?php

namespace App\Http\Requests\Document;

use App\Domains\Document\DocumentStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for filing a Document (M5.2, D-117).
 *
 * `title` and `file` are the only required fields, and both are required
 * **structurally**: a document with no name is unusable in a list, and a Document
 * record with no file is the orphan {@see DocumentStorage}
 * refuses to create. Nothing here is required on legal grounds, and no business
 * rule is invented — there is no check that `expiry_date` follows `document_date`,
 * because no canonical document says a certificate may not be reissued with an
 * earlier expiry, and guessing is what `CLAUDE.md` section 62 forbids.
 *
 * **`document_type_code` is validated for length and nothing else.** No enum, no
 * `Rule::in`, no `CHECK` — `KTP`, `NPWP`, `AKTA` and `SERTIPIKAT` are examples in
 * prose, not a validated catalogue (D-115, D-116). The options endpoint offers
 * them as suggestions and this accepts anything an office actually uses.
 *
 * **`related_to` is entirely optional, and so is every key inside it.** A document
 * may legitimately be filed before anybody knows which matter it belongs to —
 * that is the ordinary state of a scan that arrives by email. Requiring at least
 * one relation would have made the common case impossible.
 *
 * **Every system-controlled field is `prohibited`, not silently dropped**, and the
 * refusal keys on **presence** rather than emptiness — the M3.3 pattern (D-097),
 * for the same reason: an interface that appears to accept `office_id` and then
 * ignores it is worse than one that refuses.
 *
 * `status` is among them. A new Document is `RECEIVED`, and verification is a
 * capability an administrator granted separately (D-117) — letting `documents.upload`
 * choose a status would make it a silent superset of `documents.verify`.
 */
class UploadDocumentRequest extends FormRequest
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

        // Version metadata. Every one of these is computed from the bytes that
        // actually land — accepting a caller's `checksum_sha256` would make the
        // checksum attest to whatever they claimed rather than to the file.
        'storage_disk',
        'storage_path',
        'stored_filename',
        'checksum_sha256',
        'file_size',
        'version_number',
    ];

    /**
     * What may be uploaded.
     *
     * Kept beside the rules rather than in config, because the options endpoint
     * serves the same list and a second copy would drift. An office that needs
     * another type is a decision, not a setting to be guessed at now.
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Kilobytes. 20 MB, and configurable because a scanner's output size is a
     * deployment fact rather than an application rule.
     */
    public static function maxKilobytes(): int
    {
        return (int) config('documents.max_upload_kilobytes', 20480);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'document_type_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'document_date' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],

            // `mimetypes` rather than `mimes`: it checks the file's actual type as
            // detected from its contents, where `mimes` trusts the extension. A
            // renamed executable passes the second and fails the first.
            'file' => [
                'required',
                'file',
                'max:'.self::maxKilobytes(),
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
            ],

            'related_to' => ['sometimes', 'nullable', 'array'],
            'related_to.party_id' => ['sometimes', 'nullable', 'string'],
            'related_to.project_id' => ['sometimes', 'nullable', 'string'],
            'related_to.matter_id' => ['sometimes', 'nullable', 'string'],
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
            'office_id.prohibited' => 'A document is filed in your own office.',
            'document_number.prohibited' => 'The document number is generated by the system.',
            'status.prohibited' => 'A new document starts as RECEIVED.',
            'current_version_id.prohibited' => 'The current version is set by the upload.',
            'checksum_sha256.prohibited' => 'The checksum is computed from the stored file.',
            'storage_path.prohibited' => 'The storage path is decided by the system.',
            'archived_at.prohibited' => 'A document cannot be archived before it exists.',
        ];
    }

    /**
     * The ordinary metadata, and only that.
     *
     * An explicit allow-list rather than `except()`: a field added to the payload
     * later is excluded until somebody names it here.
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

    /**
     * The three optional relation ids, normalized so an empty string is absent.
     *
     * An HTML form that renders an unselected picker sends `""`, and treating that
     * as an id would produce a foreign-key error where the caller meant "none".
     *
     * @return array{party_id: string|null, project_id: string|null, matter_id: string|null}
     */
    public function relations(): array
    {
        $related = $this->validated()['related_to'] ?? [];

        $normalize = static function (mixed $value): ?string {
            $value = is_string($value) ? trim($value) : null;

            return ($value === null || $value === '') ? null : $value;
        };

        return [
            'party_id' => $normalize($related['party_id'] ?? null),
            'project_id' => $normalize($related['project_id'] ?? null),
            'matter_id' => $normalize($related['matter_id'] ?? null),
        ];
    }
}
