<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\Enums\DocumentStatus;
use App\Domains\Document\Exceptions\DocumentStatusNotEligible;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Put a settled Document away (M5.2, D-117).
 *
 * `VERIFIED` or `FINAL` → `ARCHIVED`. Archiving is how an office files a
 * concluded record, not a way to shelve an undecided one, so an unverified
 * document is 422.
 *
 * **Archiving is a state, never a deletion** (`CLAUDE.md` section 30, ERD section
 * 33). `deleted_at` is untouched, the internal reference is kept permanently, and
 * an archived Document stays fully readable and fully in the ordinary list —
 * somebody must be able to read what the office put away, and a record referencing
 * it must stay resolvable (`CLAUDE.md` section 63). {@see DocumentVisibility}
 * deliberately does not filter on `archived_at` for that reason.
 *
 * `FINAL` is a source here and no capability in M5.2 can reach it. That is stated
 * rather than implied (the D-109 precedent): the rule is written so that an office
 * which later gains a way to finalize can archive from it without this action
 * changing.
 */
class ArchiveDocument
{
    public function handle(User $actor, Document $document): Document
    {
        if (! $document->status->isArchivable()) {
            throw new DocumentStatusNotEligible($document->status, 'archived');
        }

        $document->status = DocumentStatus::ARCHIVED;
        $document->archived_at = Date::now();
        $document->archived_by = $actor->getKey();
        $document->updated_by = $actor->getKey();
        $document->save();

        return $document;
    }
}
