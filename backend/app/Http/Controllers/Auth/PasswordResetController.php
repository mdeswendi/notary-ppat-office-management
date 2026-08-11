<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\CompletePasswordReset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ResetPasswordRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Completing a password reset from the emailed link.
 *
 * Unauthenticated by necessity — somebody who could sign in would use the
 * change-password endpoint instead. The token is therefore the only credential,
 * which is why the route is rate limited and the token is single-use.
 *
 * **No session is created on success.** The user is sent back to sign in
 * normally, so an account with two-factor still meets its second factor. Logging
 * them in here would turn one emailed link into a complete bypass of it
 * (D-072).
 *
 * Every failure — wrong token, expired token, unknown address — answers the same
 * way. Distinguishing them would make this endpoint an oracle for which
 * addresses have accounts.
 */
class PasswordResetController extends Controller
{
    public function store(ResetPasswordRequest $request, CompletePasswordReset $reset): Response
    {
        $status = $reset->handle($request->only('email', 'token', 'password', 'password_confirmation'));

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('This password reset link is invalid or has expired.'),
            ]);
        }

        // No body and no session: the interface sends the user to the login page.
        return response()->noContent();
    }
}
