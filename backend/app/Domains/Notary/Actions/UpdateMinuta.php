<?php

namespace App\Domains\Notary\Actions;

use App\Models\Document;
use App\Models\NotaryMinuta;
use App\Models\User;

/**
 * Correct a filed Minuta Akta (M6.3, D-120).
 *
 * **Replacing the Document is the point, not an edge case.** A bad scan, a page
 * missed, a better copy — re-pointing `document_id` is ordinary correction, and both
 * Documents keep their own version histories either side of it (D-116). It is the one
 * pointer on this row that may change; the deed may not, because a Minuta Akta is the
 * original record of exactly one deed.
 *
 * **No status transition, in either direction.** `release_status` has no vocabulary
 * and the archive trigger is open question four, so this action reads neither and
 * writes neither. There is no deed-status gate either: gating correction on a
 * finalized deed would say a filing error becomes permanent once the deed is signed,
 * which no document states and which is the opposite of what a records office needs.
 */
class UpdateMinuta
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        NotaryMinuta $minuta,
        ?Document $document,
        array $attributes,
    ): NotaryMinuta {
        $minuta->fill($attributes);

        if ($document !== null) {
            $minuta->document_id = $document->getKey();
        }

        $minuta->save();

        return $minuta;
    }
}
