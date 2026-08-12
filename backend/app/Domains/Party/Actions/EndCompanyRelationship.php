<?php

namespace App\Domains\Party\Actions;

use App\Models\CompanyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Close a relationship by recording when it ended.
 *
 * **This is not a deletion, and it is not an edit of the historical fact.** It
 * writes `effective_until` and nothing else: the Company, the Individual, and
 * the relationship type stay exactly as recorded, because "who was the director
 * in March" must remain answerable — deeds executed in March depend on the
 * answer (D-083).
 *
 * A relationship that already carries an end date is refused by the controller
 * before this runs. Ending twice is not idempotent housekeeping; the second call
 * is asking to change a recorded end date, which is an amendment, and M2.4
 * builds no amendment workflow.
 *
 * The end date is supplied by the caller, never defaulted to today. Inventing a
 * date on somebody's behalf would be inventing a legal fact about when an
 * appointment ceased.
 */
class EndCompanyRelationship
{
    public function handle(User $actor, CompanyPerson $relationship, string $effectiveUntil): CompanyPerson
    {
        return DB::transaction(function () use ($actor, $relationship, $effectiveUntil): CompanyPerson {
            $relationship->effective_until = $effectiveUntil;
            $relationship->updated_by = $actor->getKey();
            $relationship->save();

            return $relationship->fresh();
        });
    }
}
