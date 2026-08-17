<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Who may administer role definitions.
 *
 * Every decision runs through {@see EffectiveAccessResolver}, so all of M1.3's
 * rules apply unchanged: only canonical permissions count, a role grant with no
 * Data Scope grants nothing, an active DENY override wins, an active ALLOW
 * override replaces the role result, expired overrides are ignored, and
 * Spatie's direct user-permission assignments never participate (D-029, D-041).
 *
 * Nothing here reads a role *name*. `SUPER_ADMIN` receives no bypass, and there
 * is no `Gate::before`, `Gate::after`, or name comparison anywhere in this class
 * (D-032). A role called `SUPER_ADMIN` and a role called anything else resolve
 * identically — what differs is the permission granted to it and at which scope.
 *
 * All five abilities additionally require the `ALL` Data Scope, because a Role
 * definition is deployment-global authorization metadata rather than an owned,
 * assigned, or office-held record — see `allowsGlobally()` and D-044.
 *
 * The ability names below (`viewAny`, `view`, …) deliberately are not permission
 * names. This paragraph used to explain that as a live hazard: Spatie *did*
 * register a `Gate::before` answering any ability matching a held permission —
 * from direct user grants, with no scope check — so an ability named `roles.view`
 * would have been answered by the package before this policy ran (O-027).
 *
 * **That is no longer true, and the convention holds for a different reason.**
 * D-048 resolved O-027 by setting `register_permission_check_method` to `false`,
 * so the package registers no Gate callback at all and a permission name reaching
 * the Gate now fails closed — asserted by test. Keeping ability names distinct
 * from permission codes remains right because a Policy ability and a capability
 * identifier are different things, and because reintroducing the package
 * integration must never silently re-open the hole. Corrected at M4.1 (D-077).
 */
class RolePolicy
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsGlobally($user, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->resolver->allowsGlobally($user, 'roles.view');
    }

    public function create(User $user): bool
    {
        return $this->resolver->allowsGlobally($user, 'roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->resolver->allowsGlobally($user, 'roles.update');
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->resolver->allowsGlobally($user, 'roles.delete');
    }
}
