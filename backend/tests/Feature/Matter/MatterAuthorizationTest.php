<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use App\Policies\MatterPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * An actor in a fresh Office holding the named permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function matterActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function matterPolicy(): MatterPolicy
{
    return app(MatterPolicy::class);
}

/**
 * A Matter in the given Office, with a Project of the same Office.
 */
function matterIn(Office $office, MatterDomain $domain = MatterDomain::NOTARY, array $attributes = []): Matter
{
    $project = Project::factory()->for($office)->create();

    return Matter::factory()->for($project)->domain($domain)->create($attributes);
}

/**
 * Executable code with comments removed — the banned constructs are named in
 * docblocks throughout these files.
 */
function matterCode(string $relativePath): string
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

/*
|--------------------------------------------------------------------------
| Each ability answers to its own capability
|--------------------------------------------------------------------------
*/

it('maps each ability to its own canonical capability', function (string $ability, string $capability): void {
    foreach (MatterDomain::cases() as $domain) {
        [$actor, $office] = matterActor([$domain->permission($capability)]);
        $matter = matterIn($office, $domain);

        expect(matterPolicy()->{$ability}($actor, $matter, $domain))->toBeTrue("{$domain->value} {$ability}");
    }
})->with([
    ['view', 'view'],
    ['update', 'update'],
    ['assign', 'assign'],
    ['changeStage', 'change_stage'],
    ['complete', 'complete'],
    ['cancel', 'cancel'],
]);

it('lets no lifecycle capability imply another', function (): void {
    // Seven codes, seven independent answers. No umbrella `manage` exists.
    $capabilities = ['view', 'update', 'assign', 'change_stage', 'complete', 'cancel'];
    $abilities = [
        'view' => 'view', 'update' => 'update', 'assign' => 'assign',
        'change_stage' => 'changeStage', 'complete' => 'complete', 'cancel' => 'cancel',
    ];

    foreach ($capabilities as $held) {
        [$actor, $office] = matterActor([MatterDomain::NOTARY->permission($held)]);
        $matter = matterIn($office);

        foreach ($abilities as $capability => $ability) {
            $expected = $capability === $held;

            expect(matterPolicy()->{$ability}($actor, $matter, MatterDomain::NOTARY))
                ->toBe($expected, "holding {$held}, asked {$capability}");
        }
    }
});

