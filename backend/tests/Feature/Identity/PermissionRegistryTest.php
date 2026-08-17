<?php

use App\Domains\Authorization\PermissionRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The registry is pure data, so these tests deliberately do not touch the
 * database. RefreshDatabase is absent on purpose: if any assertion here starts
 * needing a schema, the registry has grown a dependency it must not have.
 */

/**
 * The catalogue transcribed from docs/02_MENU_AND_PERMISSIONS.md sections 7-21.
 * Asserting the exact number turns any accidental addition, removal, or
 * duplicate into a failing test rather than a silent change to the
 * authorization surface.
 *
 * 171 from M1 through M3.3. **173 since M3.4**, which added
 * `projects.parties.view` and `projects.parties.manage` — the first codes any
 * milestone has added since the catalogue was transcribed, and deliberate rather
 * than accidental (D-098). This constant is the one place the total is pinned;
 * milestone tests assert that *their own* domain group is unchanged instead, so
 * a later milestone's legitimate addition does not have to be applied in six
 * places.
 */
const CANONICAL_PERMISSION_COUNT = 173;

it('publishes a non-empty catalogue', function (): void {
    expect(PermissionRegistry::all())->not->toBeEmpty();
});

it('publishes exactly the documented number of permissions', function (): void {
    expect(PermissionRegistry::all())->toHaveCount(CANONICAL_PERMISSION_COUNT);
});

it('reports a count matching the catalogue itself', function (): void {
    expect(PermissionRegistry::count())->toBe(count(PermissionRegistry::all()));
});

it('contains no duplicate permission names', function (): void {
    $all = PermissionRegistry::all();

    expect(array_unique($all))->toHaveCount(count($all));
});

it('returns the catalogue in sorted order', function (): void {
    $all = PermissionRegistry::all();
    $sorted = $all;
    sort($sorted);

    expect($all)->toBe($sorted);
});

it('returns an identical catalogue on repeated calls', function (): void {
    expect(PermissionRegistry::all())->toBe(PermissionRegistry::all());
});

it('exposes a flat catalogue equal to the union of its groups', function (): void {
    $flattened = array_merge(...array_values(PermissionRegistry::groups()));
    sort($flattened);

    expect(array_values(array_unique($flattened)))->toBe(PermissionRegistry::all());
});

it('declares no empty group', function (): void {
    foreach (PermissionRegistry::groups() as $group => $permissions) {
        expect($permissions)->not->toBeEmpty("group [{$group}] is empty");
    }
});

it('names every permission as lowercase dot-separated segments', function (): void {
    foreach (PermissionRegistry::all() as $permission) {
        expect($permission)->toMatch('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/');
    }
});

it('answers membership questions about the catalogue', function (): void {
    expect(PermissionRegistry::has('projects.view'))->toBeTrue()
        ->and(PermissionRegistry::has('projects.obliterate'))->toBeFalse();
});

it('resolves the catalogue without touching the database', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    PermissionRegistry::all();
    PermissionRegistry::groups();
    PermissionRegistry::count();
    PermissionRegistry::has('projects.view');

    expect(DB::getQueryLog())->toBeEmpty();

    DB::disableQueryLog();
});

/*
|--------------------------------------------------------------------------
| Canonical membership
|--------------------------------------------------------------------------
|
| Spot checks per documentation section. These are not exhaustive — the count
| assertion above guards the total — but they pin the naming of every module's
| permissions so a rename cannot pass unnoticed.
|
*/

it('includes the project permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'projects.view',
        'projects.view_all',
        'projects.create',
        'projects.update',
        'projects.assign',
        'projects.change_status',
        'projects.archive',
        'projects.restore',
    );
});

it('includes the party permissions with separate identity capabilities', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'parties.view',
        'parties.create',
        'parties.update',
        'parties.archive',
        'parties.identity.view',
        'parties.identity.update',
        'parties.identity.nik.view_full',
        'parties.identity.npwp.view_full',
    );
});

it('separates NIK from NPWP unmasking', function (): void {
    // Two different statutory identifiers; seeing one must not imply the other.
    expect(PermissionRegistry::has('parties.identity.nik.view_full'))->toBeTrue()
        ->and(PermissionRegistry::has('parties.identity.npwp.view_full'))->toBeTrue();
});

it('includes the company permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'companies.view',
        'companies.create',
        'companies.update',
        'companies.archive',
        'companies.management.view',
        'companies.management.update',
        'companies.shareholders.view',
        'companies.shareholders.update',
    );
});

