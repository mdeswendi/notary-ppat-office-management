<?php

namespace App\Domains\Notary\Actions;

use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\Exceptions\DeedStatusNotEligible;
use App\Models\NotaryDeed;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Approve a Deed (M6.2, D-120).
 *
 * `UNDER_REVIEW` → `APPROVED`, stamping the pair.
 *
 * **Who may do this is decided by `notary.deeds.approve` and by nothing else.** The
 * M6.2 brief specified *"hanya PRINCIPAL/SUPER_ADMIN"*; that is a role-name
 * authorization, which D-032, D-041 and D-048 forbid as a mechanism — and it would
 * remain forbidden even after a domain source answers *"which stages require
 * Principal approval?"* (open question three). Restricting approval to the Principal
 * is achieved by granting the capability to that role and no other, which is office
 * configuration an administrator makes through the Permission Matrix. No role name
 * appears anywhere in this domain.
 *
 * **Approval does not lock the record and does not number it.** Locking is
 * `locked_at`, which nothing writes (D-120); numbering answers to
 * `notary.deeds.number` on its own endpoint.
 */
class ApproveNotaryDeed
{
    public function handle(User $actor, NotaryDeed $deed): NotaryDeed
    {
        if (! $deed->status->isApprovable()) {
            throw new DeedStatusNotEligible($deed->status, 'approved');
        }

        $deed->status = NotaryDeedStatus::APPROVED;
        $deed->approved_at = Date::now();
        $deed->approved_by = $actor->getKey();
        $deed->save();

        return $deed;
    }
}
