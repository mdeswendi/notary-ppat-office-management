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
    expect(count(PermissionRegistry::all()))->toBe(171);
});

it('marks every relationship permission as implemented', function (string $code): void {
    // M2.4 gives each of these a reachable route, so the badge would now be the
    // stale kind that trains people to ignore badges (D-077).
    expect(relationshipMatrixEntries()[$code]['deferred'])->toBeFalse();
})->with([
    'companies.management.view', 'companies.management.update',
    'companies.shareholders.view', 'companies.shareholders.update',
]);

it('leaves only the security settings codes deferred', function (): void {
    // The Party domain is fully implemented as of M2.4, so what remains is the
    // flag's original case: `security.settings.*` neighbours live capabilities
    // and has no surface of its own.
    expect(relationshipMatrixEntries()->where('deferred', true)->keys()->sort()->values()->all())
        ->toBe(['security.settings.manage', 'security.settings.view']);
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

it('introduces no duplicate detection or M3 surface', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri());

    foreach (['duplicate', 'fingerprint', 'merge', 'clients', 'projects', 'matters',
        'deeds', 'documents', 'properties', 'warkah'] as $segment) {
        expect($uris->filter(fn (string $u): bool => str_contains($u, $segment)))->toBeEmpty();
    }
});