it('includes the notary matter, deed, minuta and register permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'notary.matters.view',
        'notary.matters.change_stage',
        'notary.deeds.review',
        'notary.deeds.approve',
        'notary.deeds.finalize',
        'notary.deeds.number',
        'notary.minuta.view',
        'notary.minuta.release',
        'notary.register.finalize',
        'notary.register.export',
    );
});

it('includes the PPAT matter, deed, warkah, register and report permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'ppat.matters.view',
        'ppat.matters.change_stage',
        'ppat.deeds.review',
        'ppat.deeds.finalize',
        'ppat.deeds.number',
        'ppat.warkah.view',
        'ppat.warkah.upload',
        'ppat.warkah.verify',
        'ppat.warkah.finalize',
        'ppat.register.finalize',
        'ppat.reports.approve',
    );
});

it('keeps the notary and PPAT permission namespaces separate', function (): void {
    // Shared infrastructure, distinct legal domains. A single generic
    // matters.view would let one office role act in the other domain.
    $all = PermissionRegistry::all();

    expect($all)->not->toContain('matters.view')
        ->and($all)->not->toContain('deeds.view')
        ->and($all)->not->toContain('register.view');
});

it('includes the property permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'properties.view',
        'properties.create',
        'properties.update',
        'properties.archive',
        'properties.ownership.view',
        'properties.ownership.update',
    );
});

it('gates sensitive document access behind its own permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'documents.view',
        'documents.sensitive.view',
        'documents.upload',
        'documents.download',
        'documents.sensitive.download',
        'documents.update',
        'documents.verify',
        'documents.archive',
        'documents.delete',
    );
});

it('includes the task permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'tasks.view',
        'tasks.view_all',
        'tasks.create',
        'tasks.update',
        'tasks.assign',
        'tasks.complete',
        'tasks.reopen',
        'tasks.delete',
    );
});

it('includes the calendar permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'calendar.view',
        'calendar.view_all',
        'calendar.create',
        'calendar.update',
        'calendar.delete',
    );
});

it('includes the billing permissions across every financial document', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'billing.view',
        'billing.amount.view',
        'quotations.approve',
        'invoices.issue',
        'invoices.cancel',
        'payments.verify',
        'disbursements.create',
    );
});

it('includes the report permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'reports.operational.view',
        'reports.notary.view',
        'reports.ppat.view',
        'reports.financial.view',
        'reports.audit.view',
        'reports.export',
    );
});

it('pairs every master data resource with a view and a manage permission', function (): void {
    $resources = [
        'services',
        'workflows',
        'requirements',
        'task_templates',
        'document_templates',
        'numbering',
        'legal_terms',
    ];

    foreach ($resources as $resource) {
        expect(PermissionRegistry::has("master.{$resource}.view"))->toBeTrue()
            ->and(PermissionRegistry::has("master.{$resource}.manage"))->toBeTrue();
    }
});

it('includes the user, role and permission administration permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
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
    );
});

it('includes the organization and office permissions', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'organizations.view',
        'organizations.update',
        'offices.view',
        'offices.create',
        'offices.update',
        'offices.disable',
    );
});

it('offers no way to create a second organization or delete either resource', function (): void {
    // The single Organization is a deployment concern (D-026); offices retire
    // through is_active rather than deletion, because users reference them.
    $all = PermissionRegistry::all();

    expect($all)->not->toContain('organizations.create')
        ->and($all)->not->toContain('organizations.delete')
        ->and($all)->not->toContain('offices.delete');
});

it('keeps general settings separate from security settings', function (): void {
    expect(PermissionRegistry::all())->toContain(
        'settings.view',
        'settings.manage',
        'security.settings.view',
        'security.settings.manage',
        'security.sessions.view',
        'security.sessions.revoke',
        'security.mfa.manage',
    );
});

it('exposes audit records as read-only', function (): void {
    expect(PermissionRegistry::has('audit.view'))->toBeTrue()
        ->and(PermissionRegistry::has('audit.export'))->toBeTrue();
});

it('grants no way to alter or remove audit records', function (): void {
    // Audit logs are append-only. No permission may exist that implies
    // otherwise, regardless of who holds it.
    expect(PermissionRegistry::has('audit.update'))->toBeFalse()
        ->and(PermissionRegistry::has('audit.delete'))->toBeFalse();
});

it('omits the superseded permission names', function (): void {
    // Replaced during documentation reconciliation. Registering an old alias
    // would let a role be configured against a name nothing checks.
    expect(PermissionRegistry::has('party.identity.nik.view_full'))->toBeFalse()
        ->and(PermissionRegistry::has('documents.view_sensitive'))->toBeFalse()
        ->and(PermissionRegistry::has('documents.download_sensitive'))->toBeFalse();
});
