<?php

namespace App\Domains\Ppat\Actions;

use App\Models\Matter;
use App\Models\Party;
use App\Models\Property;
use App\Models\PropertyOwner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add a link to a chain of title (M7.3, D-121).
 *
 * ## Two different acts, one endpoint, and an explicit flag between them
 *
 * The M7.3 brief specified *"tambah owner baru (set `is_current` = true, update yang
 * lama)"* and, in its constraints, *"hanya satu owner yang bisa `is_current` = true per
 * property."* **That is the one thing the M7 lock rules out by name.** Section 7.2:
 *
 * > *"a Property legitimately has **several** current owners at once, each with an
 * > `ownership_percentage`. `is_current` on `property_owners` is a 'this row applies
 * > now' flag on many rows, not a 'this is the one' pointer on one."*
 *
 * The migration says the same, the model says it, and an M7.1 test asserts two current
 * owners at 50% each. Closing the previous holders on every insert would make
 * co-ownership unrepresentable — and co-ownership is ordinary for Indonesian land.
 *
 * So the caller states which act this is:
 *
 * ```text
 * supersedes_current = false   add a co-owner beside the existing ones   (default)
 * supersedes_current = true    record a transfer: close the current links first
 * ```
 *
 * **False is the default**, because it is the choice that ends nobody's recorded
 * ownership. A wrong `true` silently writes an end date onto somebody's title; a wrong
 * `false` leaves a list an office can see is wrong and fix by closing a link.
 *
 * ## Superseding closes; it never rewrites
 *
 * Closing a link stamps `effective_until` and clears `is_current` **on the previous
 * rows** and inserts a new one. It does not touch their party or percentage —
 * `CLAUDE.md` section 63, and `PropertyOwner` refuses those changes outright. The
 * closing date is the new link's `effective_from`, so the chain has no gap and no
 * overlap.
 *
 * A row whose `effective_from` is on or after the new link's start is left alone: it
 * has not begun before the transfer, so ending it *at* the transfer would write a
 * period that runs backwards, which the model refuses.
 *
 * ## `source_matter_id` is the transfer that produced this row
 *
 * The lock calls it *"the audit trail the ownership history exists for"*. It is
 * optional, because an office records the ownership it inherits with the file, which
 * predates every Matter in this system. The controller resolves it through canonical
 * Matter visibility before this runs, so recording a transfer never becomes a way to
 * discover which Matters exist.
 *
 * ## No sum is enforced
 *
 * Whether co-owners' shares must total 100 is a rule about Indonesian co-ownership and
 * `CLAUDE.md` section 62 forbids inventing it. Each row is 0–100 — arithmetic — and the
 * total is whatever the office recorded. The interface shows the total and does not
 * judge it.
 */
class AddPropertyOwner
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        Property $property,
        Party $party,
        array $attributes,
        bool $supersedesCurrent = false,
        ?Matter $sourceMatter = null,
    ): PropertyOwner {
        return DB::transaction(function () use (
            $property,
            $party,
            $attributes,
            $supersedesCurrent,
            $sourceMatter
        ): PropertyOwner {
            $link = new PropertyOwner;

            $link->fill($attributes);

            // Structural, not fillable: `PropertyOwner` refuses every later change to
            // all three, because a chain of title is corrected by closing a link and
            // adding another (M7.1, D-121).
            $link->property_id = $property->getKey();
            $link->party_id = $party->getKey();
            $link->office_id = $property->office_id;
            $link->source_matter_id = $sourceMatter?->getKey();

            $link->is_current = (bool) ($attributes['is_current'] ?? true);

            if ($supersedesCurrent) {
                $property->owners()
                    ->where('is_current', true)
                    // A link that starts on or after the transfer has not begun
                    // before it; ending it here would write a backwards period.
                    ->whereDate('effective_from', '<', $link->effective_from)
                    ->get()
                    ->each(function (PropertyOwner $previous) use ($link): void {
                        // Stamped together in one save, so `is_current` and
                        // `effective_until` cannot disagree — the denormalization
                        // hazard the M7 lock named when it kept the column.
                        $previous->effective_until = $link->effective_from;
                        $previous->is_current = false;
                        $previous->save();
                    });
            }

            $link->save();

            return $link;
        });
    }
}
