<?php

namespace App\Http\Requests\Roles;

use App\Domains\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a role.
 *
 * Technical only. There is no naming convention to enforce: the nine documented
 * default roles happen to be upper snake case, but they are a default
 * configuration rather than a rule, and an office is free to name a role
 * "Notaris Pengganti". Requiring a shape would reject that for no security
 * benefit, since no authorization decision reads a role name (D-032).
 *
 * The submitted name is stored as given. Surrounding whitespace is removed by
 * the framework's global TrimStrings middleware, which turns an all-whitespace
 * submission into an empty string and then into null — so `required` rejects
 * it. Nothing else is rewritten: silently changing someone's chosen name to a
 * canonical form would make the interface lie about what was saved.
 *
 * `guard_name` is absent on purpose and cannot be supplied. `validated()`
 * returns only the rules below, so an injected guard never reaches the action,
 * which sets the application guard itself.
 *
 * Authorization is the policy's job, invoked in the controller.
 */
class StoreRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                // The registry's guard, not the mutable default: inside a
                // request the latter is `sanctum`, and uniqueness would then be
                // checked against a guard holding no roles at all (D-046).
                Rule::unique(config('permission.table_names.roles'), 'name')
                    ->where('guard_name', PermissionRegistry::GUARD),
            ],
        ];
    }
}
