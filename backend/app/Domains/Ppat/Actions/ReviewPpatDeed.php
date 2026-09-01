<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\Exceptions\DeedStatusNotEligible;
use App\Models\PpatDeed;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Submit a Deed for review (M7.2, D-121).
 *
 * `DRAFT` → `UNDER_REVIEW`, stamping the pair. Anything else is 422 — submitting a
 * deed that is already under review, or one that has been approved, is not an act
 * the office performs.
 *
 * **The pair is written together**, which a PostgreSQL CHECK and a model guard both
 * require: half of an act is a row nobody can explain.
 *
 * **No `reason` is accepted.** The brief asked for an optional one on this endpoint
 * and on approve and finalize. There is nowhere on the record to put it: `ppat_deeds`
 * has no reason column and the ERD gives it none. A field accepted and silently
 * discarded is worse than one never offered, because it looks like a record somebody
 * could later retrieve.
 *
 * **One of the three original premises has since lapsed.** M7.2 also argued that no
 * audit store existed (D-115); M8.1 built one, and an audit row carries a `reason`
 * field. So a reviewer's reason now has somewhere it *could* live. Whether review
 * should write one is a decision nobody has taken — recorded here so a future reader
 * finds the open question rather than a settled-looking argument that has quietly
 * lost a leg.
 */
class ReviewPpatDeed
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, PpatDeed $deed): PpatDeed
    {
        return DB::transaction(function () use ($actor, $deed): PpatDeed {
            if (! $deed->status->isReviewable()) {
                throw new DeedStatusNotEligible($deed->status, 'submitted for review');
            }

            $from = $deed->status->value;

            $deed->status = PpatDeedStatus::UNDER_REVIEW;
            $deed->reviewed_at = Date::now();
            $deed->reviewed_by = $actor->getKey();
            $deed->save();

            $this->events->statusChanged(
                $deed,
                $actor,
                $from,
                $deed->status->value,
                ActivityType::DEED_REVIEWED,
                ['reference' => $deed->deed_number, 'title' => $deed->title],
            );

            return $deed;
        });
    }
}
