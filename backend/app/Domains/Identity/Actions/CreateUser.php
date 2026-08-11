<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates one user account and nothing else.
 *
 * The new account holds **zero roles, zero direct permissions, and zero
 * permission overrides**. Capability is granted separately in M1.6, and granting
 * anything here would mean inventing a default that no document defines.
 *
 * `office_id` and `is_active` are assigned explicitly rather than mass assigned:
 * both are administrative decisions, and neither may ever arrive from request
 * input by accident (docs/07_SECURITY_RULES.md section 34). The account starts
 * active — an account created inactive would be a puzzle rather than a feature.
 *
 * The initial password is hashed by the model's `hashed` cast, so no plaintext
 * is ever written, returned, or logged (D-051).
 */
class CreateUser
{
    /**
     * @param  array{name: string, email: string, phone: ?string, office_id: string, password: string}  $attributes
     */
    public function handle(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $user = new User;

            $user->name = $attributes['name'];
            $user->email = $attributes['email'];
            $user->phone = $attributes['phone'] ?? null;
            $user->password = $attributes['password'];
            $user->office_id = $attributes['office_id'];
            $user->is_active = true;

            $user->save();

            return $user->load('office');
        });
    }
}
