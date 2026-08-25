<?php

namespace App\Domains\Ppat\Actions;

use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Take a line off a Warkah (M7.4, D-121).
 *
 * ## This is a hard delete, and the interface says so
 *
 * The M7.4 brief described it as a soft delete. **`ppat_warkah_items` has no
 * `deleted_at`** — `03_DATABASE_ERD.md` section 19 gives the table ten columns and
 * none of them is one, so M7.1 added no `SoftDeletes`. There is no column to write.
 *
 * Unlike a link in a chain of title, that is not a loss `CLAUDE.md` section 63
 * protects. A Warkah item is **a line the office typed on its own checklist** — not
 * ownership history, not a document version, not deed state, not an audit record.
 * Removing one it added in error is composing the list, which is what
 * `ppat.warkah.update` authorizes. The same reading `matter_parties` and
 * `matter_properties` already ship with, both of which delete their row outright.
 *
 * **What survives is what matters.** The Documents themselves are untouched: only the
 * junction rows cascade, and those record the office's assertion that this file
 * satisfied this line. The files stay in `documents`, reachable through
 * `documents.view` exactly as before, and every other line of the bundle is
 * unaffected.
 *
 * The confirmation wording says the assertion is withdrawn rather than implying an
 * undo the product does not have.
 *
 * ## Completeness is recomputed
 *
 * Both terms move. A line with no document raises the percentage when it goes; a line
 * with one lowers it. The arithmetic is doing what it says — the office's own
 * checklist got shorter.
 *
 * The Warkah is loaded before the delete, because the item is the only route to it
 * afterwards.
 */
class RemoveWarkahItem
{
    public function handle(User $actor, PpatWarkahItem $item): PpatWarkah
    {
        return DB::transaction(function () use ($item): PpatWarkah {
            $warkah = $item->warkah()->firstOrFail();

            // `ppat_warkah_documents` cascades from the item — the assertion goes,
            // the Documents do not.
            $item->delete();

            $warkah->recalculateCompleteness();

            return $warkah;
        });
    }
}
