<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Exceptions\EmailChangeUnavailable;
use App\Domains\Identity\SessionRegistry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Complete a pending email change.
 *
 * Every condition is rechecked here rather than trusted from when the request
 * was made: the token must match, the request must not have expired, a pending
 * request must still exist, and the address must **still** be unused. That last
 * one matters — somebody else may have claimed the address in the meantime, and
 * a unique-constraint violation at this point would be a 500 where a clear
 * refusal belongs.
 *
 * The token is compared with `hash_equals` against the stored digest, so
 * comparison time does not leak.
 *
 * On success the change is atomic: address replaced, `email_verified_at` set to
 * now (this is the moment it was proven), pending state cleared, and every other
 * session revoked — the address is a login identifier, so changing it is a
 * credential change (D-073).
 *
 * The session doing the verifying survives, so the person is not signed out for
 * completing a step they were asked to complete.
 */
class VerifyEmailChange
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * @throws EmailChangeUnavailable
     */
    public function handle(Request $request, User $user, string $rawToken): void
    {
        if ($user->pending_email === null || $user->pending_email_token === null) {
            throw EmailChangeUnavailable::notPending();
        }

        if (! hash_equals($user->pending_email_token, hash('sha256', $rawToken))) {
            throw EmailChangeUnavailable::invalidToken();
        }

        $requestedAt = $user->pending_email_requested_at;

        if ($requestedAt === null || $requestedAt->addMinutes(RequestEmailChange::TTL_MINUTES)->isPast()) {
            throw EmailChangeUnavailable::expired();
        }

        DB::transaction(function () use ($request, $user): void {
            // Rechecked inside the transaction: the address was free when the
            // request was made, which says nothing about now.
            $taken = User::withTrashed()
                ->where('email', $user->pending_email)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw EmailChangeUnavailable::addressTaken();
            }

            $user->email = $user->pending_email;
            $user->email_verified_at = now();
            $user->pending_email = null;
            $user->pending_email_token = null;
            $user->pending_email_requested_at = null;
            $user->save();

            $this->sessions->revokeAll(
                $user,
                $request->hasSession() ? $request->session()->getId() : null,
            );
        });

        Log::info('EMAIL_CHANGED', ['user_id' => $user->getKey()]);
    }
}
