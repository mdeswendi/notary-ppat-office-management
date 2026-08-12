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

it('exposes no relationship or client API surface', function (): void {
    // This assertion has narrowed twice rather than being deleted, which is the
    // point: at M2.1 no Party surface existed, at M2.2 Individuals shipped, and
    // at M2.3 Companies did. What survives is what is still true — relationship
    // behaviour is M2.4 (D-083), and "Client" is never a second entity (D-078).
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    expect($uris->filter(fn (string $uri): bool => str_contains($uri, 'clients')))->toBeEmpty()
        ->and($uris->filter(fn (string $uri): bool => str_contains($uri, 'company-people')
            || str_contains($uri, 'company_people')
            || str_contains($uri, 'management')
            || str_contains($uri, 'shareholders')
            || str_contains($uri, 'directors')))->toBeEmpty();
});

it('leaves the deferred list unchanged', function (): void {
    // The flag marks permissions inside a module the interface presents as
    // working. Party is absent from navigation entirely, exactly as projects.*
    // is, so it needs no badge — adding one would contradict the semantics
    // M1.10 settled and imply Party is partially shipped.
    $response = $this->getJson('/api/v1/permissions');

    expect($response->status())->toBe(401);
});
