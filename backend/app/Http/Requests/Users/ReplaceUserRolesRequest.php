<?php

namespace App\Http\Requests\Users;

use App\Domains\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for replacing a user's role membership.
 *
 * Roles are named by id rather than name, so renaming a role cannot change who
 * holds it — the same reason nothing in the authorization path compares role
 * names (D-045).
 *
 * `distinct` rejects a payload listing the same role twice rather than quietly
 * de-duplicating it: a request that does not mean what it says should be
 * corrected, not interpreted.
 *
 * Every role must belong to the registry's guard. A role on another guard could
 * never grant anything the resolver would honour (D-046), so assigning one would
 * look like granting access and deliver none.
 */
class ReplaceUserRolesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array'],
            'role_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(config('permission.table_names.roles'), 'id')
                    ->where('guard_name', PermissionRegistry::GUARD),
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function roleIds(): array
    {
        return array_map('intval', $this->validated()['role_ids'] ?? []);
    }
}
