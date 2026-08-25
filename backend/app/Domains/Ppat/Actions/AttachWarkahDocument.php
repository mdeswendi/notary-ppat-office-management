<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Document;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * File a Document against a line of a Warkah (M7.4, D-121).
 *
 * ## This is what completeness counts
 *
 * `PpatWarkah::computeCompleteness()` counts **lines with at least one document over
 * lines the office created**, and this action is the only thing that moves the
 * numerator up. That choice is the M7 lock's central Warkah ruling: a document being
 * attached is *observable*, where "verified" would need an item-status vocabulary the
 * ERD does not define (O-041).
 *
 * So the percentage is recomputed here, and it means one thing precisely: **every line
 * this office listed has a file against it.** Not that the Warkah is legally
 * sufficient — the mandatory composition per deed type is open question three, and the
 * interface says so in words.
 *
 * ## `attached_at` and `attached_by` are the record, and there is no `id`
 *
 * `ppat_warkah_documents` has a composite primary key `(warkah_item_id, document_id)`
 * — the shape every document junction has used since M5.1 — so re-attaching the same
 * file to the same line is not a second row. It is refused as already present rather
 * than silently duplicated.
 *
 * ## Attaching is not reading
 *
 * The Document is resolved through canonical `documents.view` visibility **before**
 * this runs, so `ppat.warkah.upload` never becomes a way to discover which files
 * exist. And attaching confers nothing onward: opening the file still answers to
 * `documents.view` and downloading to `documents.download`, each with its own Data
 * Scope, and a sensitive one still answers to `documents.sensitive.download` — which
 * D-115 leaves authorizing nothing until the audit store exists.
 *
 * A Warkah capability is never a way past any of those.
 */
class AttachWarkahDocument
{
    public function handle(User $actor, PpatWarkahItem $item, Document $document): void
    {
        DB::transaction(function () use ($actor, $item, $document): void {
            $already = $item->documents()->whereKey($document->getKey())->exists();

            if ($already) {
                return;
            }

            $item->documents()->attach($document->getKey(), [
                // The junction carries `office_id` as the composite-key carrier, so a
                // cross-Office pair is unrepresentable rather than merely validated.
                'office_id' => $item->office_id,
                'attached_at' => Date::now(),
                'attached_by' => $actor->getKey(),
            ]);

            $item->warkah()->firstOrFail()->recalculateCompleteness();
        });
    }
}
