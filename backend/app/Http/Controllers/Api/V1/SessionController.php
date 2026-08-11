<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\SessionRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ConfirmPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The authenticated user's own signed-in devices.
 *
 * "Where am I signed in, and can I stop it?" — the question that turns a
 * suspicion into an action. Self-service, so no canonical permission applies;
 * the query is scoped to `$request->user()` and cannot be pointed elsewhere
 * (D-074).
 *
 * Each row is named by an opaque key, never a session id. The id is a credential
 * — hand it out and you have handed out the session — so the API works in
 * SHA-256 digests that identify a row without being usable as one.
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sessions->forUser($request->user(), $request)->all(),
        ]);
    }

    /**
     * Sign out one other device.
     *
     * An unknown key answers 404 rather than 204. Pretending to revoke something
     * that does not exist would tell a user their old laptop is signed out when
     * it may not be.
     */
    public function destroy(Request $request, string $session): Response
    {
        // The current session is excluded by construction: revoking it here
        // would log the user out through an endpoint whose purpose is the
        // opposite. `DELETE /logout` is how you end your own session.
        if ($request->hasSession()
            && $this->sessions->opaqueKey($request->session()->getId()) === $session) {
            throw new NotFoundHttpException;
        }

        if (! $this->sessions->revokeByKey($request->user(), $session)) {
            throw new NotFoundHttpException;
        }

        Log::info('SESSION_REVOKED', ['user_id' => $request->user()->getKey()]);

        return response()->noContent();
    }

    /**
     * Sign out everywhere except here.
     *
     * Password-protected, because it is the button somebody presses when they
     * think their account is compromised — and it is also the button an
     * intruder would press to lock the owner out.
     */
    public function destroyOthers(ConfirmPasswordRequest $request): Response
    {
        // Without a session store there is no "current" session to spare, so
        // everything goes. Failing closed in the safe direction: over-revoking
        // costs a login, under-revoking leaves a device connected.
        $revoked = $this->sessions->revokeAll(
            $request->user(),
            $request->hasSession() ? $request->session()->getId() : null,
        );

        Log::info('OTHER_SESSIONS_REVOKED', [
            'user_id' => $request->user()->getKey(),
            'count' => $revoked,
        ]);

        return response()->noContent();
    }
}
