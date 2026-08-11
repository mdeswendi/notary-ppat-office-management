<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Begin an authenticated session for a user whose credentials are already
 * proven.
 *
 * Shared by the single-step login and the two-factor challenge, so both
 * regenerate the session id and stamp `last_login_at` in exactly the same way. A
 * login that skipped session regeneration on only one of its two routes would be
 * a session-fixation hole nobody would spot, because the common path would look
 * correct.
 *
 * This action proves nothing itself. Callers must have verified the password —
 * and, where the account requires it, the second factor — before reaching here.
 */
class StartAuthenticatedSession
{
    public function handle(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);

        // Prevents session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();
    }
}
