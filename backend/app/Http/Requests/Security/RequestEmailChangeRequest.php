<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking to move the account to a different email address.
 *
 * The current password is required. The address is the login identifier, so
 * changing it is a credential change, and a borrowed session must not be enough
 * to redirect somebody's account to a mailbox they do not own (D-073).
 *
 * Uniqueness is checked against soft-deleted rows as well. A retired account
 * still holds its address, and letting a live account take it would make the
 * audit trail ambiguous about who did what — two different people, one
 * identifier, no way to tell them apart later.
 *
 * `different` blocks re-requesting the address already in use: it would send a
 * verification mail that changes nothing.
 */
class RequestEmailChangeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // `notIn` rather than `different`, which compares against another
                // *field* in the payload and would silently pass here.
                Rule::notIn([$this->user()->email]),
                // Trashed rows are included, matching M1.5's user requests.
                Rule::unique('users', 'email')->ignore($this->user()->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
            'email.not_in' => 'That is already your email address.',
        ];
    }
}
