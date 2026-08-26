<?php

namespace App\Domains\Notary\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\Exceptions\DeedStatusNotEligible;
use App\Models\NotaryDeed;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Submit a Deed for review (M6.2, D-120).
 *
 * `DRAFT` → `UNDER_REVIEW`, stamping the pair. Anything else is 422 — submitting a
 * deed that is already under review, or one that has been approved, is not an act
 * the office performs.
 *
 * **The pair is written together**, which a PostgreSQL CHECK and a model guard both
 * require: half of an act is a row nobody can explain.
 *
 * **No `reason` is accepted.** The brief asked for an optional one on this endpoint
 * and on approve and finalize. There is nowhere to put it — `notary_deeds` has no
 * reason column, the ERD gives it none, and no audit store exists (D-115). A field
 * accepted and silently discarded is worse than one never offered, because it looks
 * like a record somebody could later retrieve.
 */
class ReviewNotaryDeed
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, NotaryDeed $deed): NotaryDeed
    {
        return DB::transaction(function () use ($actor, $deed): NotaryDeed {
            if (! $deed->status->isReviewable()) {
                throw new DeedStatusNotEligible($deed->status, 'submitted for review');
            }

            $from = $deed->status->value;

            $deed->status = NotaryDeedStatus::UNDER_REVIEW;
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
