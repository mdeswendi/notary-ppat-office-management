<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Models\Matter;
use App\Models\PpatDeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record a new PPAT Deed against a Matter (M7.2, D-121).
 *
 * **Office is inherited from the Matter and never accepted from the caller** — the
 * composite key `(matter_id, office_id)` permits nothing else, and letting a request
 * name an Office would be letting it choose which records the deed can reference.
 * The M4.4 rule for Matter inheriting from Project, one level down.
 *
 * **Status is always `DRAFT`.** A caller cannot create a deed that is already
 * approved: each later status answers to its own capability, and accepting one here
 * would make `ppat.deeds.create` a silent superset of three other codes.
 *
 * **`deed_number` is not accepted at creation and is not generated.** It answers to
 * `ppat.deeds.number` on its own endpoint (D-121). Accepting it here would fold a
 * separate capability into this one; generating it would invent the numbering rule
 * `09_PPAT_WORKFLOW.md` section 6 asks about.
 *
 * A transaction because the insert must either happen whole or not at all — the deed
 * carries six references that the composite keys check together.
 */
class CreatePpatDeed
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, Matter $matter, array $attributes): PpatDeed
    {
        return DB::transaction(function () use ($matter, $attributes): PpatDeed {
            $deed = new PpatDeed;

            $deed->fill($attributes);

            // Identity, decided here rather than by the caller.
            $deed->matter_id = $matter->getKey();
            $deed->office_id = $matter->office_id;
            $deed->status = PpatDeedStatus::DRAFT;

            $deed->save();

            return $deed;
        });
    }
}
