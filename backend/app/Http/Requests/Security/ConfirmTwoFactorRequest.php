<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirming a two-factor enrolment with the first working code.
 *
 * Enrolment is only real once a code from the authenticator actually verifies.
 * Turning it on the moment a QR code is displayed would lock out anybody whose
 * scan failed or whose device clock is wrong — and they would discover it at
 * their next login, with no way back in (D-076).
 *
 * Six digits, as a string. Casting to an integer would eat the leading zero in
 * `002451` and reject a perfectly valid code.
 */
class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['code' => 'verification code'];
    }
}
