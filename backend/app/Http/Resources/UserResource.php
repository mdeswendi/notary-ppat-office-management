<?php

namespace App\Http\Resources;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Safe identity fields, plus what this account can effectively do.
     *
     * The attribute list is explicit rather than derived from the model, so a
     * column added later cannot start appearing in API output by accident.
     * `password`, `remember_token`, session state, and account flags are never
     * exposed.
     *
     * **`permissions` and `permission_scopes` come from
     * {@see EffectiveAccessResolver}, the same component every Policy consults**
     * (D-062). Until M1.7 this field was Spatie's `getAllPermissions()`, which
     * counted direct user-permission grants the authorization model excludes,
     * carried no Data Scope, and ignored overrides entirely — so the browser and
     * the backend could disagree about what somebody could do. That was O-026,
     * and this is its resolution.
     *
     * A permission appears only when it is effectively **granted**: denials are
     * absent rather than present and empty. Direct package grants, stale rows,
     * grants missing scope metadata, expired overrides, and malformed ALLOW
     * overrides are all excluded, exactly as the resolver excludes them.
     *
     * `roles` remains **presentation only**. Nothing may decide visibility from a
     * role name (D-032, D-045); that is what the permission fields are for.
     *
     * Still not a security boundary. This describes what the interface should
     * offer; every request is authorized again by a Policy, and a browser
     * editing this payload gains nothing.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = app(EffectiveAccessResolver::class)->resolveAll($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'preferred_locale' => $this->preferred_locale,
            'roles' => $this->resource->getRoleNames()->sort()->values()->all(),

            // Canonical order, so the payload is stable between requests.
            'permissions' => array_keys($access),

            // Cast so an account with no capability serializes as `{}` rather
            // than `[]` — the shape should not change with its contents.
            'permission_scopes' => (object) array_map(
                fn (EffectiveAccess $granted): array => $granted->scopeValues(),
                $access,
            ),
        ];
    }
}
