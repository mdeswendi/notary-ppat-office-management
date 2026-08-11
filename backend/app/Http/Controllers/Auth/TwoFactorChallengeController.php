<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\StartAuthenticatedSession;
use App\Domains\Identity\TwoFactor;
use App\Domains\Identity\TwoFactorChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\TwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The second step of a two-factor login.
 *
 * Reachable only with a live challenge in the session, which exists only after a
 * correct password. It is not an alternative way in: no email, no user id, and
 * no password is accepted here, so it cannot be called on its own (D-075).
 *
 * Attempts are throttled harder than the password step. Six digits is a million
 * possibilities, which is plenty against a person and nothing against a script,
 * so the rate limit is what actually carries the security of this endpoint.
 *
 * Recovery codes are accepted on the same route and consumed on use.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly TwoFactorChallenge $challenge,
        private readonly TwoFactor $twoFactor,
        private readonly StartAuthenticatedSession $startSession,
    ) {}

    public function store(TwoFactorChallengeRequest $request): Response
    {
        $user = $this->challenge->pendingUser($request);

        if ($user === null) {
            // Expired, never started, or the account changed underneath it.
            // All three mean "log in again", and the answer says only that.
            throw new UnprocessableEntityHttpException(
                'This sign-in attempt has expired. Please sign in again.'
            );
        }

        $this->ensureIsNotRateLimited($user);

        $submitted = $request->filled('code')
            ? $request->string('code')->toString()
            : $request->string('recovery_code')->toString();

        $result = $this->twoFactor->verifySecondFactor($user, $submitted);

        if (! $result['valid']) {
            RateLimiter::hit($this->throttleKey($user), self::DECAY_SECONDS);

            // Never echoes the submitted value back (D-075).
            throw ValidationException::withMessages([
                'code' => __('That code was not accepted.'),
            ]);
        }

        if ($result['remainingRecoveryCodes'] !== null) {
            // A recovery code was used, so it is spent. Persisted before the
            // session starts, so a failure here cannot leave a consumed code
            // still usable.
            $user->two_factor_recovery_codes = $result['remainingRecoveryCodes'];
            $user->save();

            Log::info('TWO_FACTOR_RECOVERY_CODE_USED', [
                'user_id' => $user->getKey(),
                'remaining' => count($result['remainingRecoveryCodes']),
            ]);
        }

        // Read before clearing — the flag lives in the state being discarded.
        $remember = $this->challenge->shouldRemember($request);

        RateLimiter::clear($this->throttleKey($user));
        $this->challenge->clear($request);

        $this->startSession->handle($request, $user, $remember);

        return response()->noContent();
    }

    /**
     * @throws ThrottleRequestsException
     */
    private function ensureIsNotRateLimited(User $user): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS)) {
            return;
        }

        throw new ThrottleRequestsException(
            __('Too many attempts. Please try again later.'),
            null,
            ['Retry-After' => RateLimiter::availableIn($this->throttleKey($user))]
        );
    }

    /**
     * Keyed on the pending account and the source address. The submitted code is
     * never part of the key — that would give each guess its own bucket and
     * make the limit meaningless.
     */
    private function throttleKey(User $user): string
    {
        return 'two-factor|'.$user->getKey().'|'.request()->ip();
    }
}
