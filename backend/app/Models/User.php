<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * `is_active`, `last_login_at`, and `office_id` are intentionally left out of
 * the fillable list: none may ever be set from request input. Account state is
 * changed by administration, the login timestamp is written by the
 * application, and moving someone between offices is a User Management
 * operation subject to backend authorization — not something a profile update
 * should be able to do. See docs/07_SECURITY_RULES.md section 34.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'preferred_locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Role and permission relations, provided by spatie/laravel-permission.
     *
     * No `role` or `permission` column is added to `users`: assignments live
     * in the package pivot tables, whose morph key is a ULID so that it
     * matches the primary key supplied by HasUlids below.
     *
     * Capability decisions are made with `can('resource.action')`, never with
     * `hasRole()` — CLAUDE.md section 24 and
     * docs/02_MENU_AND_PERMISSIONS.md section 1. Role membership is for
     * display and administration.
     */
    use HasRoles;

    /**
     * ULID primary key, per CLAUDE.md section 11.
     *
     * The trait supplies the generated identifier and sets the key as a
     * non-incrementing string, so no manual id generation or `$keyType`
     * override is needed. Identifiers are opaque: nothing may parse them or
     * infer ordering from them.
     */
    use HasUlids;

    /**
     * Retiring a person must never destroy the record their work references.
     *
     * There is no `users.delete` permission and no deletion endpoint, so
     * nothing in the product calls this today (D-050). It exists because the
     * canonical ERD carries `deleted_at`, and because a soft delete keeps
     * Spatie's foreign-key-less morph pivots from being orphaned if a removal
     * path is ever built — see O-025. Accounts are retired with `is_active`.
     */
    use SoftDeletes;

    /**
     * The user's primary office. Exactly one in V1 (D-027) — the Organization
     * is reached through it rather than stored again on this table.
     *
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
