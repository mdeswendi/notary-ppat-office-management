<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Models\Office;
use App\Models\ServiceType;
use App\Models\User;
use App\Policies\ServiceTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * An actor in a fresh Office holding one permission at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function serviceTypeActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function serviceTypePolicy(): ServiceTypePolicy
{
    return app(ServiceTypePolicy::class);
}

/*
|--------------------------------------------------------------------------
| Each ability answers to its own capability
|--------------------------------------------------------------------------
*/

it('maps each ability to its own canonical capability', function (string $ability, string $permission): void {
    [$actor, $office] = serviceTypeActor([$permission]);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->{$ability}($actor, $serviceType))->toBeTrue();
})->with([
    ['view', 'master.services.view'],
    ['update', 'master.services.manage'],
    ['setActivation', 'master.services.manage'],
]);

it('does not let view imply manage', function (): void {
    [$actor, $office] = serviceTypeActor(['master.services.view']);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->view($actor, $serviceType))->toBeTrue()
        ->and(serviceTypePolicy()->update($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->setActivation($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->create($actor))->toBeFalse();
});

it('does not let manage imply view', function (): void {
    // The half that feels wrong, and it is deliberate: the registry defines two
    // codes, so an administrator who wants both grants both (D-098's reasoning).
    [$actor, $office] = serviceTypeActor(['master.services.manage']);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->update($actor, $serviceType))->toBeTrue()
        ->and(serviceTypePolicy()->view($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->viewAny($actor))->toBeFalse();
});

it('refuses every ability to an actor holding nothing', function (): void {
    [$actor, $office] = serviceTypeActor([]);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->viewAny($actor))->toBeFalse()
        ->and(serviceTypePolicy()->view($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->update($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->setActivation($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->create($actor))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Data Scope predicates
|--------------------------------------------------------------------------
*/

it('reaches a service type in the actor office at OFFICE scope', function (): void {
    [$actor, $office] = serviceTypeActor(['master.services.view'], DataScope::OFFICE);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->view($actor, $serviceType))->toBeTrue();
});

it('does not reach another office at OFFICE scope', function (): void {
    [$actor] = serviceTypeActor(['master.services.view'], DataScope::OFFICE);
    $foreign = ServiceType::factory()->for(Office::factory())->create();

    expect(serviceTypePolicy()->view($actor, $foreign))->toBeFalse();
});

it('reaches another office at ALL scope', function (): void {
    [$actor] = serviceTypeActor(['master.services.view'], DataScope::ALL);
    $foreign = ServiceType::factory()->for(Office::factory())->create();

    expect(serviceTypePolicy()->view($actor, $foreign))->toBeTrue();
});

it('fails closed for scopes that cannot describe a service type', function (DataScope $scope): void {
    // OWN would have to mean `created_by`, and the table deliberately has no such
    // column; ASSIGNED has no assignment entity; TEAM has no Team entity (D-042).
    // A grant carrying only one of these reaches nothing.
    [$actor, $office] = serviceTypeActor(['master.services.view', 'master.services.manage'], $scope);
    $serviceType = ServiceType::factory()->for($office)->create();

    expect(serviceTypePolicy()->viewAny($actor))->toBeFalse()
        ->and(serviceTypePolicy()->view($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->update($actor, $serviceType))->toBeFalse()
        ->and(serviceTypePolicy()->create($actor))->toBeFalse();
})->with([
    'OWN' => [DataScope::OWN],
    'ASSIGNED' => [DataScope::ASSIGNED],
    'TEAM' => [DataScope::TEAM],
]);

it('unions multiple grants rather than ranking them', function (): void {
    // Two roles, two scopes, one union — never "the wider of the two" (D-028).
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'master.services.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'master.services.view', DataScope::ALL);

    $actor = $actor->fresh();

    $own = ServiceType::factory()->for($office)->create();
    $foreign = ServiceType::factory()->for(Office::factory())->create();

    expect(serviceTypePolicy()->view($actor, $own))->toBeTrue()
        ->and(serviceTypePolicy()->view($actor, $foreign))->toBeTrue();
});

it('lets an active DENY override win', function (): void {
    [$actor, $office] = serviceTypeActor(['master.services.view']);
    $serviceType = ServiceType::factory()->for($office)->create();

    makeOverride(
        $actor,
        Permission::findByName('master.services.view'),
        UserPermissionEffect::DENY,
    );

    expect(serviceTypePolicy()->view($actor->fresh(), $serviceType))->toBeFalse();
});

it('ignores an expired DENY override', function (): void {
    [$actor, $office] = serviceTypeActor(['master.services.view']);
    $serviceType = ServiceType::factory()->for($office)->create();

    makeOverride(
        $actor,
        Permission::findByName('master.services.view'),
        UserPermissionEffect::DENY,
        expiresAt: now()->subDay(),
    );

    expect(serviceTypePolicy()->view($actor->fresh(), $serviceType))->toBeTrue();
});

it('reaches an inactive service type normally', function (): void {
    // `is_active` is catalogue availability, not a visibility rule. Somebody
    // administering the catalogue must see what they retired, and a record
    // referencing a retired service must stay readable.
    [$actor, $office] = serviceTypeActor(['master.services.view']);
    $retired = ServiceType::factory()->for($office)->inactive()->create();

    expect(serviceTypePolicy()->view($actor, $retired))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Creation lands in the actor's own Office
|--------------------------------------------------------------------------
*/

it('permits creation for an actor holding manage at OFFICE', function (): void {
    [$actor, $office] = serviceTypeActor(['master.services.manage'], DataScope::OFFICE);

    expect(serviceTypePolicy()->create($actor))->toBeTrue()
        ->and(serviceTypePolicy()->create($actor, $office->getKey()))->toBeTrue();
});

it('refuses cross-office creation even at ALL scope', function (): void {
    // `ALL` is reach over records that already exist, never authority to decide
    // which Office a new one belongs to — the line D-098 drew for participation.
    [$actor] = serviceTypeActor(['master.services.manage'], DataScope::ALL);
    $foreign = Office::factory()->create();

    expect(serviceTypePolicy()->create($actor))->toBeTrue()
        ->and(serviceTypePolicy()->create($actor, $foreign->getKey()))->toBeFalse();
});

it('refuses creation to a view-only actor at any scope', function (): void {
    [$actor] = serviceTypeActor(['master.services.view'], DataScope::ALL);

    expect(serviceTypePolicy()->create($actor))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Scope rules and the registry
|--------------------------------------------------------------------------
*/

it('offers exactly OFFICE and ALL for the service type permissions', function (string $permission): void {
    $rules = app(PermissionScopeRules::class);

    expect($rules->allowedFor($permission))->toBe([DataScope::OFFICE, DataScope::ALL])
        ->and($rules->permits($permission, DataScope::OWN))->toBeFalse()
        ->and($rules->permits($permission, DataScope::ASSIGNED))->toBeFalse()
        ->and($rules->permits($permission, DataScope::TEAM))->toBeFalse();
})->with(['master.services.view', 'master.services.manage']);

it('leaves the other master data families on the permissive default', function (): void {
    // Their domains are still undesigned; narrowing them would repeat the mistake
    // the Service Type entry corrects, one module across.
    //
    // **Narrowed at M4.6**, which gave `master.workflows.*` real tables and the
    // same `OFFICE`/`ALL` treatment (D-111). Excluding it here is not weakening
    // the guard: the claim this file owns is that a family *without* a designed
    // domain keeps the permissive default, and workflow now has one. Its own
    // narrowing is asserted in `WorkflowTemplateSchemaTest`.
    $rules = app(PermissionScopeRules::class);

    $others = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'master.')
            && ! str_starts_with($code, 'master.services.')
            && ! str_starts_with($code, 'master.workflows.'),
    ));

    expect($others)->not->toBeEmpty();

    foreach ($others as $permission) {
        expect($rules->allowedFor($permission))->toBe(
            [DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL],
            $permission,
        );
    }
});

it('adds no permission to the canonical registry', function (): void {
    // The Service Type surface is exactly the two codes the registry already
    // carried. M4.1 invents none, and the global total stays where
    // `PermissionRegistryTest` pins it.
    $services = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'master.services.'),
    ));

    sort($services);

    expect($services)->toBe(['master.services.manage', 'master.services.view']);
});

/*
|--------------------------------------------------------------------------
| Architecture guards
|--------------------------------------------------------------------------
*/

/**
 * Executable code with comments removed.
 *
 * The banned constructs are named in docblocks throughout this codebase —
 * including in the very files scanned below, which explain why they are absent —
 * so a raw string search would fail on its own documentation. The canonical
 * security scan strips comments for exactly this reason.
 */
function serviceTypeCode(string $relativePath): string
{
    $stripped = '';

    foreach (token_get_all(file_get_contents(app_path($relativePath))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

it('strips comments before scanning', function (): void {
    // Proves the helper does what the two scans below depend on: each of these
    // files discusses the forbidden constructs in prose.
    expect(serviceTypeCode('Policies/ServiceTypePolicy.php'))->not->toContain('SUPER_ADMIN receives no bypass');
});

it('reads no role name and no permission code as authority', function (): void {
    $sources = [
        serviceTypeCode('Policies/ServiceTypePolicy.php'),
        serviceTypeCode('Domains/MasterData/ServiceTypeVisibility.php'),
        serviceTypeCode('Models/ServiceType.php'),
    ];

    foreach ($sources as $source) {
        expect($source)->not->toContain('hasRole(')
            ->and($source)->not->toContain('hasPermissionTo(')
            ->and($source)->not->toContain('getAllPermissions(')
            ->and($source)->not->toContain('Gate::allows(')
            ->and($source)->not->toContain('SUPER_ADMIN');
    }
});

it('introduces no scope ranking', function (): void {
    $visibility = serviceTypeCode('Domains/MasterData/ServiceTypeVisibility.php');

    expect($visibility)->not->toContain('maxScope')
        ->and($visibility)->not->toContain('widest')
        ->and($visibility)->not->toContain('rank(');
});

it('exposes no master data service type http surface', function (): void {
    // **Narrowed at M4.4, not deleted.** M4.1 asserted there was no Service Type
    // route at all. M4.4 adds a **Matter-scoped** options endpoint — authorized by
    // the Matter capability and bounded to the actor's own Office, domain, and
    // active entries (D-109) — which is not the master-data surface this guard was
    // protecting.
    //
    // What stays true, and is the part that mattered: there is still no
    // `master/service-types` CRUD surface, so the deferred-badge question for the
    // twelve sibling `master.*` codes stays closed.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'master/service-types')
            || str_contains($uri, 'service_types'));

    expect($routes)->toBeEmpty();
});

it('introduces no master workflow surface', function (): void {
    // **Narrowed at M4.4**, which ships the Matter product surface (D-109), and
    // again at M4.7, which gives a Matter's *running* workflow three routes
    // (D-112). The claim this file owns is narrower and still true: M4.1 built
    // the Service Type catalogue and no **master-data workflow surface** exists
    // for configuring templates — `master.workflows.*` are registered, scoped at
    // M4.6, and reachable through no endpoint.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'workflow')
            || str_contains($uri, 'workflow-templates')
            || str_contains($uri, 'master/'));

    expect($routes)->toBeEmpty();
});
