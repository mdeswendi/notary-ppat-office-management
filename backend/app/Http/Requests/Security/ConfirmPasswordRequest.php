<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A bare re-proof of the signed-in user's password.
 *
 * Used by the operations that weaken or replace a second factor — disabling
 * two-factor, and regenerating recovery codes. Both would otherwise be reachable
 * from nothing more than an unlocked screen, and both hand the person who finds
 * that screen lasting access rather than a moment of it (D-076).
 *
 * Deliberately not required for *enabling* two-factor. Adding protection should
 * be the frictionless direction; removing it is where the friction belongs.
 */
class ConfirmPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
