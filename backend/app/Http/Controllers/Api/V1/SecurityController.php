<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\ChangeOwnPassword;
use App\Domains\Identity\Actions\RequestEmailChange;
use App\Domains\Identity\Actions\VerifyEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ChangePasswordRequest;
use App\Http\Requests\Security\RequestEmailChangeRequest;
use App\Http\Resources\SecurityOverviewResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The authenticated user's own account security.
 *
 * **Self-service, not administration** — the same boundary
 * {@see ProfileController} draws, for the same reason (D-066). The target is
 * always `$request->user()`; there is no id parameter and no way to name
 * somebody else. No canonical permission guards these routes, because
 * `security.*` describes administering *other people's* security, and requiring
 * one here would mean a user could be forbidden from changing their own
 * password.
 *
 * Every mutating operation re-proves the current password through its Form
 * Request. A live session says a browser is signed in; it does not say the
 * person at the keyboard is the account owner (D-071).
 */
class SecurityController extends Controller
{
    public function show(Request $request): SecurityOverviewResource
    {
        return SecurityOverviewResource::make($request->user());
    }

    public function updatePassword(
        ChangePasswordRequest $request,
        ChangeOwnPassword $changePassword,
    ): Response {
        $changePassword->handle($request, $request->user(), $request->string('password')->toString());

        // No body. Returning anything about the password — even its length —
        // would be a detail this endpoint has no reason to disclose.
        return response()->noContent();
    }

    public function requestEmailChange(
        RequestEmailChangeRequest $request,
        RequestEmailChange $requestChange,
    ): SecurityOverviewResource {
        $requestChange->handle($request->user(), $request->string('email')->toString());

        // The pending address comes back so the interface can say which mailbox
        // to check, which is the one thing the user needs next.
        return SecurityOverviewResource::make($request->user()->refresh());
    }

    /**
     * Confirm a pending email change.
     *
     * Authenticated: the link lands on a frontend page which calls this with the
     * session already established. An unauthenticated variant would let a token
     * alone change an address, and a token in a mailbox is a weaker credential
     * than a token plus a session.
     */
    public function verifyEmailChange(
        Request $request,
        VerifyEmailChange $verify,
    ): SecurityOverviewResource {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $verify->handle($request, $request->user(), $validated['token']);

        return SecurityOverviewResource::make($request->user()->refresh());
    }

    /**
     * Abandon a pending email change without confirming it.
     *
     * The safety valve for "I typed it wrong" and for "that request was not
     * mine". Clears the pending state and nothing else.
     */
    public function cancelEmailChange(Request $request): SecurityOverviewResource
    {
        $user = $request->user();

        $user->forceFill([
            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_requested_at' => null,
        ])->save();

        return SecurityOverviewResource::make($user);
    }
}
