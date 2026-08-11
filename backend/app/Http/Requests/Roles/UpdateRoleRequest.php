<?php

namespace App\Http\Requests\Roles;

use App\Domains\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Validation for renaming a role.
 *
 * Same rules as creation, except the role being edited is excluded from the
 * uniqueness check so that saving a form without changing the name is not an
 * error.
 *
 * `guard_name` is not accepted here either — see {@see StoreRoleRequest}. The
 * guard is fixed for the lifetime of the role.
 */
class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', PermissionRegistry::GUARD)
                    ->ignore($role->getKey()),
            ],
        ];
    }
}
