<?php

namespace App\Http\Requests\Profile;

use App\Domains\Identity\SupportedLocales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a user editing their own profile.
 *
 * Three fields, all optional so a partial PATCH is meaningful: `name`, `phone`,
 * and `preferred_locale`. Everything else a user record carries belongs to
 * somebody else's decision (D-066).
 *
 * `email` is **rejected rather than ignored** if submitted. It is the
 * authentication identifier, and changing it needs a verification flow nobody
 * has specified — deferred to Account Security. Silently discarding it would
 * let the interface appear to accept a change that never happened, which is
 * worse than a clear refusal.
 *
 * `office_id`, `is_active`, `password`, `roles`, and `permissions` are rejected
 * for the same reason: each is an administrative or security decision, and a
 * self-service form is not where they belong. `validated()` would drop them
 * anyway; refusing says so out loud.
 *
 * `preferred_locale` accepts only the canonical stored codes — `id` and `en`,
 * never `id-ID` or `Indonesia` (D-068).
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * Fields a user may never set on themselves, with the reason each is
     * refused rather than quietly dropped.
     */
    private const FORBIDDEN = [
        'email',
        'email_verified_at',
        'office_id',
        'is_active',
        'password',
        'password_confirmation',
        'roles',
        'permissions',
        'last_login_at',
        'deleted_at',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Free text, as M1.5 established: no country prefix and no
            // reformatting, because the specification defines neither.
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'preferred_locale' => ['sometimes', 'required', 'string', Rule::in(SupportedLocales::all())],
        ];

        foreach (self::FORBIDDEN as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.prohibited' => 'Your email address cannot be changed here.',
            'office_id.prohibited' => 'Your office is changed by an administrator, not from your profile.',
            'password.prohibited' => 'Your password is changed from account security, not from your profile.',
            'is_active.prohibited' => 'Account activation is an administrative action.',
            'roles.prohibited' => 'Roles are assigned by an administrator.',
            'permissions.prohibited' => 'Permissions are assigned by an administrator.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['preferred_locale' => 'language'];
    }
}
