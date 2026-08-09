<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Safe identity fields only.
     *
     * The attribute list is explicit rather than derived from the model, so a
     * column added later cannot start appearing in API output by accident.
     * `password`, `remember_token`, session state, and account flags are never
     * exposed. Roles and permissions arrive with M0.8.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'preferred_locale' => $this->preferred_locale,
            'roles' => $this->resource->getRoleNames()->sort()->values()->all(),
            'permissions' => $this->effectivePermissionNames(),
        ];
    }

    /**
     * Permission names for the interface to hint with.
     *
     * **Presentation only, and not the authorization model.** This list comes
     * from the package's `getAllPermissions()`, so it includes direct
     * user-permission grants that D-029 and D-041 exclude from first-party
     * authorization, and it carries no Data Scope — it cannot express a
     * condition like "`roles.view` at `ALL`". It therefore does not agree with
     * `EffectiveAccessResolver`, and no backend decision reads it: every
     * endpoint authorizes independently through a Policy (D-048).
     *
     * Known and tracked as O-026, to be resolved in M1.7 by deriving this from
     * the resolver, scopes included, so the interface and the backend follow one
     * calculation. Until then, treat a name here as a hint about what to show,
     * never as proof of what is allowed.
     *
     * Names only: database ids, pivot rows, and guard internals stay
     * server-side. Sorted and de-duplicated so a permission reachable by more
     * than one path appears once and the output order is stable.
     *
     * @return array<int, string>
     */
    private function effectivePermissionNames(): array
    {
        return $this->resource->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
