<?php

namespace App\Http\Requests\Users;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the administrative update of a user account.
 *
 * The same four fields the action writes, all optional so a partial PATCH is
 * meaningful. Email uniqueness ignores the user being edited, so saving a form
 * without changing the address is not an error.
 *
 * **Password is not accepted here.** Changing somebody's password is an
 * account-security operation with its own capability and its own flow (M1.9),
 * not a field on an administrative form (D-051). Neither is `is_active`:
 * activation has its own endpoints so that turning an account off is always a
 * deliberate act (D-052). Neither is `preferred_locale`, which belongs to the
 * person, not their administrator (M1.8).
 *
 * `roles` and `permissions` are absent because assignment is M1.6 — a role
 * granted through a user form would change effective authorization from a
 * screen that never asked about authorization.
 *
 * Since `validated()` returns only the rules below, every one of those fields is
 * discarded rather than merely ignored.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'office_id' => [
                'sometimes',
                'required',
                'string',
                Rule::exists(Office::class, 'id')->where('is_active', true),
            ],
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
