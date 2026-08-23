<?php

namespace App\Domains\Matter\Actions;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Put somebody in charge of a Matter, or clear the assignment (M4.4, D-109).
 *
 * **The person in charge must be an active user of the Matter's own Office**, and
 * this is checked here as well as in the Form Request. The duplication is
 * deliberate defence in depth, and the reason is not tidiness: `ASSIGNED` grants
 * reach when `matter.pic_user_id == actor.id` (D-100), so a cross-office
 * assignment would hand somebody reach over a Matter their own scope never
 * included — a privilege grant performed through a work allocation, with no role
 * changing and nothing in the authorization surfaces to show for it.
 *
 * **The restriction holds even when the acting administrator holds `ALL`.** `ALL`
 * is the *actor's* reach over existing records; it says nothing about who may be
 * given work. That is the same line D-097 drew for Project and D-107 drew for
 * Matter creation.
 *
 * `null` clears the assignment, which is a legitimate act rather than an error.
 *
 * Matter PIC and Project PIC are unrelated: assigning one never writes the other,
 * and neither widens the other's `ASSIGNED` reach (D-100).
 */
class AssignMatterPic
{
    public function handle(User $actor, Matter $matter, ?string $picUserId): Matter
    {
        return DB::transaction(function () use ($actor, $matter, $picUserId): Matter {
            if ($picUserId !== null) {
                $eligible = User::query()
                    ->whereKey($picUserId)
                    ->where('office_id', $matter->office_id)
                    ->where('is_active', true)
                    ->exists();

                if (! $eligible) {
                    throw new RuntimeException(
                        'The person in charge of a Matter must be an active user of that '
                        .'Matter\'s own Office (D-109). A cross-office assignment would grant '
                        .'ASSIGNED reach the recipient\'s own scope never included.'
                    );
                }
            }

            $matter->pic_user_id = $picUserId;
            $matter->updated_by = $actor->getKey();
            $matter->save();

            return $matter->refresh();
        });
    }
}
