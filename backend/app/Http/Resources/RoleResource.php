<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * The role definition itself, and nothing about what it can do.
     *
     * No permission list, no Data Scope rows, no member count. M1.4 manages
     * role records only, and shipping a read-only capability summary now would
     * put a shape in the API contract before the milestone that owns it has
     * decided what that shape should be (M1.6).
     *
     * `id` is the package's own auto-incrementing integer, not a ULID —
     * `roles` is a third-party table whose key type stays as the package
     * defines it (D-023, D-045). The frontend treats it as an opaque handle and
     * derives nothing from its value.
     *
     * ISO 8601 timestamps, per `docs/06_API_CONVENTIONS.md` section 16.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
