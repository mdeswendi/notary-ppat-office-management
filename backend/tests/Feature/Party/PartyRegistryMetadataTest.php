<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Party\Enums\CompanyRelationshipCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$partyPermissions = [
    'parties.view', 'parties.create', 'parties.update', 'parties.archive',
    'parties.identity.view', 'parties.identity.update',
    'parties.identity.nik.view_full', 'parties.identity.npwp.view_full',
    'companies.view', 'companies.create', 'companies.update', 'companies.archive',
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
];

it('adds no permission in M2.1', function (): void {
    expect(count(PermissionRegistry::all()))->toBe(171)
        ->and(PermissionRegistry::count())->toBe(171);
});

it('uses only permission codes that already exist in the registry', function (string $code): void {
    // M2.1 invents no permission name. Every code the Party policies consult is
    // one the registry already carried.
    expect(PermissionRegistry::all())->toContain($code);
})->with($partyPermissions);

it('offers only OFFICE and ALL for party-domain permissions', function (string $code): void {
    // The Matrix offers exactly this list, so anything else would be an offer
    // the resolver could not honour (D-080).
    expect(app(PermissionScopeRules::class)->allowedFor($code))
        ->toBe([DataScope::OFFICE, DataScope::ALL]);
})->with($partyPermissions);

it('refuses to assign an unsupported scope to a party permission', function (string $scope): void {
    $rules = app(PermissionScopeRules::class);

    expect($rules->permits('parties.view', DataScope::from($scope)))->toBeFalse()
        ->and($rules->permits('companies.view', DataScope::from($scope)))->toBeFalse();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('keeps the relationship category mapping pointed at real permissions', function (): void {
    foreach (CompanyRelationshipCategory::cases() as $category) {
        expect(PermissionRegistry::all())->toContain($category->viewPermission())
            ->and(PermissionRegistry::all())->toContain($category->updatePermission());
    }
});

it('exposes no party API surface yet', function (): void {
    // M2.1 is schema and authorization only. The registry's implementation
    // status must not imply a reachable surface that does not exist — the stale
    // claim M1.10 had to correct (D-077).
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'parties')
            || str_contains($uri, 'individuals')
            || str_contains($uri, 'companies'));

    expect($routes)->toBeEmpty();
});

it('leaves the deferred list unchanged', function (): void {
    // The flag marks permissions inside a module the interface presents as
    // working. Party is absent from navigation entirely, exactly as projects.*
    // is, so it needs no badge — adding one would contradict the semantics
    // M1.10 settled and imply Party is partially shipped.
    $response = $this->getJson('/api/v1/permissions');

    expect($response->status())->toBe(401);
});
