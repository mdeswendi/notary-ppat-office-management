<?php

namespace App\Http\Requests\Security;

use App\Domains\Identity\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing your own password.
 *
 * `current_password` is required and checked against the signed-in user by
 * Laravel's rule of the same name. A live session is not enough on its own — an
 * unattended screen is a session too, and re-proving the password is what stops
 * a passer-by from taking the account permanently (D-071).
 *
 * `confirmed` guards against a typo becoming a lockout: nobody can read what
 * they typed, so the second field is the only check available.
 *
 * Strength itself is delegated to {@see PasswordRules}, so this endpoint, the
 * reset endpoint, and user creation cannot drift apart.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [...PasswordRules::forNewPassword(), 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.different' => 'The new password must be different from the current password.',
        ];
    }
}
