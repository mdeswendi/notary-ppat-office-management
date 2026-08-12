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
    expect(count(PermissionRegistry::all()))->toBe(171);
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

it('keeps every company relationship permission deferred', function (string $code): void {
    // At M2.2 this covered all eight `companies.*` codes, which was true then.
    // M2.3 shipped the Company lifecycle, so the assertion narrowed to what is
    // still true rather than being deleted — relationships are M2.4 (D-083), and
    // `CompanyRegistryStatusTest` now owns the positive half of the claim.
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', $code);

    expect($entry['deferred'])->toBeTrue();
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

    // And nothing relationship-shaped is reachable, which is what keeps the four
    // remaining Company codes deferred. Company *lifecycle* routes now exist, so
    // this no longer asserts the absence of everything Company-shaped.
    expect($uris->filter(fn (string $u): bool => str_contains($u, 'management')
        || str_contains($u, 'shareholders')))->toBeEmpty();
});

it('still offers only OFFICE and ALL for party permissions', function (): void {
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', 'parties.view');

    expect($entry['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
});
