<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Models\User;

/**
 * Who may read and change the authorization configuration.
 *
 * Covers the permission catalogue, role permission grants, and role membership —
 * everything that decides what anybody can do. All of it is deployment-global
 * metadata owned by nobody, so both abilities require the `ALL` Data Scope
 * (D-054), exactly as role management does (D-044). `OFFICE`, `OWN`,
 * `ASSIGNED`, and `TEAM` have no record to match against here.
 *
 * Presence, not precedence: `{OFFICE, ALL}` passes because `ALL` is in the set.
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so
 * canonical-registry membership, missing scope metadata, overrides, expiry, and
 * the exclusion of direct package grants all apply unchanged. No role name is
 * read; `SUPER_ADMIN` gets no bypass.
 *
 * The ability names are `viewAny` and `assign` rather than the permission codes
 * themselves. O-027 was the original reason — Spatie's `Gate::before` would have
 * answered an ability named after a permission — and **D-048 resolved it** by
 * disabling that integration, so the Gate now refuses permission names outright.
 * The distinction is kept because a Policy ability and a capability identifier
 * are different things, and it is asserted by test rather than left to
 * convention. Citation corrected at M4.1 (D-077).
 */
class PermissionPolicy
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    public function viewAny(User $actor): bool
    {
        return $this->resolver->allowsGlobally($actor, 'permissions.view');
    }

    public function assign(User $actor): bool
    {
        return $this->resolver->allowsGlobally($actor, 'permissions.assign');
    }
}
