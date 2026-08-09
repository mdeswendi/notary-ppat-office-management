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
        ];
    }
}
