<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The second factor, supplied during login.
 *
 * Either a six-digit authenticator code **or** a recovery code — exactly one.
 * `required_without` on both sides means an empty submission is refused, and
 * `prohibited_unless`-style exclusivity is left out on purpose: if somebody
 * sends both, checking the one they filled in properly is friendlier than
 * refusing the login of a person who is already halfway locked out.
 *
 * Neither value is ever logged, echoed back, or included in an error message
 * (D-075).
 */
class TwoFactorChallengeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'verification code',
            'recovery_code' => 'recovery code',
        ];
    }
}
