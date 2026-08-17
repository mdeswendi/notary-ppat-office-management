<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function matrixReader(): User
{
    $actor = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($actor, 'permissions.view', DataScope::ALL);

    return $actor->fresh();
}

it('adds no permission in M2.2', function (): void {
    // Narrowed at M3.4, which moved the global total to 173 (D-098). The total
    // is pinned once in `PermissionRegistryTest`; the claim that belongs here is
    // that M2.2 invented no Party-domain code, and that still holds.
    $party = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'parties.'),
    ));

    expect($party)->toHaveCount(8);
});

it('marks every party permission as implemented', function (string $code): void {
    // M2.2 gives each of these a reachable route, so none may carry the badge.
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', $code);

    expect($entry['deferred'])->toBeFalse();
})->with([
    'parties.view', 'parties.create', 'parties.update', 'parties.archive',
    'parties.identity.view', 'parties.identity.update',
    'parties.identity.nik.view_full', 'parties.identity.npwp.view_full',
]);

it('marks every company permission implemented once its surface exists', function (string $code): void {
    // This assertion has tracked the milestone honestly rather than being
    // deleted: at M2.2 all eight `companies.*` codes were deferred, at M2.3 the
    // four lifecycle codes were not, and at M2.4 none of them is — every one has
    // a reachable route. That the expectation inverted is the badge working.
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', $code);

    expect($entry['deferred'])->toBeFalse();
})->with([
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
]);

it('checks the implemented claim against the router, not the list', function (): void {
    // The M1.10 correction, applied forward: a status claim with no mechanism to
    // keep it true is one that will eventually be false (D-077).
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    // Every party permission claimed implemented has a real Individual surface.
    expect($uris->contains(fn (string $u): bool => $u === 'api/v1/individuals'))->toBeTrue()
        ->and($uris->contains(fn (string $u): bool => str_contains($u, 'individuals/{individual}/identity')))->toBeTrue();

    // The Company relationship surfaces exist as of M2.4, so this no longer
    // asserts their absence — `CompanyRelationshipRegistryTest` owns that claim
    // in its positive form. What stays here is the Individual half.
    expect($uris->contains(fn (string $u): bool => $u === 'api/v1/individuals/{individual}'))->toBeTrue();
});

it('still offers only OFFICE and ALL for party permissions', function (): void {
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', 'parties.view');

    expect($entry['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
});
