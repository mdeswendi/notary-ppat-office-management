<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function relationshipMatrixEntries(): Collection
{
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'permissions.view', DataScope::ALL);

    return collect(
        test()->actingAs($actor->fresh())->getJson('/api/v1/permissions')->assertOk()
            ->json('data.groups')
    )->flatMap(fn (array $group): array => $group['permissions'])->keyBy('code');
}

it('adds no permission in M2.4', function (): void {
    // Narrowed at M3.4, which moved the global total to 173 (D-098). The total
    // is pinned once in `PermissionRegistryTest`; the claim that belongs here is
    // that M2.4 invented no Company-domain code — the relationship surfaces
    // reuse `companies.management.*` and `companies.shareholders.*` — and that
    // still holds.
    $companies = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'companies.'),
    ));

    expect($companies)->toHaveCount(8);
});

it('marks every relationship permission as implemented', function (string $code): void {
    // M2.4 gives each of these a reachable route, so the badge would now be the
    // stale kind that trains people to ignore badges (D-077).
    expect(relationshipMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with([
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
]);

it('leaves no Party-domain code deferred', function (): void {
    // The Party domain is fully implemented as of M2.4, and that is the claim
    // this file owns.
    //
    // Narrowed at M4.4. It previously pinned the *global* deferred set to
    // `security.settings.*`, which was true when the Party domain was the newest
    // module and stopped being true the moment another module registered a code
    // ahead of its surface — `notary.matters.change_stage` and
    // `ppat.matters.change_stage` now do exactly that (D-109). A Party test
    // failing because a Matter permission was deferred is the assertion
    // overreaching its subject, not a defect it caught. The global set stays
    // pinned once, in `PermissionMatrixTest`.
    $deferred = relationshipMatrixEntries()->where('deferred', true)->keys();

    expect($deferred->filter(
        fn (string $code): bool => str_starts_with($code, 'parties.')
            || str_starts_with($code, 'companies.')
    )->values()->all())->toBe([]);
});

it('checks the relationship claim against the router, not the list', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    foreach (['management', 'shareholders'] as $surface) {
        expect($uris->contains("api/v1/companies/{company}/{$surface}"))->toBeTrue()
            ->and($uris->contains("api/v1/companies/{company}/{$surface}/options"))->toBeTrue()
            ->and($uris->contains("api/v1/companies/{company}/{$surface}/{relationship}/end"))->toBeTrue();
    }
});

it('exposes no destructive or generic-update relationship route', function (): void {
    // The append-and-close model is a property of the router, not a convention:
    // there is no verb that could remove or rewrite a historical row.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'management')
            || str_contains($route->uri(), 'shareholders')
            || str_contains($route->uri(), 'company-people'));

    foreach ($routes as $route) {
        expect($route->methods())->not->toContain('DELETE')
            ->and($route->methods())->not->toContain('PUT')
            ->and($route->methods())->not->toContain('PATCH');
    }

    expect($routes)->not->toBeEmpty();
});

it('still offers only OFFICE and ALL for relationship permissions', function (string $code): void {
    expect(relationshipMatrixEntries()[$code]['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
})->with([
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
]);

it('introduces no merge, fingerprint, or later-module surface', function (): void {
    // At M2.4 this also covered `duplicate`, which was true then. M2.5 shipped
    // advisory duplicate *candidate* checks, so the assertion narrowed to what
    // stays true and matters more: detection never merges, never exposes the
    // cryptographic material it compares on, and starts no later module (D-084,
    // D-086).
    //
    // **Narrowed again at M3.3**, which intentionally ships the Project surface
    // this once forbade. `projects` is gone from the list; everything else is
    // kept, and the Party-domain point survives unchanged — nothing here reaches
    // Matter, deeds, documents, properties, or Warkah, and Project is not
    // reachable *through the Party surfaces* either.
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    // **Narrowed at M4.4**, which ships the Matter surface at its own domain
    // roots (D-109). The boundary this guard protects is unchanged: none of these
    // hangs off a Company relationship address.
    //
    // **Narrowed again at M5.2**, which ships the Document surface at its own
    // root (D-117). `documents` is no longer forbidden outright; the
    // Company-rooted direction still is, which is the boundary this guard has
    // always been about.
    //
    // **Narrowed a fourth time at M6.2**, which ships the Notarial Deed surface at
    // `/notary/deeds` (D-120). Same treatment as `documents`: the bare segment
    // goes, the Company-rooted direction stays forbidden.
    foreach (['fingerprint', 'merge', 'similarity', 'score', 'clients',
        'companies/{company}/matters', 'companies/{company}/documents',
        'companies/{company}/deeds', 'properties', 'warkah'] as $segment) {
        expect($uris->filter(fn (string $u): bool => str_contains($u, $segment)))->toBeEmpty($segment);
    }

    // Project exists now, but never as a Party sub-resource.
    //
    // **Narrowed again at M3.4**, which ships the opposite nesting on purpose:
    // `projects/{project}/parties` is a Project sub-resource, authorized by the
    // Project's Data Scope. What must stay false is the Party-rooted direction —
    // a Party surface that reaches Project would put Project work behind Party
    // permissions. So the test now anchors on the root segment instead of
    // matching the substring anywhere in the URI.
    $partyRooted = $uris->filter(fn (string $u): bool => str_starts_with($u, 'api/v1/parties/')
        || str_starts_with($u, 'api/v1/individuals/')
        || str_starts_with($u, 'api/v1/companies/'));

    expect($partyRooted->filter(fn (string $u): bool => str_contains($u, 'project')))->toBeEmpty();

    // What duplicate detection does expose is candidate checks, and only those.
    expect($uris->filter(fn (string $u): bool => str_contains($u, 'duplicate'))
        ->every(fn (string $u): bool => str_ends_with($u, 'duplicate-candidates')))->toBeTrue();
});
