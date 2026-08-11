<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\StartAuthenticatedSession;
use App\Domains\Identity\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
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
    public function __construct(
        private readonly TwoFactorChallenge $challenge,
        private readonly StartAuthenticatedSession $startSession,
    ) {}

    /**
     * Log in and start an authenticated session.
     *
     * Two outcomes. Without two-factor, the password is enough and a session
     * starts. With it, the response says a second factor is required and **no
     * session is created** — `two_factor: true` is a statement about what must
     * happen next, not a half-open door (D-075).
     *
     * The success path returns no body: the identity contract lives at
     * `GET /api/v1/me`, which the SPA calls next. Keeping one shape for the
     * current user avoids two places that must agree.
     */
    public function store(LoginRequest $request): Response|JsonResponse
    {
        $user = $request->authenticate();

        if ($user->hasConfirmedTwoFactor()) {
            $this->challenge->begin($request, $user, $request->boolean('remember'));

            return response()->json(['two_factor' => true], 202);
        }

        $this->startSession->handle($request, $user, $request->boolean('remember'));

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
