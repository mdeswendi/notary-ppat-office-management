<?php

namespace App\Domains\Ppat\Actions;

use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use Illuminate\Support\Facades\DB;

/**
 * Materialise a deed's Warkah, or return the one it already has (M7.4, D-121).
 *
 * ## Why a bundle is started rather than created
 *
 * **There is no `ppat.warkah.create` in the catalogue** — six codes, and `create` is
 * not among them. The reading M7.3 applied to `properties.ownership.update`, which has
 * no `create` beside it either, applies here: **composing the bundle is what brings it
 * into existence**, so every act that writes to a Warkah calls this first and the row
 * appears on the first line somebody adds or the first status somebody sets.
 *
 * That is why this is not an endpoint. There is no `POST /warkah`; there is
 * `POST /warkah/items` and `PATCH /warkah/status`, and both start the bundle if it is
 * absent.
 *
 * ## Reading never starts one
 *
 * `GET /warkah` answers **404** while no bundle exists, and does not create one. The
 * M7.4 brief asked for the opposite — *"create if not exists"* on the read endpoint —
 * and it is refused for a reason that outlives this milestone: `ppat.warkah.view`
 * names reading, and a capability that silently writes is a capability nobody can
 * reason about. It would also mean a read-only actor's page load inserts a row, and
 * that every PPAT deed anyone ever opened acquires a bundle it never needed.
 *
 * The 404 is the M6.3 convention for exactly this shape — *"one of two things the
 * caller cannot tell apart, which is by design: nothing filed, or a deed the caller
 * may not reach."*
 *
 * ## The unique index is the concurrency answer
 *
 * `ppat_warkah` is `UNIQUE (ppat_deed_id)` — one bundle per deed (lock section 8.3).
 * `firstOrCreate` inside a transaction leans on that index rather than on a
 * read-then-write, so two simultaneous callers cannot both insert.
 *
 * **Status and completeness take their database defaults**: `INCOMPLETE` and `0`. A
 * bundle nobody has listed anything for has collected nothing, and 0% is the honest
 * figure — `PpatWarkah::computeCompleteness()` says so at length.
 */
class StartWarkah
{
    public function handle(PpatDeed $deed): PpatWarkah
    {
        return DB::transaction(function () use ($deed): PpatWarkah {
            $existing = PpatWarkah::query()->where('ppat_deed_id', $deed->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $warkah = new PpatWarkah;

            // Structural, and immutable afterwards: `PpatWarkah::booted()` refuses a
            // change to either, because re-pointing a bundle would file one
            // transaction's evidence under another.
            $warkah->ppat_deed_id = $deed->getKey();
            $warkah->office_id = $deed->office_id;

            $warkah->save();

            return $warkah;
        });
    }
}
