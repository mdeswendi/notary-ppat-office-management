<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Updates the three things a person may change about themselves.
 *
 * `name`, `phone`, and `preferred_locale` — and nothing else, by construction:
 * this class reads only those three keys, so a field that slipped past
 * validation still could not be written (D-066).
 *
 * Deliberately **not** routed through `UserPolicy`. Administrative update
 * excludes the `OWN` scope on purpose (D-049): editing your own administrative
 * record is self-service, not administration, and bending the policy to fit
 * would weaken the rule it exists to state. The boundary here is simply that the
 * target is the authenticated user.
 *
 * Touches no pivot. Role memberships, direct permissions, Data Scope metadata,
 * and permission overrides are untouched, and tests assert all four across a
 * profile save — changing your display name must never change what you can do.
 */
class UpdateProfile
{
    /**
     * @param  array{name?: string, phone?: ?string, preferred_locale?: string}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            foreach (['name', 'phone', 'preferred_locale'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $user->{$field} = $attributes[$field];
                }
            }

            $user->save();

            return $user->load('office');
        });
    }
}
