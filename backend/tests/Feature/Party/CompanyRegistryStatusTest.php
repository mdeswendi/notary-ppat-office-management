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
    expect(count(PermissionRegistry::all()))->toBe(171);
});

it('marks every Company lifecycle permission as implemented', function (string $code): void {
    // M2.3 gives each of these a reachable route, so the badge would now be the
    // stale kind that trains people to ignore badges (D-077).
    expect(companyMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with(['companies.view', 'companies.create', 'companies.update', 'companies.archive']);

it('keeps every relationship permission deferred', function (string $code): void {
    // Sharper than before M2.3: Companies is a live surface now, so granting
    // `companies.management.view` and getting no directors section is exactly
    // the surprise the badge prevents. Relationships are M2.4 (D-083).
    expect(companyMatrixEntries()[$code]['deferred'])->toBeTrue();
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

    // And the converse: nothing relationship-shaped is reachable, which is what
    // keeps those four permissions honestly deferred.
    expect($uris->filter(fn (string $u): bool => str_contains($u, 'management')
        || str_contains($u, 'shareholders')
        || str_contains($u, 'company-people')))->toBeEmpty();
});

it('still offers only OFFICE and ALL for company permissions', function (string $code): void {
    expect(companyMatrixEntries()[$code]['allowed_scopes'])->toBe(['OFFICE', 'ALL']);
})->with(['companies.view', 'companies.create', 'companies.update', 'companies.archive']);

it('exposes no Client or generic Party API', function (): void {
    // "Client" is a word, not a table (D-078). A second persistence surface
    // beside Party is the thing the unified aggregate exists to prevent.
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($uris->filter(fn (string $u): bool => str_contains($u, 'clients')))->toBeEmpty()
        ->and($uris->filter(fn (string $u): bool => preg_match('#(^|/)api/v1/parties(/|$)#', $u) === 1))
        ->toBeEmpty();
});

it('introduces no M3 surface', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    foreach (['projects', 'matters', 'deeds', 'documents', 'properties', 'warkah'] as $segment) {
        expect($uris->filter(fn (string $u): bool => str_contains($u, $segment)))->toBeEmpty();
    }
});
