<?php

namespace App\Domains\Project\Actions;

use App\Models\ProjectParty;
use Illuminate\Support\Facades\DB;

/**
 * Correct a participation's relationship metadata (M3.4, D-098).
 *
 * **Three fields, and only three**: `role_code`, `is_primary`, `notes`. The
 * endpoints — `project_id`, `party_id`, `office_id` — are not merely omitted from
 * the request shape, they are withheld from mass assignment on the model, so no
 * future caller can route around this by passing a wider array.
 *
 * Re-pointing a participation at a different Party is not an edit. It is a
 * different relationship, and allowing it would let one row silently become
 * another while keeping the `created_at` and `created_by` of the first. Remove
 * and add instead — two explicit acts, each of which the actor is authorized for.
 *
 * `created_by` is likewise untouched: it records who linked this Party, which
 * stays true after somebody else corrects the note. There is no `updated_by`
 * column to write and no `updated_at` to bump — this table is current working
 * state, not a ledger, and adding either would be the first half of a history
 * mechanism nothing else honours.
 *
 * The transaction wraps a single write, which is not strictly required. It is
 * here for consistency with the rest of the domain: every Action that mutates is
 * transactional, so nobody has to check which ones are.
 *
 * **No actor parameter**, unlike the other Project Actions. There is no
 * `updated_by` column for it to write, and accepting one anyway would suggest
 * this Action records who made the correction when it records nothing of the
 * kind. Authorization has already happened in the Policy.
 */
class UpdateProjectParty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(ProjectParty $participation, array $attributes): ProjectParty
    {
        return DB::transaction(function () use ($participation, $attributes): ProjectParty {
            $participation->fill($attributes);
            $participation->save();

            return $participation;
        });
    }
}
