<?php

namespace App\Http\Requests\Notary;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for filing a Minuta Akta (M6.3, D-120).
 *
 * `document_id` is the only required field: a Minuta record with no file is a shelf
 * reference to nothing. The deed comes from the **route**, not the body — the
 * address already names it, and accepting it twice would let the two disagree.
 *
 * **The three shelf fields are optional and free text.** `archive_location`,
 * `volume_number` and `bundle_number` describe a physical filing cabinet; requiring
 * them would assert the office has one arranged that way, and validating their shape
 * would be inventing its filing system.
 *
 * **Every system-controlled field is `prohibited`, not silently dropped**, and the
 * refusal keys on **presence** rather than emptiness — the D-097 pattern.
 *
 * `release_status`, `archived_at` and `archived_by` are refused for a reason worth
 * naming: the ERD carries all three, gives `release_status` **no vocabulary at all**,
 * and *"What triggers Minuta Akta archiving, and what release conditions apply?"* is
 * open question four. Accepting any of them here would let a caller write a lifecycle
 * nobody has defined.
 *
 * The Document's eligibility — reachable, in the deed's Office — is decided by the
 * controller and by the composite foreign key behind it, not here. This class
 * validates shape.
 */
class StoreMinutaRequest extends FormRequest
{
    /**
     * System-controlled or not-yet-defined, refused outright.
     */
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
            'document_id' => ['required', 'string', 'size:26'],
            'archive_location' => ['nullable', 'string', 'max:255'],
            'volume_number' => ['nullable', 'string', 'max:50'],
            'bundle_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
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

    /**
     * The shelf fields, without the Document the controller resolves separately.
     *
     * @return array<string, mixed>
     */
    public function minutaAttributes(): array
    {
        return collect($this->validated())
            ->only(['archive_location', 'volume_number', 'bundle_number', 'notes'])
            ->all();
    }
}
