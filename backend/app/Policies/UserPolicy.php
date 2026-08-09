<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Identity\UserVisibility;
use App\Models\User;

/**
 * Who may administer user accounts.
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so all of
 * M1.3's rules apply unchanged: canonical permissions only, a role grant with no
 * Data Scope grants nothing, an active DENY override wins, an active ALLOW
 * override replaces the role result, expired overrides are ignored, and Spatie's
 * direct user-permission grants never participate.
 *
 * No role name is read anywhere. `SUPER_ADMIN` receives no bypass, and there is
 * no `Gate::before` (D-032, D-048).
 *
 * Unlike role definitions, users are Office-owned, so these abilities do **not**
 * require the `ALL` scope. `OFFICE` is a working predicate here — it matches the
 * target's `office_id` — and {@see UserVisibility} turns the granted scopes into
 * the same condition used to filter the list, so a record can never be hidden
 * from the listing yet remain reachable by id.
 */
class UserPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly UserVisibility $visibility,
    ) {}

    /**
     * Any reach at all is enough to open the list; the query itself is what
     * narrows the rows. A grant carrying only ASSIGNED or TEAM reaches no user,
     * so it is refused here rather than returning a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->hasReadableScope($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->visibility->permitsReading(
            $actor,
            $this->resolver->resolve($actor, 'users.view'),
            $target,
        );
    }

    /**
     * Creation is checked against the *destination Office* rather than a target
     * record, since none exists yet. The Form Request supplies `office_id`, and
     * the office must be assignable and active.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        $access = $this->resolver->resolve($actor, 'users.create');

        if ($officeId === null) {
            return $this->hasAdministrableScope($access);
        }

        return $this->visibility->permitsOfficeAssignment($actor, $access, $officeId);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->visibility->permitsAdministration(
            $actor,
            $this->resolver->resolve($actor, 'users.update'),
            $target,
        );
    }

    /**
     * May the actor move a user *into* this Office?
     *
     * Checked separately from {@see update()}, because reaching the target and
     * reaching the destination are different questions. An office-scoped
     * administrator may edit a colleague but must not be able to move them
     * somewhere they can no longer see them — an edit that ends in losing the
     * record is worse than an edit that is refused.
     *
     * Judged against `users.update`, the capability actually being exercised.
     */
    public function assignOffice(User $actor, string $officeId): bool
    {
        return $this->visibility->permitsOfficeAssignment(
            $actor,
            $this->resolver->resolve($actor, 'users.update'),
            $officeId,
        );
    }

    /**
     * Activation and deactivation share one capability, `users.disable`, and one
     * predicate. Refusing to disable oneself is a separate concern — the actor
     * is authorized, so it is answered with a conflict rather than a denial.
     */
    public function setActivation(User $actor, User $target): bool
    {
        return $this->visibility->permitsAdministration(
            $actor,
            $this->resolver->resolve($actor, 'users.disable'),
            $target,
        );
    }

    /**
     * Reading the Office choices a User Management form may offer. Anyone who
     * may create or update a user needs them.
     */
    public function viewOptions(User $actor): bool
    {
        return $this->hasAdministrableScope($this->resolver->resolve($actor, 'users.create'))
            || $this->hasAdministrableScope($this->resolver->resolve($actor, 'users.update'));
    }

    private function hasReadableScope(User $actor): bool
    {
        $access = $this->resolver->resolve($actor, 'users.view');

        return $access->granted && (
            $access->hasScope(DataScope::ALL)
            || $access->hasScope(DataScope::OFFICE)
            || $access->hasScope(DataScope::OWN)
        );
    }

    private function hasAdministrableScope(EffectiveAccess $access): bool
    {
        return $access->granted && (
            $access->hasScope(DataScope::ALL) || $access->hasScope(DataScope::OFFICE)
        );
    }
}
