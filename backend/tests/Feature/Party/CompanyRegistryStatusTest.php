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

function companyMatrixReader(): User
{
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'permissions.view', DataScope::ALL);

    return $actor->fresh();
}

/**
 * The catalogue, flattened by code.
 *
 * @return Collection<string, array<string, mixed>>
 */
function companyMatrixEntries(): Collection
{
    return collect(
        test()->actingAs(companyMatrixReader())->getJson('/api/v1/permissions')->assertOk()
            ->json('data.groups')
    )->flatMap(fn (array $group): array => $group['permissions'])->keyBy('code');
}

it('adds no permission in M2.3', function (): void {
    // Narrowed at M3.4, which moved the global total to 173 (D-098). The total
    // is pinned once in `PermissionRegistryTest`; the claim that belongs here is
    // that M2.3 invented no Company-domain code, and that still holds.
    $companies = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'companies.'),
    ));

    expect($companies)->toHaveCount(8);
});

it('marks every Company lifecycle permission as implemented', function (string $code): void {
    // M2.3 gives each of these a reachable route, so the badge would now be the
    // stale kind that trains people to ignore badges (D-077).
    expect(companyMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with(['companies.view', 'companies.create', 'companies.update', 'companies.archive']);

it('marks every relationship permission implemented as of M2.4', function (string $code): void {
    // M2.3 deferred these because Companies had become a live surface with no
    // relationship section behind it. M2.4 built both surfaces, so the badge
    // would now be the stale kind that trains people to ignore badges (D-077).
    expect(companyMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with([
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
]);

it('keeps every party permission implemented', function (string $code): void {
    expect(companyMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with([
    'parties.view', 'parties.create', 'parties.update', 'parties.archive',
    'parties.identity.view', 'parties.identity.update',
    'parties.identity.nik.view_full', 'parties.identity.npwp.view_full',
]);

it('checks the implemented claim against the router, not the list', function (): void {
    // A status claim with no mechanism to keep it true is one that will
    // eventually be false (D-077).
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    // Every Company lifecycle permission claimed implemented has a real surface.
    expect($uris->contains(fn (string $u): bool => $u === 'api/v1/companies'))->toBeTrue()
        ->and($uris->contains(fn (string $u): bool => $u === 'api/v1/companies/{company}'))->toBeTrue()
        ->and($uris->contains(fn (string $u): bool => $u === 'api/v1/companies/{company}/archive'))->toBeTrue()
        ->and($uris->contains(fn (string $u): bool => str_contains($u, 'companies/{company}/identity')))->toBeTrue();

    // Relationship surfaces landed at M2.4, so this no longer asserts their
    // absence; what stays true is that `company_people` is never addressed as a
    // top-level resource. `CompanyRelationshipRegistryTest` owns the positive
    // claim for the relationship routes.
    expect($uris->filter(fn (string $u): bool => str_contains($u, 'company-people')
        || str_contains($u, 'company_people')))->toBeEmpty();
});

it('still offers only OFFICE and ALL for company permissions', function (string $code): void {
    expect(companyMatrixEntries()[$code]['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
})->with(['companies.view', 'companies.create', 'companies.update', 'companies.archive']);

it('exposes no Client API and no generic Party mutation', function (): void {
    // "Client" is a word, not a table (D-078), and that never changes.
    //
    // The generic-Party half narrowed at M2.5: the read-only directory landed at
    // `GET /api/v1/parties`. What the original assertion was really protecting
    // is that no second *write* path to a Party exists beside the subtype
    // lifecycles — so that is what it asserts now.
    $routes = collect(app('router')->getRoutes()->getRoutes());

    expect($routes->map(fn ($r): string => $r->uri())
        ->filter(fn (string $u): bool => str_contains($u, 'clients')))->toBeEmpty();

    $partyRoutes = $routes->filter(
        fn ($r): bool => preg_match('#(^|/)api/v1/parties(/|$)#', $r->uri()) === 1
    );

    expect($partyRoutes)->toHaveCount(1);

    foreach ($partyRoutes as $route) {
        expect($route->methods())->toContain('GET')
            ->and($route->methods())->not->toContain('POST', 'PATCH', 'PUT', 'DELETE');
    }
});

it('introduces no surface beyond the milestone that owns it', function (): void {
    // **Narrowed at M3.3, not deleted.** This asserted no `projects` route
    // existed, which was true from M2.3 until M3.3 intentionally shipped one.
    // The rest of the list has not expired and is kept: Matter, deeds,
    // documents, properties, and Warkah all belong to M4 and later, and none of
    // them may appear as a side effect of Party or Project work.
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    // **Narrowed at M4.4**, which ships the Matter surface at its own domain
    // roots (D-109). What this guard was always about survives: no Matter, deed,
    // document, property, or Warkah surface hangs off a **Company** address.
    foreach (['companies/{company}/matters', 'deeds', 'documents', 'properties', 'warkah'] as $segment) {
        expect($uris->filter(fn (string $u): bool => str_contains($u, $segment)))->toBeEmpty($segment);
    }
});
