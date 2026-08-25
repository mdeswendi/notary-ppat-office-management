<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Document;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stop treating a Document as satisfying a line of a Warkah (M7.4, D-121).
 *
 * **The junction row only** — never the Document, never the line, never the bundle.
 * The file stays in `documents` with its versions and its own capabilities intact; what
 * goes is the office's assertion that *this* file answers *this* requirement, which is
 * the thing being corrected when a scan is filed against the wrong line.
 *
 * **`ppat.warkah.upload`, the same code that attaches.** There is no
 * `ppat.warkah.detach` and no `ppat.warkah.delete` in the catalogue — six codes, and
 * neither is among them. Removing a misfiled document is the correction of the upload
 * rather than a different act, the reading M7.3 applied when
 * `properties.ownership.update` had to cover both adding and closing a link.
 *
 * `ppat_warkah_documents` has no `deleted_at` — the ERD gives it four columns and a
 * composite primary key — so this is a hard delete of the junction row and the
 * interface says so rather than implying an undo.
 *
 * **Completeness is recomputed**, and it may fall: a line whose last document is
 * detached stops counting toward the numerator. That is the arithmetic being honest.
 */
class DetachWarkahDocument
{
    public function handle(User $actor, PpatWarkahItem $item, Document $document): void
    {
        DB::transaction(function () use ($item, $document): void {
            $item->documents()->detach($document->getKey());

            $item->warkah()->firstOrFail()->recalculateCompleteness();
        });
    }
}
