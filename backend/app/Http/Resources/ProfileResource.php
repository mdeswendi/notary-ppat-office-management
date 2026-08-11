<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A person's own account, as they see it.
 *
 * Separate from {@see UserResource}, which is the current-session and
 * authorization contract, and from {@see ManagedUserResource}, which is how an
 * administrator sees somebody else. Three questions, three shapes: merging them
 * would couple a self-service form to the authorization payload, and any change
 * to one would risk the others.
 *
 * Read-only context is included because it is useful to see and harmless to
 * show — the Office you work in, the roles you hold — but showing it is not
 * offering to change it. Only `name`, `phone`, and `preferred_locale` are
 * editable, and `PATCH` rejects the rest outright (D-066).
 *
 * Never exposed: `password`, `remember_token`, `email_verified_at`, permission
 * pivots, `user_permission_overrides`, or session state. The effective
 * authorization projection is deliberately absent too — `/api/v1/me` already
 * answers that, and a second copy could drift.
 *
 * @mixin User
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // Displayed, never editable here: it is the authentication
            // identifier, and changing it needs a verification flow that has
            // not been specified (D-067).
            'email' => $this->email,

            'phone' => $this->phone,
            'preferred_locale' => $this->preferred_locale,
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            // Where the person works. Read-only: transferring somebody between
            // Offices is an administrative act (D-049), not a self-service one.
            'office' => $this->whenLoaded('office', fn (): array => [
                'id' => $this->office->id,
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),

            // Presentation only, exactly as in `/api/v1/me`. Nothing decides
            // anything from a role name (D-045).
            'roles' => $this->resource->getRoleNames()->sort()->values()->all(),
        ];
    }
}
