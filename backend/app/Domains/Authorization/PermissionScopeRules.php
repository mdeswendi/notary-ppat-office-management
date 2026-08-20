<?php

namespace App\Domains\Authorization;

use App\Domains\Authorization\Enums\DataScope;

/**
 * Which Data Scopes may be assigned to which permission.
 *
 * One source, consulted by the write endpoint and served to the interface, so
 * the matrix cannot offer a scope the backend would reject and the backend
 * cannot silently accept one the matrix never showed (D-053).
 *
 * It holds **no permission names of its own** — every answer is derived from
 * {@see PermissionRegistry}, which stays the only catalogue. What lives here is
 * the rule about a name's shape, not the name.
 *
 * **`TEAM` is never assignable.** It remains a canonical `DataScope` because the
 * vocabulary is fixed (D-042), but no Team entity exists, so a grant carrying it
 * could never be evaluated against a record. `02_MENU_AND_PERMISSIONS.md`
 * section 22 requires it be rejected in validation, and this is where that
 * happens.
 *
 * Only the rules the specification has actually settled are encoded. Everything
 * else gets the generic non-TEAM set rather than a restriction invented here —
 * a domain's Policy decides what its own permissions can mean, and none of those
 * domains exist yet.
 */
class PermissionScopeRules
{
    /**
     * Deployment-global authorization metadata: not owned, not assigned, not
     * office-held. `ALL` is the only predicate that can reach it (D-044).
     */
    private const GLOBAL_PREFIXES = ['roles.', 'permissions.'];

    /**
     * Reading users, which a person may legitimately do for themselves.
     */
    private const USER_READ = ['users.view'];

    /**
     * Administering users. `OWN` is excluded — editing your own administrative
     * record is self-service, not administration (D-049).
     */
    private const USER_ADMINISTRATION = [
        'users.create',
        'users.update',
        'users.disable',
        'users.reset_password',
    ];

    /**
     * The Party domain, whose predicates M2.0 settled (D-080).
     *
     * `OFFICE` and `ALL` only. The other three are withheld because they would
     * be offers the resolver could not honour: `OWN` must not become
     * `created_by` — a Party is a shared directory record, and typing one in is
     * no claim on the person it describes; `ASSIGNED` has no Party assignment
     * entity to match; `TEAM` has no Team entity at all (D-042).
     *
     * Withholding them here matters because the Permission Matrix offers exactly
     * this list. Leaving Party in the permissive default below would let an
     * administrator grant `parties.view` at `OWN`, see it saved, and get a
     * silently powerless grant — the configuration equivalent of a dead control.
     *
     * `parties.identity.*` and the Company relationship permissions are included:
     * they select the same records by the same predicate, so a narrower or wider
     * scope set would be inventing a second rule for one boundary.
     */
    private const PARTY_DOMAIN = [
        'parties.view',
        'parties.create',
        'parties.update',
        'parties.archive',
        'parties.identity.view',
        'parties.identity.update',
        'parties.identity.nik.view_full',
        'parties.identity.npwp.view_full',
        'companies.view',
        'companies.create',
        'companies.update',
        'companies.archive',
        'companies.management.view',
        'companies.management.update',
        'companies.shareholders.view',
        'companies.shareholders.update',
    ];

    /**
     * The Project domain, whose predicates M3.0 settled (D-088).
     *
     * All four assignable scopes mean something here, which is why Project gets
     * an explicit entry rather than the permissive default below: the default
     * offered the same four, but offered them as *"nobody has decided yet"*. Now
     * somebody has, and the list says so.
     *
     *   OWN       projects.created_by  = actor id
     *   ASSIGNED  projects.pic_user_id = actor id
     *   OFFICE    projects.office_id   = actor office
     *   ALL       cross-office reach
     *
     * `TEAM` is withheld here as everywhere — no Team entity exists (D-042).
     *
     * That Party withholds `OWN` (D-080) while Project offers it is not an
     * inconsistency. A Party is a shared directory record and the colleague who
     * typed it in has no claim on the person it describes; a Project is a unit of
     * work somebody opened. Each domain argued its own case.
     *
     * **`projects.view_all` is deliberately absent from this list.** It is
     * superseded by Data Scope `ALL` for reach and is not part of the Project
     * authorization surface (D-090); no Policy ability consults it. Adding it
     * here would assert it is a live capability with meaningful scopes, which is
     * precisely what supersession denies. It keeps the generic default below,
     * unchanged — M3 alters nothing about it, including its assignability, which
     * would need its own ruling.
     */
    private const PROJECT_DOMAIN = [
        'projects.view',
        'projects.create',
        'projects.update',
        'projects.assign',
        'projects.change_status',
        'projects.archive',
        'projects.restore',

        // Participation is evaluated against the **parent Project** by the same
        // four predicates (M3.4, D-098), so it belongs in this list rather than
        // in the Party one. The Party a row points at is reached through Party
        // visibility separately; that is a different question with a different
        // answer, and mixing the two here would encode one boundary as two.
        'projects.parties.view',
        'projects.parties.manage',
    ];

