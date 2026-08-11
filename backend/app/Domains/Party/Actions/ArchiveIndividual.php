<?php

namespace App\Domains\Party\Actions;

use App\Models\Individual;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archive an Individual by archiving its Party root.
 *
 * The aggregate root is the lifecycle authority (D-081), so this sets
 * `parties.deleted_at` and touches the subtype not at all. The Individual row
 * remains as historical data attached to an archived root — a live Party with an
 * archived subtype, or the reverse, is a state nothing in the product could
 * render honestly.
 *
 * Not a deletion. Party-domain records are master data that legal records will
 * eventually reference, so there is no hard-delete path and no restore: the
 * registry defines `parties.archive` and no restore permission, and inventing one
 * is out of scope.
 */
class ArchiveIndividual
{
    public function handle(User $actor, Individual $individual): void
    {
        DB::transaction(function () use ($actor, $individual): void {
            $party = $individual->party;

            $party->updated_by = $actor->getKey();
            $party->save();

            $party->delete();
        });
    }
}
