<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates the administrative fields of a user account.
 *
 * Exactly four: name, email, phone, and Office. Everything else a user record
 * carries belongs to somebody else's decision —
 *
 *   password, email_verified_at    account security, M1.9
 *   preferred_locale               the person's own preference, M1.8
 *   is_active                      a deliberate security action with its own
 *                                  capability and its own endpoint
 *   roles, permissions, overrides  authorization assignment, M1.6
 *   last_login_at                  written by the application, never by a form
 *
 * — and none of them can arrive here, because the Form Request does not accept
 * them and this action reads only the four keys it names.
 *
 * Role memberships, direct permissions, and permission overrides are untouched
 * by construction: nothing in this class writes to a pivot. Tests assert the
 * three tables byte-for-byte across an update.
 */
class UpdateUser
{
    /**
     * @param  array{name?: string, email?: string, phone?: ?string, office_id?: string}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            foreach (['name', 'email', 'phone'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $user->{$field} = $attributes[$field];
                }
            }

            // Assigned explicitly, never mass assigned: moving somebody between
            // Offices changes what they can reach.
            if (array_key_exists('office_id', $attributes)) {
                $user->office_id = $attributes['office_id'];
            }

            $user->save();

            return $user->load('office');
        });
    }
}
