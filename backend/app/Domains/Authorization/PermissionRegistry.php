<?php

namespace App\Domains\Authorization;

/**
 * The single first-party source of truth for permission names.
 *
 * Every entry is transcribed from `docs/02_MENU_AND_PERMISSIONS.md` sections 7
 * to 21, which remains authoritative. Nothing here is invented: if a capability
 * is missing, the fix is to add it to that document first.
 *
 * Most of this catalogue describes modules that do not exist yet. Those entries
 * are **inert** — a permission name creates no route, controller, policy,
 * table, menu entry, or role assignment. Registering them now means role
 * configuration can be designed against the finished capability surface instead
 * of growing one milestone at a time.
 *
 * Deliberately absent:
 *
 *   audit.update / audit.delete   section 21 explicitly forbids them; audit
 *                                 records are append-only
 *   party.identity.nik.view_full  superseded alias (D-001)
 *   documents.view_sensitive      superseded alias (D-001)
 *   documents.download_sensitive  superseded alias (D-001)
 *
 * `settings.*` and `security.settings.*` are separate namespaces, not aliases
 * (D-030). General system configuration is not the same capability as
 * authentication and security configuration.
 *
 * Pure data: no database access, no container resolution, no side effects.
 */
final class PermissionRegistry
{
    /**
     * The guard every first-party permission belongs to.
     *
     * A permission's identity is `(name, guard_name)`, so the registry, the
     * synchronization command, and the resolver must all name the same guard or
     * nothing authorizes.
     *
     * Deliberately **not** `config('auth.defaults.guard')`. That value is
     * mutable at runtime: `Illuminate\Auth\Middleware\Authenticate` calls
     * `Auth::shouldUse()` on success, which rewrites the default guard for the
     * rest of the request. Every authenticated API request passes through
     * `auth:sanctum`, so reading the config inside a controller yields
     * `sanctum` — a guard no permission row was ever written for, which would
     * make authorization fail closed on every request while continuing to
     * resolve correctly in tests and on the console (D-046).
     *
     * `web` is the session guard the first-party SPA authenticates against.
     * Sanctum's stateful mode authenticates that same session; it is a wrapper
     * over this guard, not a second permission namespace.
     */
    public const GUARD = 'web';