    /**
     * The Service Type catalogue, whose predicates M4.1 settled.
     *
     * `OFFICE` and `ALL` only — the Party answer (D-080), not the Project one
     * (D-088), because the reasoning that separated them applies here too. A
     * Service Type is a **shared reference record**: `OWN` would have to mean
     * `created_by`, and the table deliberately has no such column, since the
     * colleague who typed an entry in has no claim on the service the office
     * offers. `ASSIGNED` has no assignment entity — nobody is the PIC of a
     * catalogue entry. `TEAM` has no Team entity at all (D-042).
     *
     * Withholding them matters because the Permission Matrix offers exactly this
     * list. Leaving `master.services.*` in the permissive default below would let
     * an administrator grant it at `OWN`, see it saved, and get a silently
     * powerless grant — the dead control D-080 named.
     *
     * **Only the Service Type family is narrowed.** The other twelve `master.*`
     * codes keep the permissive default, because their domains are still
     * undesigned and narrowing them would repeat the mistake this entry corrects,
     * one module across.
     */
    private const MASTER_SERVICE_TYPES = [
        'master.services.view',
        'master.services.manage',
    ];

    /**
     * The Matter domain, whose predicates M4.2 settled (D-100).
     *
     * All four assignable scopes mean something here, exactly as they do for
     * Project:
     *
     *   OWN       matters.created_by  = actor id
     *   ASSIGNED  matters.pic_user_id = actor id
     *   OFFICE    matters.office_id   = actor office
     *   ALL       cross-office reach
     *
     * `TEAM` is withheld here as everywhere — no Team entity exists (D-042).
     *
     * **Eighteen codes as of M4.5, not twenty.** `notary.matters.view_all` and
     * `ppat.matters.view_all` are deliberately absent: they are superseded by
     * Data Scope `ALL` for reach and are not part of the Matter authorization
     * surface (D-090). No Policy ability consults either. Listing them here would
     * assert they are live capabilities with meaningful scopes, which is precisely
     * what supersession denies — the same treatment `projects.view_all` receives.
     *
     * **`create` needs no special entry, and that is worth stating** because it
     * looks as though it might. An administrator may legitimately grant
     * `notary.matters.create` at `ASSIGNED`; the grant simply authorizes nothing
     * on its own, because the `ASSIGNED` predicate is false for a record that has
     * no PIC yet (D-097, D-107). That exclusion belongs to the predicate, not to
     * the assignable-scope list — encoding it here would confuse *what may be
     * granted* with *what a grant can match*.
     */
    private const MATTER_DOMAIN = [
        'notary.matters.view',
        'notary.matters.create',
        'notary.matters.update',
        'notary.matters.assign',
        'notary.matters.change_stage',
        'notary.matters.complete',
        'notary.matters.cancel',

        // Participation is evaluated against the **parent Matter** by the same
        // four predicates (M4.5, D-105), so it belongs in this list rather than
        // in the Party one. The Party a row points at is reached through Party
        // visibility separately; that is a different question with a different
        // answer, and mixing the two here would encode one boundary as two.
        'notary.matters.parties.view',
        'notary.matters.parties.manage',

        'ppat.matters.view',
        'ppat.matters.create',
        'ppat.matters.update',
        'ppat.matters.assign',
        'ppat.matters.change_stage',
        'ppat.matters.complete',
        'ppat.matters.cancel',

        'ppat.matters.parties.view',
        'ppat.matters.parties.manage',
    ];

    /**
     * The scopes assignable to a permission, in canonical order.
     *
     * @return array<int, DataScope>
     */
    public function allowedFor(string $permission): array
    {
        if ($this->matchesPrefix($permission, self::GLOBAL_PREFIXES)) {
            return [DataScope::ALL];
        }

        if (in_array($permission, self::USER_READ, true)) {
            return [DataScope::OWN, DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::USER_ADMINISTRATION, true)) {
            return [DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::PARTY_DOMAIN, true)) {
            return [DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::PROJECT_DOMAIN, true)) {
            return [DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::MASTER_SERVICE_TYPES, true)) {
            return [DataScope::OFFICE, DataScope::ALL];
        }

        if (in_array($permission, self::MATTER_DOMAIN, true)) {
            return [DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL];
        }

        // Everything else, including every permission whose module is not built
        // yet. Deliberately permissive rather than guessed: narrowing it would
        // mean deciding what `notary.deeds.approve` at `OWN` means before the
        // Notary domain has been designed.
        //
        // Project used to land here. It no longer does — the four scopes above
        // are the same four, but they are now a decision (D-088) rather than a
        // placeholder, and an explicit entry is what tells the difference.
        return [DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL];
    }

    public function permits(string $permission, DataScope $scope): bool
    {
        return in_array($scope, $this->allowedFor($permission), true);
    }

    /**
     * The rules for the whole catalogue, keyed by permission name.
     *
     * @return array<string, array<int, string>>
     */
    public function all(): array
    {
        $rules = [];

        foreach (PermissionRegistry::all() as $permission) {
            $rules[$permission] = array_map(
                fn (DataScope $scope): string => $scope->value,
                $this->allowedFor($permission),
            );
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    private function matchesPrefix(string $permission, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
