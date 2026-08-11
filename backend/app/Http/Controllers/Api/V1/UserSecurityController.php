<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\DisableTwoFactor;
use App\Domains\Identity\Actions\SendPasswordResetLink;
use App\Domains\Identity\SessionRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Administering *another* user's account security.
 *
 * Three powers, each behind its own canonical permission and each resolved
 * through {@see UserPolicy} — never a role name, never a permission code checked
 * directly (D-048).
 *
 * What an administrator can do here is deliberately narrow. They can start a
 * reset, end sessions, and remove a second factor. What they cannot do, at all,
 * through any endpoint in this application:
 *
 * - set or read a password, or see a temporary one;
 * - receive or read a reset token;
 * - read or set a two-factor secret;
 * - read recovery codes, or issue them for somebody else;
 * - see a raw session identifier.
 *
 * The line is that an administrator may **restore access** to an account and may
 * **take access away**, but may never come to *hold* it. Someone who can silently
 * become another user can sign a deed as them, and in a Notary office that is
 * not a recoverable mistake (D-071).
 */
class UserSecurityController extends Controller
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    /**
     * Send a password-reset link to the user's own email address.
     *
     * The response says only that it was sent. No token, no temporary password,
     * nothing the administrator could use themselves.
     *
     * The existing password keeps working until the link is used, so this cannot
     * be used to lock somebody out.
     */
    public function sendPasswordReset(User $user, SendPasswordResetLink $sendLink): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $sendLink->handle($user);

        return response()->json([
            'data' => ['message' => 'A password reset link has been sent to the user.'],
        ]);
    }

    /**
     * Where this user is signed in.
     *
     * Opaque keys and coarse device labels only — no raw session ids, no session
     * payloads, no full user-agent strings.
     */
    public function sessions(Request $request, User $user): JsonResponse
    {
        $this->authorize('viewSessions', $user);

        return response()->json([
            // No `$request` passed to the registry: `current` marks *the
            // caller's* session, and an administrator viewing somebody else has
            // no session in that list to mark.
            'data' => $this->sessions->forUser($user)->all(),
        ]);
    }

    /**
     * Sign this user out everywhere.
     *
     * Every session, with nothing spared — an administrator ending a
     * compromised account's access must not leave one device connected because
     * of a rule about sparing the current one.
     */
    public function revokeSessions(Request $request, User $user): Response
    {
        $this->authorize('revokeSessions', $user);

        $revoked = $this->sessions->revokeAll($user);

        Log::info('SESSIONS_REVOKED_BY_ADMIN', [
            'user_id' => $user->getKey(),
            'actor_id' => $request->user()->getKey(),
            'count' => $revoked,
        ]);

        return response()->noContent();
    }

    /**
     * Remove this user's two-factor authentication.
     *
     * For the lost phone with the recovery codes lost alongside it. Removal
     * only: the user re-enrols themselves, and the new secret is seen by them
     * and nobody else.
     *
     * An optional `reason` is recorded, because "why was this account's
     * protection reduced" is the question an audit asks first.
     */
    public function disableTwoFactor(
        Request $request,
        User $user,
        DisableTwoFactor $disable,
    ): Response {
        $this->authorize('manageTwoFactor', $user);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $disable->handle($user, $request->user(), $validated['reason'] ?? null);

        return response()->noContent();
    }
}
