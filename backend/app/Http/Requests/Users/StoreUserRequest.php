<?php

namespace App\Http\Requests\Users;

use App\Models\Office;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for creating a user account.
 *
 * The Office must exist **and be active**: assigning somebody to an Office that
 * is being retired would be staffing a place that is closing (D-049). Whether
 * the actor may use that particular Office is a separate question, answered by
 * {@see UserPolicy::create()} — validation says the Office is
 * usable, authorization says it is theirs to use.
 *
 * The initial password uses Laravel's own `Password::default()` rather than a
 * bespoke rule. No password policy is canonicalized anywhere in the
 * specification, and inventing complexity requirements, expiry, or history here
 * would be inventing account-security rules that belong to M1.9 (D-051).
 *
 * Deliberately not accepted: `is_active`, `preferred_locale`,
 * `email_verified_at`, `last_login_at`, `roles`, `permissions`, `guard_name`,
 * `deleted_at`. `validated()` returns only the keys below, so none of them can
 * reach the action even if submitted.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],

            // Free text. No country prefix and no formatting is imposed — the
            // specification defines none, and rewriting what an office typed
            // would be inventing a rule.
            'phone' => ['nullable', 'string', 'max:50'],

            'office_id' => [
                'required',
                'string',
                Rule::exists(Office::class, 'id')->where('is_active', true),
            ],

            'password' => ['required', 'confirmed', Password::default()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['office_id' => 'office'];
    }
}
