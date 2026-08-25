<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\Exceptions\DeedStatusNotEligible;
use App\Models\PpatDeed;
use App\Models\User;
use App\Policies\PpatDeedPolicy;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Finalize a Deed (M7.2, D-121).
 *
 * `APPROVED` → `FINALIZED`, stamping the pair. After this the record is read-only:
 * `CLAUDE.md` section 29 denies normal updates once finalized, and section 64
 * requires the original values be preserved. {@see PpatDeed::isReadOnly()} and
 * {@see PpatDeedPolicy::update()} both honour it, so no interface
 * offers an edit control that cannot work.
 *
 * ## Three things the brief asked for that this deliberately does not do
 *
 * **It does not assign a deed number.** *"Set deed_number jika belum"* asserts that
 * numbering happens at finalization, which is half of open question five — *"what are
 * the deed numbering rules, and who assigns the number?"* `ppat.deeds.number` is its
 * own canonical capability precisely so the office decides when to use it, and
 * folding it in here would answer a question `CLAUDE.md` section 62 forbids
 * answering. A deed may be finalized with no number, and one may be numbered before
 * it is ever reviewed.
 *
 * **It does not create a register entry.** `ppat_matters.registration_required` is
 * stored and branches on nothing (M7.1): the register format and its finalization
 * period are open question six, and `ppat_register_entries` does not exist — batch
 * 11 per `03_DATABASE_ERD.md` section 32, a batch after this one (O-042).
 *
 * **It does not touch taxes either**, which is the PPAT-specific half of the same
 * refusal. `ppat_matters.tax_processing_required` branches on nothing and
 * `ppat_tax_records` is unbuilt: it has **no canonical capability at all** (O-040).
 *
 * **It does not write `locked_at`.** Who locks a deed and under what conditions is
 * the correction-mechanism question (open question nine), and there is no
 * `ppat.deeds.lock` capability. The column stays canonical vocabulary nothing writes.
 *
 * A transaction, because `CLAUDE.md` section 37 requires finalization be
 * transaction-safe — even though this version touches a single row, so that the
 * milestone which adds register allocation inherits the boundary rather than having
 * to introduce one.
 */
class FinalizePpatDeed
{
    public function handle(User $actor, PpatDeed $deed): PpatDeed
    {
        return DB::transaction(function () use ($actor, $deed): PpatDeed {
            if (! $deed->status->isFinalizable()) {
                throw new DeedStatusNotEligible($deed->status, 'finalized');
            }

            $deed->status = PpatDeedStatus::FINALIZED;
            $deed->finalized_at = Date::now();
            $deed->finalized_by = $actor->getKey();
            $deed->save();

            return $deed;
        });
    }
}
