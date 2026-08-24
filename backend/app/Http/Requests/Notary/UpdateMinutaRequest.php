<?php

namespace App\Http\Requests\Notary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for correcting a filed Minuta Akta (M6.3, D-120).
 *
 * Every field is optional — a partial edit is the normal case — but `document_id`
 * may not be sent empty: a Minuta record with no file is a shelf reference to
 * nothing, and clearing it is never what somebody meant.
 *
 * **`document_id` is deliberately editable, and it is the only pointer that is.**
 * Replacing a bad scan is ordinary correction, and the M6.3 brief asked for it
 * explicitly. Both Documents keep their own version histories (D-116), so nothing is
 * lost either side of the change.
 *
 * **`notary_deed_id` is prohibited**, and the model refuses it too. A Minuta Akta is
 * the original record of *one* deed; re-pointing it would file one deed's original
 * under another. This turns a 500 into a field error.
 *
 * The same three lifecycle fields are refused as at creation, for the same reason:
 * the ERD gives `release_status` no vocabulary and the archive trigger is open
 * question four.
 */
class UpdateMinutaRequest extends FormRequest
{
    private const FORBIDDEN = [
        'id',
        'office_id',
        'notary_deed_id',
        'release_status',
        'archived_at',
        'archived_by',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'document_id' => ['sometimes', 'required', 'string', 'size:26'],
            'archive_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'volume_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bundle_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function documentId(): ?string
    {
        $value = $this->validated('document_id');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function minutaAttributes(): array
    {
        return collect($this->validated())
            ->only(['archive_location', 'volume_number', 'bundle_number', 'notes'])
            ->all();
    }
}
