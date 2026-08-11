<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\SessionRegistry;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Finish a password reset with the token from the emailed link.
 *
 * Laravel's password broker does the token work — lookup, hash comparison,
 * expiry, and single-use deletion. Writing that by hand would mean writing
 * token cryptography, which D-071 forbids for good reason.
 *
 * On success:
 *
 * - every session for that account is revoked, including any the attacker who
 *   prompted the reset may hold (D-072);
 * - **no session is created**. The user signs in again, which means an account
 *   with two-factor still faces its second factor. Auto-login here would turn a
 *   single emailed link into a complete bypass of MFA;
 * - roles, permissions, Office, profile, locale, and the entire two-factor
 *   configuration are preserved. A password reset is not an account reset.
 *
 * An invalid, expired, already-used, or mismatched token fails closed and
 * changes nothing.
 */
class CompletePasswordReset
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $credentials
     * @return string The broker status constant.
     */
    public function handle(array $credentials): string
    {
        return Password::broker()->reset($credentials, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->password = $password;
                $user->setRememberToken(Str::random(60));
                $user->save();

                // Nothing spared: the person completing this is not currently
                // signed in, so every live session belongs to a device that has
                // not proved anything.
                $this->sessions->revokeAll($user);
            });

            event(new PasswordReset($user));

            Log::info('PASSWORD_RESET_COMPLETED', ['user_id' => $user->getKey()]);
        });
    }
}