    /**
     * Every canonical permission, grouped by the documentation section it comes
     * from. Grouping is for traceability and for the eventual permission matrix
     * UI; authorization itself only ever uses the flat list.
     *
     * @return array<string, array<int, string>>
     */
    public static function groups(): array
    {
        return [
            // Section 7
            'projects' => [
                'projects.view',
                'projects.view_all',
                'projects.create',
                'projects.update',
                'projects.assign',
                'projects.change_status',
                'projects.archive',
                'projects.restore',

                // Section 7a. Project <-> Party participation (M3.4, D-098).
                // Two codes, and deliberately no `projects.parties.view_all`:
                // reach is Data Scope `ALL` against the parent Project, and a
                // second reach mechanism is what D-090 refuses.
                'projects.parties.view',
                'projects.parties.manage',
            ],

            // Section 8
            'parties' => [
                'parties.view',
                'parties.create',
                'parties.update',
                'parties.archive',
                'parties.identity.view',
                'parties.identity.update',
                'parties.identity.nik.view_full',
                'parties.identity.npwp.view_full',
            ],

            // Section 9
            'companies' => [
                'companies.view',
                'companies.create',
                'companies.update',
                'companies.archive',
                'companies.management.view',
                'companies.management.update',
                'companies.shareholders.view',
                'companies.shareholders.update',
            ],

            // Section 10
            'notary' => [
                'notary.matters.view',
                'notary.matters.view_all',
                'notary.matters.create',
                'notary.matters.update',
                'notary.matters.assign',
                'notary.matters.change_stage',
                'notary.matters.complete',
                'notary.matters.cancel',

                'notary.deeds.view',
                'notary.deeds.create',
                'notary.deeds.update',
                'notary.deeds.review',
                'notary.deeds.approve',
                'notary.deeds.finalize',
                'notary.deeds.number',

                'notary.minuta.view',
                'notary.minuta.create',
                'notary.minuta.update',
                'notary.minuta.archive',
                'notary.minuta.release',

                'notary.register.view',
                'notary.register.create',
                'notary.register.update',
                'notary.register.finalize',
                'notary.register.export',
            ],

            // Section 11
            'ppat' => [
                'ppat.matters.view',
                'ppat.matters.view_all',
                'ppat.matters.create',
                'ppat.matters.update',
                'ppat.matters.assign',
                'ppat.matters.change_stage',
                'ppat.matters.complete',
                'ppat.matters.cancel',

                'ppat.deeds.view',
                'ppat.deeds.create',
                'ppat.deeds.update',
                'ppat.deeds.review',
                'ppat.deeds.approve',
                'ppat.deeds.finalize',
                'ppat.deeds.number',

                'ppat.warkah.view',
                'ppat.warkah.upload',
                'ppat.warkah.update',
                'ppat.warkah.verify',
                'ppat.warkah.finalize',
                'ppat.warkah.archive',

                'ppat.register.view',
                'ppat.register.create',
                'ppat.register.update',
                'ppat.register.finalize',
                'ppat.register.export',

                'ppat.reports.view',
                'ppat.reports.generate',
                'ppat.reports.review',
                'ppat.reports.approve',
                'ppat.reports.export',
            ],

            // Section 12
            'properties' => [
                'properties.view',
                'properties.create',
                'properties.update',
                'properties.archive',
                'properties.ownership.view',
                'properties.ownership.update',
            ],

            // Section 13
            'documents' => [
                'documents.view',
                'documents.sensitive.view',
                'documents.upload',
                'documents.download',
                'documents.sensitive.download',
                'documents.update',
                'documents.verify',
                'documents.archive',
                'documents.delete',
            ],

            // Section 14
            'tasks' => [
                'tasks.view',
                'tasks.view_all',
                'tasks.create',
                'tasks.update',
                'tasks.assign',
                'tasks.complete',
                'tasks.reopen',
                'tasks.delete',
            ],

            // Section 15
            'calendar' => [
                'calendar.view',
                'calendar.view_all',
                'calendar.create',
                'calendar.update',
                'calendar.delete',
            ],

            // Section 16
            'billing' => [
                'billing.view',
                'billing.amount.view',

                'quotations.view',
                'quotations.create',
                'quotations.update',
                'quotations.approve',

                'invoices.view',
                'invoices.create',
                'invoices.update',
                'invoices.issue',
                'invoices.cancel',

                'payments.view',
                'payments.create',
                'payments.verify',

                'disbursements.view',
                'disbursements.create',
                'disbursements.update',
            ],

            // Section 17
            'reports' => [
                'reports.operational.view',
                'reports.notary.view',
                'reports.ppat.view',
                'reports.financial.view',
                'reports.audit.view',
                'reports.export',
            ],

            // Section 18
            'master_data' => [
                'master.services.view',
                'master.services.manage',
                'master.workflows.view',
                'master.workflows.manage',
                'master.requirements.view',
                'master.requirements.manage',
                'master.task_templates.view',
                'master.task_templates.manage',
                'master.document_templates.view',
                'master.document_templates.manage',
                'master.numbering.view',
                'master.numbering.manage',
                'master.legal_terms.view',
                'master.legal_terms.manage',
            ],

            // Section 19
            'users_and_roles' => [
                'users.view',
                'users.create',
                'users.update',
                'users.disable',
                'users.reset_password',

                'roles.view',
                'roles.create',
                'roles.update',
                'roles.delete',

                'permissions.view',
                'permissions.assign',
            ],

            // Section 19a. No organizations.create: the single Organization is
            // created once by deployment bootstrap (D-026, D-034). No hard
            // delete for either resource — retirement uses is_active.
            'organizations_and_offices' => [
                'organizations.view',
                'organizations.update',

                'offices.view',
                'offices.create',
                'offices.update',
                'offices.disable',
            ],

            // Section 20. Two distinct groups, deliberately not aliases (D-030).
            'settings' => [
                'settings.view',
                'settings.manage',
            ],

            'security' => [
                'security.settings.view',
                'security.settings.manage',
                'security.sessions.view',
                'security.sessions.revoke',
                'security.mfa.manage',
            ],

            // Section 21. Read-only by design; audit records are append-only.
            'audit' => [
                'audit.view',
                'audit.export',
            ],
        ];
    }

    /**
     * Every canonical permission name, de-duplicated and sorted.
     *
     * Sorted so the order never depends on how the groups happen to be written
     * — synchronization output and tests stay stable across edits.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $permissions = array_merge(...array_values(self::groups()));

        $permissions = array_values(array_unique($permissions));

        sort($permissions);

        return $permissions;
    }

    /**
     * How many canonical permissions exist.
     */
    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * Is this name part of the canonical catalogue?
     */
    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
