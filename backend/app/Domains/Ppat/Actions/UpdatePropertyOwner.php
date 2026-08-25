<?php

namespace App\Domains\Ppat\Actions;

use App\Models\PropertyOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct or close one link in a chain of title (M7.3, D-121).
 *
 * ## This is also how a link is "removed", and there is no other way
 *
 * The M7.3 brief asked for `DELETE /properties/{property}/owners/{owner}` described as
 * a *"soft delete ownership"*. **`property_owners` has no `deleted_at`** — the ERD's
 * field list in section 16 does not carry one, unlike `properties`, so M7.1 did not
 * add `SoftDeletes` to this model. A `DELETE` here could only be a hard one, and a hard
 * delete of a link in a chain of title destroys exactly the history the table exists
 * to keep (`CLAUDE.md` sections 30 and 63, and the model's own docblock).
 *
 * So there is no delete route. Ending an ownership is **closing the link**: stamp
 * `effective_until`, clear `is_current`, leave the party and the percentage as they
 * were recorded. That is what a chain of title does when land changes hands, and the
 * closed row stays visible in the history for good.
 *
 * A row entered by mistake is a different problem — a **correction mechanism**, which
 * is the same open question that has no answer for deeds either (O-039). M7.3 does not
 * guess at one.
 *
 * ## What may change, and what the model refuses
 *
 * ```text
 * ownership_percentage   yes — a recorded share can be mistyped
 * effective_from         yes — so can a date
 * effective_until        yes — this is how a link is closed
 * is_current             yes — cleared when closing, set when reopening a mistake
 *
 * party_id               never — that is a different owner, so a different link
 * property_id            never — likewise a different chain
 * office_id              never — the security boundary
 * ```
 *
 * The three refusals are enforced by `PropertyOwner::booted()`, not by filtering here,
 * so no path can reach them.
 *
 * **`is_current` and `effective_until` are written together**, and the model refuses a
 * row that is current *and* ended. That guard is what keeps the denormalized flag and
 * the date from drifting apart — the hazard the M7 lock accepted when it kept the
 * column because the ERD names it.
 *
 * A transaction, so the pair lands atomically.
 */
class UpdatePropertyOwner
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, PropertyOwner $link, array $attributes): PropertyOwner
    {
        return DB::transaction(function () use ($link, $attributes): PropertyOwner {
            $link->fill($attributes);

            // Closing a link clears the flag even when the caller sent only a date,
            // so the two can never be saved in contradiction. The model would throw
            // otherwise, which is a correct but unhelpful way to say the same thing.
            if (array_key_exists('effective_until', $attributes)
                && $attributes['effective_until'] !== null
                && ! array_key_exists('is_current', $attributes)) {
                $link->is_current = false;
            }

            $link->save();

            return $link;
        });
    }
}
