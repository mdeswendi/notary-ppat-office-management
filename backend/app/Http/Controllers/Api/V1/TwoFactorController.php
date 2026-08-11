<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\BeginTwoFactorEnrolment;
use App\Domains\Identity\Actions\ConfirmTwoFactorEnrolment;
use App\Domains\Identity\Actions\DisableTwoFactor;
use App\Domains\Identity\Actions\RegenerateRecoveryCodes;
use App\Domains\Identity\Exceptions\TwoFactorUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ConfirmPasswordRequest;
use App\Http\Requests\Security\ConfirmTwoFactorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The authenticated user's own two-factor authentication.
 *
 * Self-service throughout: the target is `$request->user()` and there is no id
 * parameter. An administrator's power over somebody else's second factor is a
 * separate route with a separate permission, and it can only *remove*
 * (D-076).
 *
 * Three responses in this controller carry material that exists exactly once —
 * the enrolment secret, and the recovery codes from confirmation and
 * regeneration. None of it is readable again afterwards from any endpoint,
 * including for the user themselves.
 */
class TwoFactorController extends Controller
{
    /**
     * Begin enrolment: secret, provisioning URI, and a QR code to scan.
     *
     * Nothing about how the account logs in changes yet. Enrolment counts only
     * once {@see confirm()} succeeds.
     */
    public function store(Request $request, BeginTwoFactorEnrolment $begin): JsonResponse
    {
        if ($request->user()->hasConfirmedTwoFactor()) {
            // Refused rather than silently re-issued: a new secret would break
            // the authenticator that currently works.
            throw TwoFactorUnavailable::alreadyConfirmed();
        }

        return response()->json(['data' => $begin->handle($request->user())]);
    }

    /**
     * Confirm enrolment with the first working code, and receive the recovery
     * codes — the only time they are ever shown.
     */
    public function confirm(
        ConfirmTwoFactorRequest $request,
        ConfirmTwoFactorEnrolment $confirm,
    ): JsonResponse {
        $codes = $confirm->handle($request->user(), $request->string('code')->toString());

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /**
     * Turn two-factor off. Requires the current password, because this is the
     * direction that removes protection.
     */
    public function destroy(ConfirmPasswordRequest $request, DisableTwoFactor $disable): Response
    {
        if (! $request->user()->hasConfirmedTwoFactor()) {
            throw TwoFactorUnavailable::notEnabled();
        }

        $disable->handle($request->user());

        return response()->noContent();
    }

    /**
     * Replace every recovery code with a fresh set. Also password-protected: the
     * old codes stop working, so an unattended screen must not be enough.
     */
    public function regenerateRecoveryCodes(
        ConfirmPasswordRequest $request,
        RegenerateRecoveryCodes $regenerate,
    ): JsonResponse {
        $codes = $regenerate->handle($request->user());

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }
}
