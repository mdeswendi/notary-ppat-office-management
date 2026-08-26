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
 * Approve a Deed (M7.2, D-121).
 *
 * `UNDER_REVIEW` → `APPROVED`, stamping the pair.
 *
 * **Who may do this is decided by `ppat.deeds.approve` and by nothing else.** The
 * M7.2 brief specified *"hanya PRINCIPAL/SUPER_ADMIN"*; that is a role-name
 * authorization, which D-032, D-041 and D-048 forbid as a mechanism — and it would
 * remain forbidden even after a domain source answers *"which stages require
 * Principal approval?"* (open question three). Restricting approval to the Principal
 * is achieved by granting the capability to that role and no other, which is office
 * configuration an administrator makes through the Permission Matrix. No role name
 * appears anywhere in this domain.
 *
 * **Approval does not lock the record and does not number it.** Locking is
 * `locked_at`, which nothing writes (D-121); numbering answers to
 * `ppat.deeds.number` on its own endpoint.
 */
class ApprovePpatDeed
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, PpatDeed $deed): PpatDeed
    {
        return DB::transaction(function () use ($actor, $deed): PpatDeed {
            if (! $deed->status->isApprovable()) {
                throw new DeedStatusNotEligible($deed->status, 'approved');
            }

            $from = $deed->status->value;

            $deed->status = PpatDeedStatus::APPROVED;
            $deed->approved_at = Date::now();
            $deed->approved_by = $actor->getKey();
            $deed->save();

            $this->events->statusChanged(
                $deed,
                $actor,
                $from,
                $deed->status->value,
                ActivityType::DEED_APPROVED,
                ['reference' => $deed->deed_number, 'title' => $deed->title],
            );

            return $deed;
        });
    }
}
