<?php

namespace App\Domains\Party\Actions;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archive a Company by archiving its Party root.
 *
 * The aggregate root is the lifecycle authority (D-081), so this sets
 * `parties.deleted_at` and touches neither the subtype nor its relationships.
 * The Company row remains as historical data attached to an archived root — a
 * live Party with an archived subtype, or the reverse, is a state nothing in the
 * product could render honestly.
 *
 * **`company_people` rows are left exactly as they are.** Deleting them would
 * destroy the relationship history D-083 exists to preserve, and M2.3 owns no
 * relationship behaviour to reason about it with. An archived company keeps its
 * record of who was a director and when.
 *
 * Not a deletion. Party-domain records are master data that legal records will
 * eventually reference, so there is no hard-delete path and no restore: the
 * registry defines `companies.archive` and no restore permission, and inventing
 * one is out of scope.
 */
class ArchiveCompany
{
    public function handle(User $actor, Company $company): void
    {
        DB::transaction(function () use ($actor, $company): void {
            $party = $company->party;

            $party->updated_by = $actor->getKey();
            $party->save();

            $party->delete();
        });
    }
}
