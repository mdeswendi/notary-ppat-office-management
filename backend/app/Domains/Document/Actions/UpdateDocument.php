<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\Exceptions\SensitivityIsSettled;
use App\Models\Document;
use App\Models\User;

/**
 * Correct a Document's metadata (M5.2, D-117).
 *
 * **Metadata only, never the file.** A correction to the bytes is a new version;
 * `CLAUDE.md` section 19 forbids overwriting one, and `DocumentVersion` refuses
 * `update` outright. This action cannot reach a version at all.
 *
 * **Five ordinary fields plus one guarded one.** `title`, `document_type_code`,
 * `document_date`, `expiry_date` and `notes` are fillable and ordinary.
 * `is_sensitive` is neither: it decides which capability a download answers to,
 * so it is assigned explicitly and refused once the document is settled.
 *
 * Everything else is out of reach by construction rather than by omission —
 * `document_number`, `office_id`, `created_by`, `status` and `current_version_id`
 * are not fillable, and the Form Request refuses each one by name so a caller who
 * sends it is told rather than quietly ignored.
 *
 * `updated_by` is stamped here, which is what the column is for: `updated_at`
 * alone records that something changed without recording who.
 */
class UpdateDocument
{
    /**
     * @param  array<string, mixed>  $attributes  ordinary fields, optionally including is_sensitive
     */
    public function handle(User $actor, Document $document, array $attributes): Document
    {
        if (array_key_exists('is_sensitive', $attributes)) {
            $this->applySensitivity($document, (bool) $attributes['is_sensitive']);

            unset($attributes['is_sensitive']);
        }

        $document->fill($attributes);
        $document->updated_by = $actor->getKey();
        $document->save();

        return $document;
    }

    /**
     * Change the sensitivity flag, unless verification has settled it.
     *
     * **Refused only when the value actually differs.** A `PATCH` that resends the
     * current value alongside a title correction is not an attempt to reclassify
     * anything, and answering 422 to it would make the whole form unusable on a
     * verified document — the interface would have to know to strip a field it
     * legitimately displays.
     */
    private function applySensitivity(Document $document, bool $requested): void
    {
        if ($document->is_sensitive === $requested) {
            return;
        }

        if ($document->status->locksSensitivity()) {
            throw new SensitivityIsSettled($document->status);
        }

        $document->is_sensitive = $requested;
    }
}
