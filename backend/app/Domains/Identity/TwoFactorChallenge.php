<?php

namespace App\Domains\Identity;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The half-finished login that sits between a correct password and a verified
 * second factor.
 *
 * A user with two-factor enabled is **never logged in by the password alone**.
 * `POST /login` validates the credentials, records the pending state here, and
 * returns without an authenticated session. Only the challenge endpoint creates
 * one. Logging the user in first and "requiring" the code afterwards would leave
 * a real session that any client ignoring the response could simply use
 * (D-075).
 *
 * The pending state lives in the session, so it is server-side, expires on its
 * own, and cannot be forged by the browser. It holds an id and a flag — never
 * the password, never the secret, never a code.
 *
 * A short window applies. An abandoned challenge should not stay resumable for
 * as long as the session cookie lives.
 */
class TwoFactorChallenge
{
    /**
     * How long the second factor may be supplied after the password.
     */
    public const TTL_MINUTES = 5;

    private const KEY = 'auth.two_factor';

    /**
     * Record that this user passed the password step, and nothing more.
     */
    public function begin(Request $request, User $user, bool $remember): void
    {
        // A fresh id before storing anything: the pre-login id must not be able
        // to carry the challenge, or a fixed cookie could inherit it.
        $request->session()->regenerate();

        $request->session()->put(self::KEY, [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The user awaiting a second factor, or null when there is no live
     * challenge.
     *
     * Re-reads the account rather than trusting anything cached. Between the two
     * requests an administrator may have disabled it, and a disabled account must
     * not be able to finish a login it started while still active.
     */
    public function pendingUser(Request $request): ?User
    {
        $state = $this->state($request);

        if ($state === null) {
            return null;
        }

        $user = User::query()
            ->where('is_active', true)
            ->find($state['user_id']);

        // Belt and braces: a user whose two-factor was removed mid-challenge has
        // nothing left to verify, so the challenge is void rather than passable.
        if ($user === null || ! $user->hasConfirmedTwoFactor()) {
            $this->clear($request);

            return null;
        }

        return $user;
    }

    public function shouldRemember(Request $request): bool
    {
        return (bool) ($this->state($request)['remember'] ?? false);
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::KEY);
    }

    /**
     * @return array{user_id: string, remember: bool, expires_at: int}|null
     */
    private function state(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var array{user_id: string, remember: bool, expires_at: int}|null $state */
        $state = $request->session()->get(self::KEY);

        if ($state === null) {
            return null;
        }

        if (now()->getTimestamp() > $state['expires_at']) {
            $this->clear($request);

            return null;
        }

        return $state;
    }
}
