<?php

namespace App\Http\Requests\Security;

use App\Domains\Identity\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completing a password reset with an emailed token.
 *
 * Unauthenticated by necessity — the whole point is that the person cannot sign
 * in. The token is therefore the only credential, which is why the endpoint
 * behind this is rate limited and why the token is single-use and short-lived.
 *
 * Only shape is validated here. Whether the token is genuine, unexpired, and
 * belongs to that address is the password broker's job, and asking it twice
 * would create two answers that can disagree.
 *
 * The same {@see PasswordRules} apply as everywhere else: a reset must not be
 * the cheap route to a weaker password than the change form would accept.
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => PasswordRules::forNewPassword(),
        ];
    }
}
