<?php

namespace App\Models;

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;

/**
 * The single per-user authorization exception (D-029).
 *
 * At most one row per user per permission, enforced by a unique index, so
 * "the active override" is never a question about which row wins. An active
 * `DENY` denies regardless of role grants; an active `ALLOW` *replaces* the
 * role-derived result and its scope becomes authoritative, so an override can
 * narrow access as well as widen it.
 *
 * `created_at` with no `updated_at` follows docs/03_DATABASE_ERD.md section 5.
 * An override is a decision, and a decision that changes is a new decision.
 *
 * Expiry deliberately has **no helper here**. The rule — `expires_at IS NULL`
 * or strictly in the future, evaluated at check time — lives in exactly one
 * place, `EffectiveAccessResolver`. A convenience copy on the model is how the
 * two quietly drift apart, and an authorization rule that exists twice is an
 * authorization rule that is wrong somewhere.
 *
 * **No fillable attributes**, for the same reason as
 * {@see RolePermissionScope}: every column is an authorization decision. M1.3
 * exposes no API for this table.
 */
class UserPermissionOverride extends Model
{
    use HasUlids;

    /**
     * Only `created_at` exists on this table.
     */
    public const UPDATED_AT = null;

    /**
     * The user the exception applies to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * Who granted the exception. Provenance, which is why the foreign key
     * restricts rather than cascades.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effect' => UserPermissionEffect::class,
            'scope' => DataScope::class,
            'expires_at' => 'datetime',
        ];
    }
}