it('refuses every ability to an actor holding nothing', function (): void {
    [$actor, $office] = matterActor([]);
    $matter = matterIn($office);

    expect(matterPolicy()->viewAny($actor, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->view($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->update($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->assign($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->changeStage($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->complete($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->cancel($actor, $matter, MatterDomain::NOTARY))->toBeFalse();
});

it('exposes no archive, restore, or delete ability', function (): void {
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(MatterPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    foreach (['archive', 'restore', 'delete', 'forceDelete'] as $absent) {
        expect($methods)->not->toContain($absent);
    }
});

/*
|--------------------------------------------------------------------------
| The domain namespace comes from the caller, not the row
|--------------------------------------------------------------------------
*/

it('does not let a notary grant authorize a ppat matter', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view']);
    $ppatMatter = matterIn($office, MatterDomain::PPAT);

    expect(matterPolicy()->view($actor, $ppatMatter, MatterDomain::PPAT))->toBeFalse();
});

it('does not let a ppat grant authorize a notary matter', function (): void {
    [$actor, $office] = matterActor(['ppat.matters.view']);
    $notaryMatter = matterIn($office, MatterDomain::NOTARY);

    expect(matterPolicy()->view($actor, $notaryMatter, MatterDomain::NOTARY))->toBeFalse();
});

it('refuses when the supplied domain does not match the persisted one', function (): void {
    // The record must belong to the surface that was addressed. At M4.4 the route
    // binding turns this into the canonical 404; here it is a Policy refusal, so
    // the invariant exists before the route does.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach (['notary.matters.view', 'ppat.matters.view'] as $permission) {
        grantPermissionScope($actor, $permission, DataScope::OFFICE);
    }
    $actor = $actor->fresh();

    $notaryMatter = matterIn($office, MatterDomain::NOTARY);

    expect(matterPolicy()->view($actor, $notaryMatter, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $notaryMatter, MatterDomain::PPAT))->toBeFalse();
});

it('never selects a permission namespace from persisted row data', function (): void {
    // D-101's rule, asserted on the source: the Policy must not read
    // `$matter->domain` to decide which permission to resolve. It compares the
    // persisted domain against the supplied one, which is a different act.
    $policy = matterCode('Policies/MatterPolicy.php');

    expect($policy)->not->toContain('$matter->domain->permission')
        ->and($policy)->not->toContain('$matter->domain->permissionNamespace')
        ->and(substr_count($policy, '$matter->domain'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Data Scope predicates
|--------------------------------------------------------------------------
*/

it('reaches a matter the actor created at OWN scope', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view'], DataScope::OWN);
    $mine = matterIn($office, MatterDomain::NOTARY, ['created_by' => $actor->getKey()]);
    $other = matterIn($office);

    expect(matterPolicy()->view($actor, $mine, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $other, MatterDomain::NOTARY))->toBeFalse();
});

it('reaches a matter the actor is pic of at ASSIGNED scope', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view'], DataScope::ASSIGNED);
    $mine = matterIn($office, MatterDomain::NOTARY, ['pic_user_id' => $actor->getKey()]);
    $other = matterIn($office);

    expect(matterPolicy()->view($actor, $mine, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $other, MatterDomain::NOTARY))->toBeFalse();
});

it('reaches the office at OFFICE scope and no further', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view'], DataScope::OFFICE);
    $own = matterIn($office);
    $foreign = matterIn(Office::factory()->create());

    expect(matterPolicy()->view($actor, $own, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $foreign, MatterDomain::NOTARY))->toBeFalse();
});

it('reaches another office at ALL scope', function (): void {
    [$actor] = matterActor(['notary.matters.view'], DataScope::ALL);
    $foreign = matterIn(Office::factory()->create());

    expect(matterPolicy()->view($actor, $foreign, MatterDomain::NOTARY))->toBeTrue();
});

it('fails closed at TEAM scope', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view', 'notary.matters.update'], DataScope::TEAM);
    $matter = matterIn($office);

    expect(matterPolicy()->viewAny($actor, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->view($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->update($actor, $matter, MatterDomain::NOTARY))->toBeFalse();
});

it('unions multiple grants rather than ranking them', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.matters.view', DataScope::OWN);
    grantPermissionScope($actor, 'notary.matters.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $mine = matterIn($office, MatterDomain::NOTARY, ['created_by' => $actor->getKey()]);
    $colleagues = matterIn($office);
    $foreign = matterIn(Office::factory()->create());

    expect(matterPolicy()->view($actor, $mine, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $colleagues, MatterDomain::NOTARY))->toBeTrue()
        ->and(matterPolicy()->view($actor, $foreign, MatterDomain::NOTARY))->toBeFalse();
});

it('lets an active DENY override win', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view']);
    $matter = matterIn($office);

    makeOverride($actor, Permission::findByName('notary.matters.view'), UserPermissionEffect::DENY);

    expect(matterPolicy()->view($actor->fresh(), $matter, MatterDomain::NOTARY))->toBeFalse();
});

it('ignores an expired DENY override', function (): void {
    [$actor, $office] = matterActor(['notary.matters.view']);
    $matter = matterIn($office);

    makeOverride(
        $actor,
        Permission::findByName('notary.matters.view'),
        UserPermissionEffect::DENY,
        expiresAt: now()->subDay(),
    );

    expect(matterPolicy()->view($actor->fresh(), $matter, MatterDomain::NOTARY))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Parent Project reach does not widen Matter reach
|--------------------------------------------------------------------------
*/

it('gives no matter access to an actor who can only reach the parent project', function (): void {
    // D-100's core rule. Reaching a Project confers nothing over the Matters
    // beneath it — otherwise `projects.view` would silently carry Notary and PPAT
    // work visibility nobody named.
    [$actor, $office] = matterActor(['projects.view', 'projects.update'], DataScope::OFFICE);
    $matter = matterIn($office);

    expect(matterPolicy()->viewAny($actor, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->view($actor, $matter, MatterDomain::NOTARY))->toBeFalse()
        ->and(matterPolicy()->update($actor, $matter, MatterDomain::NOTARY))->toBeFalse();
});

it('consults no project relation in matter visibility', function (): void {
    // The guard for the rule above: a `whereHas('project', …)` branch would make
    // Project reach a superset of Matter reach.
    $visibility = matterCode('Domains/Matter/MatterVisibility.php');

    expect($visibility)->not->toContain('project')
        ->and($visibility)->not->toContain('whereHas');
});

it('introduces no stage assignment branch', function (): void {
    // When M4.7 adds `assigned_user_id`, extending ASSIGNED to cover it would be
    // a new grant wearing an existing scope's name (D-100).
    $visibility = matterCode('Domains/Matter/MatterVisibility.php');

    expect($visibility)->not->toContain('assigned_user_id')
        ->and($visibility)->not->toContain('stage');
});

it('introduces no scope ranking', function (): void {
    $visibility = matterCode('Domains/Matter/MatterVisibility.php');

    expect($visibility)->not->toContain('maxScope')
        ->and($visibility)->not->toContain('widest')
        ->and($visibility)->not->toContain('rank(');
});

/*
|--------------------------------------------------------------------------
| view_all is not authority
|--------------------------------------------------------------------------
*/

it('gives an actor holding only view_all no matter reach', function (): void {
    // Registered for compatibility, superseded by Data Scope ALL (D-090). A
    // second reach mechanism is exactly what must not exist.
    foreach (MatterDomain::cases() as $domain) {
        [$actor, $office] = matterActor([$domain->permission('view_all')], DataScope::ALL);
        $matter = matterIn($office, $domain);

        expect(matterPolicy()->viewAny($actor, $domain))->toBeFalse("{$domain->value} viewAny")
            ->and(matterPolicy()->view($actor, $matter, $domain))->toBeFalse("{$domain->value} view");
    }
});

it('consults no view_all code in the matter policy', function (): void {
    expect(matterCode('Policies/MatterPolicy.php'))->not->toContain('view_all');
});

/*
|--------------------------------------------------------------------------
| Creation
|--------------------------------------------------------------------------
*/

it('permits creation with the domain create code and project view', function (): void {
    foreach (MatterDomain::cases() as $domain) {
        [$actor, $office] = matterActor([$domain->permission('create'), 'projects.view']);
        $project = Project::factory()->for($office)->create();

        expect(matterPolicy()->create($actor, $domain, $project))->toBeTrue($domain->value);
    }
});

it('refuses creation without the domain create code', function (): void {
    [$actor, $office] = matterActor(['notary.matters.update', 'projects.view']);
    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeFalse();
});

it('refuses creation in the other domain', function (): void {
    [$actor, $office] = matterActor(['notary.matters.create', 'projects.view']);
    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::PPAT, $project))->toBeFalse();
});

it('requires the parent project to be reachable under projects.view', function (): void {
    [$actor, $office] = matterActor(['notary.matters.create']);
    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeFalse();
});

it('does not require projects.update to create a matter', function (): void {
    // Reading a Project is the minimum coherent proof somebody may open work
    // beneath it. Requiring update would demand the right to edit a Project in
    // order to add work to it.
    [$actor, $office] = matterActor(['notary.matters.create', 'projects.view']);
    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeTrue();
});

it('refuses creation when only ASSIGNED is granted', function (): void {
    // A new Matter has no PIC, so `pic_user_id == actor.id` is false for the very
    // record being created (D-107). Not an exception to the union rule.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.matters.create', DataScope::ASSIGNED);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeFalse();
});

it('permits creation when ASSIGNED is joined by OFFICE', function (): void {
    // The union rule is untouched: OFFICE matches the record about to exist.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.matters.create', DataScope::ASSIGNED);
    grantPermissionScope($actor, 'notary.matters.create', DataScope::OFFICE);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeTrue();
});

it('refuses creation when only TEAM is granted', function (): void {
    [$actor, $office] = matterActor(['notary.matters.create', 'projects.view'], DataScope::TEAM);
    $project = Project::factory()->for($office)->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeFalse();
});

it('refuses cross-office creation even at ALL scope', function (): void {
    // ALL is cross-office reach over existing Matters; it is not authority to
    // file new work into another Office (D-097's ruling, one domain across).
    [$actor] = matterActor(['notary.matters.create', 'projects.view'], DataScope::ALL);
    $foreignProject = Project::factory()->for(Office::factory())->create();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $foreignProject))->toBeFalse();
});

it('refuses creation under an archived project', function (): void {
    // ProjectVisibility excludes soft-deleted Projects by default, so this falls
    // out of using the canonical reach check rather than a separate lookup.
    [$actor, $office] = matterActor(['notary.matters.create', 'projects.view']);
    $project = Project::factory()->for($office)->create();
    $project->delete();

    expect(matterPolicy()->create($actor, MatterDomain::NOTARY, $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Scope rules and the registry
|--------------------------------------------------------------------------
*/

it('offers all four assignable scopes for each actionable matter code', function (string $permission): void {
    $rules = app(PermissionScopeRules::class);

    expect($rules->allowedFor($permission))
        ->toBe([DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL])
        ->and($rules->permits($permission, DataScope::TEAM))->toBeFalse();
})->with([
    'notary.matters.view', 'notary.matters.create', 'notary.matters.update',
    'notary.matters.assign', 'notary.matters.change_stage', 'notary.matters.complete',
    'notary.matters.cancel',
    'ppat.matters.view', 'ppat.matters.create', 'ppat.matters.update',
    'ppat.matters.assign', 'ppat.matters.change_stage', 'ppat.matters.complete',
    'ppat.matters.cancel',
]);

it('never offers TEAM for a matter permission', function (): void {
    $rules = app(PermissionScopeRules::class);

    foreach (array_keys($rules->all()) as $permission) {
        if (! str_contains($permission, '.matters.')) {
            continue;
        }

        expect($rules->permits($permission, DataScope::TEAM))->toBeFalse($permission);
    }
});

it('keeps view_all out of the matter scope rules', function (): void {
    // Listing it would assert it is a live capability with meaningful scopes,
    // which is precisely what supersession denies (D-090). It keeps the generic
    // default, exactly as `projects.view_all` does.
    $rules = file_get_contents(app_path('Domains/Authorization/PermissionScopeRules.php'));

    $matterBlock = substr(
        $rules,
        (int) strpos($rules, 'MATTER_DOMAIN = ['),
        (int) strpos($rules, '];', (int) strpos($rules, 'MATTER_DOMAIN = [')) - (int) strpos($rules, 'MATTER_DOMAIN = ['),
    );

    expect($matterBlock)->not->toContain('view_all');
});

it('adds no permission to the canonical registry', function (): void {
    $matters = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, '.matters.'),
    ));

    sort($matters);

    expect($matters)->toBe([
        'notary.matters.assign', 'notary.matters.cancel', 'notary.matters.change_stage',
        'notary.matters.complete', 'notary.matters.create', 'notary.matters.update',
        'notary.matters.view', 'notary.matters.view_all',
        'ppat.matters.assign', 'ppat.matters.cancel', 'ppat.matters.change_stage',
        'ppat.matters.complete', 'ppat.matters.create', 'ppat.matters.update',
        'ppat.matters.view', 'ppat.matters.view_all',
    ]);
});

it('registers no generic matters namespace', function (): void {
    $generic = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'matters.'),
    ));

    expect($generic)->toBe([]);
});

it('registers no matter participation permission yet', function (): void {
    // Those four belong to M4.5.
    $participation = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'matters.parties.'),
    ));

    expect($participation)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Architecture guards
|--------------------------------------------------------------------------
*/

it('reads no role name and no permission code as authority', function (): void {
    foreach ([
        'Policies/MatterPolicy.php',
        'Domains/Matter/MatterVisibility.php',
        'Models/Matter.php',
    ] as $path) {
        $source = matterCode($path);

        expect($source)->not->toContain('hasRole(')
            ->and($source)->not->toContain('hasPermissionTo(')
            ->and($source)->not->toContain('getAllPermissions(')
            ->and($source)->not->toContain('Gate::allows(')
            ->and($source)->not->toContain('SUPER_ADMIN');
    }
});

it('exposes no matter surface beyond the milestone that owns it', function (): void {
    // **Narrowed at M4.4, not deleted.** M4.2 asserted there was no Matter route
    // at all, which was true while it shipped schema and authorization only. M4.4
    // ships the product surface and pins its exact inventory in
    // `MatterManagementTest`. What stays true here is the boundary this guard was
    // really protecting: no participation surface (M4.5), no workflow or stage
    // surface (M4.6 / M4.7), and no archive or restore, which M4 does not have.
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri());

    foreach ([
        'matters/{matter}/parties', 'matter-parties',
        'matters/{matter}/stage', 'matters/{matter}/workflow',
        'matters/{matter}/archive', 'matters/{matter}/restore', 'matters/archived',
    ] as $absent) {
        expect($uris->filter(fn (string $uri): bool => str_contains($uri, $absent)))->toBeEmpty($absent);
    }

    // And no destructive verb: Matter records are never deleted (D-102).
    $destructive = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'matters')
            && in_array('DELETE', $route->methods(), true));

    expect($destructive)->toBeEmpty();
});

it('copies no party or legal identity into the matter aggregate', function (): void {
    foreach (['nik', 'npwp', 'tax_id', 'party_id', 'deed_number', 'warkah', 'property_id'] as $column) {
        expect(Schema::hasColumn('matters', $column))->toBeFalse($column);
    }
});
