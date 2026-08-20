<?php

namespace App\Domains\Matter\Actions;

use App\Models\MatterParty;
use Illuminate\Support\Facades\DB;

/**
 * Correct a participation's relationship metadata (M4.5, D-105).
 *
 * **Two fields, and only two**: `role_code` and `notes`. The endpoints —
 * `matter_id`, `party_id`, `office_id` — are not merely omitted from the request
 * shape, they are withheld from mass assignment on the model, so no future
 * caller can route around this by passing a wider array.
 *
 * Re-pointing a participation at a different Party is not an edit. It is a
 * different relationship, and allowing it would let one row silently become
 * another while keeping the `created_at` and `created_by` of the first, and
 * would bypass the candidate authorization the store path performs. Remove and
 * add instead — two explicit acts, each of which the actor is authorized for.
 *
 * `created_by` is untouched: it records who linked this Party, which stays true
 * after somebody else corrects the role. `updated_at` moves because
 * `03_DATABASE_ERD.md` section 9 gives this table one; there is no `updated_by`
 * counterpart to write, so the row records *when* a correction happened and not
 * *who* made it. Adding the missing half would be the first step of a ledger
 * this table declines to be (D-105).
 *
 * **No actor parameter.** There is no `updated_by` column for it to write, and
 * accepting one anyway would suggest this Action records who made the correction
 * when it records nothing of the kind. Authorization has already happened in the
 * Policy.
 */
class UpdateMatterParty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(MatterParty $participation, array $attributes): MatterParty
    {
        return DB::transaction(function () use ($participation, $attributes): MatterParty {
            $participation->fill($attributes);
            $participation->save();

            return $participation;
        });
    }
}
