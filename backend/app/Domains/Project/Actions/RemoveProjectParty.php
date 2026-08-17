<?php

namespace App\Domains\Project\Actions;

use App\Models\ProjectParty;
use Illuminate\Support\Facades\DB;

/**
 * Unlink a Party from a Project (M3.4, D-098).
 *
 * **Hard deletion of the relationship row, and nothing else.** The Project is
 * untouched. The Party is untouched — not archived, not soft-deleted, not
 * altered in any way. Both are records in their own right that happened to be
 * associated; ending the association says nothing about either.
 *
 * **This does not preserve history, and does not pretend to.** `project_parties`
 * is current working state: it has no `deleted_at`, no `effective_until`, and no
 * `updated_at`, because `03_DATABASE_ERD.md` section 7 gives it none. A soft
 * delete added here would create a half-history — rows nobody lists, no
 * mechanism to read them, and a claim in the schema that participation is
 * preserved when no surface preserves it. `company_people` keeps history because
 * deeds depend on who was a director in March (D-083); nothing yet depends on
 * who was listed on a Project last Tuesday, and inventing the mechanism before
 * the requirement would be building for an imagined caller.
 *
 * If Project participation history is later required, it needs its own decision
 * and its own columns — not a `deleted_at` added quietly here.
 */
class RemoveProjectParty
{
    public function handle(ProjectParty $participation): void
    {
        DB::transaction(function () use ($participation): void {
            $participation->delete();
        });
    }
}
