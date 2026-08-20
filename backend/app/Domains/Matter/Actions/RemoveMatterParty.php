<?php

namespace App\Domains\Matter\Actions;

use App\Models\MatterParty;
use Illuminate\Support\Facades\DB;

/**
 * Unlink a Party from a Matter (M4.5, D-105).
 *
 * **Hard deletion of the relationship row, and nothing else.** The Matter is
 * untouched — not cancelled, not reopened, not renumbered. The Party is
 * untouched — not archived, not soft-deleted, not altered in any way. Both are
 * records in their own right that happened to be associated; ending the
 * association says nothing about either.
 *
 * **This does not preserve history, and does not pretend to.** `matter_parties`
 * is current working state: no `deleted_at`, no `effective_until`, because
 * D-105 gives it none. A soft delete added here would create a half-history —
 * rows nobody lists, no mechanism to read them, and a claim in the schema that
 * participation is preserved when no surface preserves it. `company_people`
 * keeps history because deeds executed in March depend on who was a director in
 * March (D-083); nothing yet depends on who was listed on a Matter last Tuesday,
 * and inventing the mechanism before the requirement would be building for an
 * imagined caller.
 *
 * If Matter participation history is later required — and a deed drafted from a
 * participant list is the obvious way it becomes required — it needs its own
 * decision and its own columns, not a `deleted_at` added quietly here.
 */
class RemoveMatterParty
{
    public function handle(MatterParty $participation): void
    {
        DB::transaction(function () use ($participation): void {
            $participation->delete();
        });
    }
}
