<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Attempts allowed before the throttle engages, and the decay window.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Verify the credentials and return the user they belong to.
     *
     * **No session is created here.** The provider is consulted directly rather
     * than through `attempt()`, because an account with two-factor enabled must
     * not be logged in by its password alone — the controller decides between
     * starting a session and starting a challenge, and it cannot do that if the
     * session already exists (D-075).
     *
     * `is_active` is part of the credential lookup rather than a check made
     * after a successful password comparison. A disabled account therefore
     * fails identically to a wrong password, which keeps the response from
     * distinguishing "disabled" from "does not exist".
     *
     * @throws ValidationException
     * @throws ThrottleRequestsException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ];

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            // One generic message for every failure mode, so the response
            // cannot be used to enumerate accounts.
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * @throws ThrottleRequestsException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw new ThrottleRequestsException(
            __('Too many login attempts. Please try again later.'),
            null,
            ['Retry-After' => $seconds]
        );
    }

    /**
     * Throttle on normalized identity plus source address, so one address
     * cannot spray many accounts and one account cannot be attacked from a
     * single address. The password is never part of the key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->toString()).'|'.$this->ip()
        );
    }
}
