<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\Exceptions\DocumentStatusNotEligible;
use App\Models\Document;
use App\Models\User;

/**
 * Remove a Document nobody has verified yet (M5.2, D-117).
 *
 * `DRAFT` or `RECEIVED` only. Anything further along is 422 — once somebody has
 * verified a document, removing it is not an ordinary act, and
 * `02_MENU_AND_PERMISSIONS.md` section 13's requirement that `documents.delete`
 * be *"heavily restricted"* is met by a status rule rather than by a permission
 * one. An archived document in particular can never be deleted: it reached
 * `ARCHIVED` through `VERIFIED`.
 *
 * **Soft delete, and the file is left exactly where it is.** The bytes and the
 * checksum stay on disk, and every version row survives. `CLAUDE.md` section 19
 * forbids overwriting a version, section 30 forbids destructive deletion of legal
 * records, and a soft delete that quietly erased files would be a hard delete
 * wearing a soft one's name. Reclaiming storage for a removed document is a
 * retention decision nobody has taken, and it is not this milestone's to invent.
 *
 * **The junction rows survive too**, and they are what would block a hard delete
 * — every junction foreign key is `RESTRICT`. Soft delete leaves them intact, so
 * the attachment history of a removed document is still there if it is restored.
 *
 * There is no restore endpoint. `documents.delete` is the only canonical code near
 * this act, and reading it as *"may also undelete"* would make one capability do
 * two jobs — the D-091 discipline. Restoring is a milestone decision with its own
 * capability question.
 */
class DeleteDocument
{
    public function handle(User $actor, Document $document): Document
    {
        if (! $document->status->isDeletable()) {
            throw new DocumentStatusNotEligible($document->status, 'deleted');
        }

        // Stamped before the delete so the last hand on the record is recorded.
        // `deleted_at` says when it went; `updated_by` says who sent it.
        $document->updated_by = $actor->getKey();
        $document->save();

        $document->delete();

        return $document;
    }
}
