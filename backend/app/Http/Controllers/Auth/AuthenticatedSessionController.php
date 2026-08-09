<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * First-party SPA session authentication.
 *
 * No API token is issued anywhere in this controller. The browser is
 * authenticated by Laravel's session cookie, per docs/07_SECURITY_RULES.md
 * section 3.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Log in and start an authenticated session.
     *
     * Returns no body: the identity contract lives at `GET /api/v1/me`, which
     * the SPA calls next. Keeping one shape for the current user avoids two
     * places that must agree.
     */
    public function store(LoginRequest $request): Response
    {
        $request->authenticate();

        // Prevents session fixation: the pre-login session id is discarded.
        $request->session()->regenerate();

        $request->user()->forceFill([
            'last_login_at' => now(),
        ])->save();

        return response()->noContent();
    }

    /**
     * Log out and discard the session.
     *
     * Deliberately idempotent — logging out twice is not an error — so it does
     * not sit behind the auth middleware. CSRF protection still applies.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
