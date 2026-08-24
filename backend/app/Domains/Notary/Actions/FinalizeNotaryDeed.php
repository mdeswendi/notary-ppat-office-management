<?php

namespace App\Domains\Notary\Actions;

use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\Exceptions\DeedStatusNotEligible;
use App\Models\NotaryDeed;
use App\Models\User;
use App\Policies\NotaryDeedPolicy;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Finalize a Deed (M6.2, D-120).
 *
 * `APPROVED` → `FINALIZED`, stamping the pair. After this the record is read-only:
 * `CLAUDE.md` section 29 denies normal updates once finalized, and section 64
 * requires the original values be preserved. {@see NotaryDeed::isReadOnly()} and
 * {@see NotaryDeedPolicy::update()} both honour it, so no interface
 * offers an edit control that cannot work.
 *
 * ## Three things the brief asked for that this deliberately does not do
 *
 * **It does not assign a deed number.** *"Set deed_number jika belum"* asserts that
 * numbering happens at finalization, which is half of open question one — *"what are
 * the deed numbering rules, and who assigns the number?"* `notary.deeds.number` is
 * its own canonical capability precisely so the office decides when to use it, and
 * folding it in here would answer a question `CLAUDE.md` section 62 forbids
 * answering. A deed may be finalized with no number, and one with a number may be
 * numbered before it is ever reviewed.
 *
 * **It does not create a Repertorium entry.** `notary_matters.requires_register_entry`
 * is stored and branches on nothing (M6.1): the register procedure is open question
 * two, and `notary_register_entries` does not exist — batch 11 per
 * `03_DATABASE_ERD.md` section 32, two batches after this one.
 *
 * **It does not write `locked_at`.** The ERD's ladder ends at a locked state, but who
 * locks a deed and under what conditions is the correction-mechanism question, and
 * there is no `notary.deeds.lock` capability. The column stays canonical vocabulary
 * nothing writes.
 *
 * A transaction, because `CLAUDE.md` section 37 requires finalization be
 * transaction-safe — even though M6.2's version is a single row, so that the
 * milestone which adds register allocation inherits the boundary rather than having
 * to introduce one.
 */
class FinalizeNotaryDeed
{
    public function handle(User $actor, NotaryDeed $deed): NotaryDeed
    {
        return DB::transaction(function () use ($actor, $deed): NotaryDeed {
            if (! $deed->status->isFinalizable()) {
                throw new DeedStatusNotEligible($deed->status, 'finalized');
            }

            $deed->status = NotaryDeedStatus::FINALIZED;
            $deed->finalized_at = Date::now();
            $deed->finalized_by = $actor->getKey();
            $deed->save();

            return $deed;
        });
    }
}
