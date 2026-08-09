<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user account as User Management sees it.
 *
 * Deliberately separate from {@see UserResource}, which serves `/api/v1/me` and
 * answers a different question — "who am I and what may I see" versus "what does
 * this account look like to an administrator". Merging them would have coupled
 * an administrative screen to the identity payload, and any change here would
 * have risked O-026's behaviour.
 *
 * The attribute list is explicit, so a column added later cannot start appearing
 * in API output by accident. Never exposed: `password`, `remember_token`,
 * `email_verified_at`, `deleted_at`, session state, permission pivots, or
 * `user_permission_overrides`.
 *
 * No roles and no effective permissions. M1.5 manages accounts, not capability,
 * and shipping a read-only role list would put a shape in the API contract
 * before M1.6 has decided what that shape should be.
 *
 * `preferred_locale` is shown because an administrator benefits from knowing
 * which language a colleague works in, but it is read-only here — changing it is
 * the person's own choice (M1.8).
 *
 * @mixin User
 */
class ManagedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'preferred_locale' => $this->preferred_locale,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Enough to identify the Office without a second request, and
            // nothing more — this is not an Office endpoint.
            'office' => $this->whenLoaded('office', fn (): array => [
                'id' => $this->office->id,
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),
        ];
    }
}
