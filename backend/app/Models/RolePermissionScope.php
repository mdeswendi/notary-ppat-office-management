<?php

namespace App\Models;

use App\Domains\Authorization\Enums\DataScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Data Scope a role's permission grant reaches (D-028).
 *
 * Spatie's `role_has_permissions` says a role holds a permission; this says how
 * far that grant extends across records. A grant without a row here contributes
 * nothing at all — `EffectiveAccessResolver` treats missing scope metadata as a
 * denial, because reading it as `ALL` would turn an administrative oversight
 * into a privilege escalation.
 *
 * One scope per role per permission, enforced by a unique index. The union
 * described in D-028 happens *across* the several roles a user holds, not
 * within one role's grant.
 *
 * **No fillable attributes.** Every column here is an authorization decision,
 * so rows are built by assigning properties explicitly — there is no mass
 * assignment path that request input could ever reach
 * (docs/07_SECURITY_RULES.md section 34). M1.3 exposes no API for this table.
 */
class RolePermissionScope extends Model
{
    use HasUlids;

    /**
     * The package's Role model, whose key stays a package-native bigint.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => DataScope::class,
        ];
    }
}
