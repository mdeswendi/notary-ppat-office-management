<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * `is_active` and `last_login_at` are intentionally left out of the fillable
 * list: neither may ever be set from request input. Account state is changed
 * by administration, and the login timestamp is written by the application.
 * See docs/07_SECURITY_RULES.md section 34.
 */
#[Fillable(['name', 'email', 'password', 'preferred_locale'])]
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
