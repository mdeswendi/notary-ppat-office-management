<?php

namespace App\Domains\Notary\Actions;

use App\Models\Document;
use App\Models\NotaryDeed;
use App\Models\NotaryMinuta;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record where a deed's original is filed (M6.3, D-120).
 *
 * **Office is inherited from the deed and never accepted from the caller** — the
 * composite keys permit nothing else, and letting a request name an Office would be
 * letting it choose which Documents the row can reference.
 *
 * **No status is set.** `release_status` has no vocabulary in any canonical document,
 * so a new Minuta carries none rather than a `DRAFT` this milestone would have had to
 * invent (D-120).
 *
 * **No deed status is required.** A Minuta may be filed against a deed in any state:
 * *when* an office files the original is a question `08_NOTARY_WORKFLOW.md` section 6
 * does not answer, and gating it on `FINALIZED` would be answering it.
 *
 * A transaction because the insert must happen whole or not at all — the row carries
 * three references the composite keys check together.
 */
class FileMinuta
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, NotaryDeed $deed, Document $document, array $attributes): NotaryMinuta
    {
        return DB::transaction(function () use ($deed, $document, $attributes): NotaryMinuta {
            $minuta = new NotaryMinuta;

            $minuta->fill($attributes);

            // Identity, decided here rather than by the caller.
            $minuta->notary_deed_id = $deed->getKey();
            $minuta->office_id = $deed->office_id;
            $minuta->document_id = $document->getKey();

            $minuta->save();

            return $minuta;
        });
    }
}
