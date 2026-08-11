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

it('keeps every company permission deferred', function (string $code): void {
    // Company surfaces are M2.3 and M2.4. Now that the Party module appears in
    // navigation, an administrator granting one of these would reasonably expect
    // something to happen — so the badge earns its place.
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', $code);

    expect($entry['deferred'])->toBeTrue();
})->with([
    'companies.view', 'companies.create', 'companies.update', 'companies.archive',
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

    // And nothing Company-shaped is reachable, which is what keeps those deferred.
    expect($uris->filter(fn (string $u): bool => str_contains($u, 'companies')))->toBeEmpty();
});

it('still offers only OFFICE and ALL for party permissions', function (): void {
    $response = $this->actingAs(matrixReader())->getJson('/api/v1/permissions')->assertOk();

    $entry = collect($response->json('data.groups'))
        ->flatMap(fn (array $group): array => $group['permissions'])
        ->firstWhere('code', 'parties.view');

    expect($entry['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
});
