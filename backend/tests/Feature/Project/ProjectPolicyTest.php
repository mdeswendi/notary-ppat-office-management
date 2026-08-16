<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function projectActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function projectPolicy(): ProjectPolicy
{
    return app(ProjectPolicy::class);
}

/*
|--------------------------------------------------------------------------
| Each ability answers to its own capability
|--------------------------------------------------------------------------
*/

it('maps each ability to its own canonical capability', function (
    string $ability,
    string $permission,
): void {
    [$actor, $office] = projectActor([$permission]);
    $project = Project::factory()->for($office)->create();

    expect(projectPolicy()->{$ability}($actor, $project))->toBeTrue();

    [$without, $otherOffice] = projectActor(['projects.view']);
    $otherProject = Project::factory()->for($otherOffice)->create();

    expect(projectPolicy()->{$ability}($without, $otherProject))
        ->toBe($permission === 'projects.view');
})->with([
    ['view', 'projects.view'],
    ['update', 'projects.update'],
    ['assign', 'projects.assign'],
    ['changeStatus', 'projects.change_status'],
    ['archive', 'projects.archive'],
]);

it('does not let update imply assign or change status', function (): void {
    // D-091, and the reason the registry has always carried separate codes:
    // reassigning work is a different act from correcting a title.
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    expect(projectPolicy()->update($actor, $project))->toBeTrue()
        ->and(projectPolicy()->assign($actor, $project))->toBeFalse()
        ->and(projectPolicy()->changeStatus($actor, $project))->toBeFalse()
        ->and(projectPolicy()->archive($actor, $project))->toBeFalse();
});

it('does not let assign or change status imply update', function (): void {
    [$assigner, $office] = projectActor(['projects.assign']);
    $project = Project::factory()->for($office)->create();

    expect(projectPolicy()->assign($assigner, $project))->toBeTrue()
        ->and(projectPolicy()->update($assigner, $project))->toBeFalse()
        ->and(projectPolicy()->changeStatus($assigner, $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Scope is honoured by the record abilities, not just the list
|--------------------------------------------------------------------------
*/

it('refuses an ability on a record the scope does not reach', function (): void {
    [$actor] = projectActor(['projects.update']);
    $elsewhere = Project::factory()->create();

    expect(projectPolicy()->update($actor, $elsewhere))->toBeFalse();
});

it('honours OWN and ASSIGNED on record abilities', function (): void {
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.update', DataScope::OWN);

    $mine = Project::factory()->for($elsewhere)->createdBy($actor)->create();
    $theirs = Project::factory()->for($elsewhere)->create();

    expect(projectPolicy()->update($actor->fresh(), $mine))->toBeTrue()
        ->and(projectPolicy()->update($actor->fresh(), $theirs))->toBeFalse();
});

it('refuses viewAny when no scope reaches a project', function (): void {
    [$teamOnly] = projectActor(['projects.view'], DataScope::TEAM);
    [$none] = projectActor([]);
    [$office] = projectActor(['projects.view']);

    expect(projectPolicy()->viewAny($teamOnly))->toBeFalse()
        ->and(projectPolicy()->viewAny($none))->toBeFalse()
        ->and(projectPolicy()->viewAny($office))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Creation is judged against the destination Office
|--------------------------------------------------------------------------
*/

it('confines creation to the actor office unless the grant is ALL', function (): void {
    [$officeScoped, $office] = projectActor(['projects.create']);
    $elsewhere = Office::factory()->create();

    expect(projectPolicy()->create($officeScoped, $office->getKey()))->toBeTrue()
        ->and(projectPolicy()->create($officeScoped, $elsewhere->getKey()))->toBeFalse();

    [$all, $allOffice] = projectActor(['projects.create'], DataScope::ALL);

    expect(projectPolicy()->create($all, $allOffice->getKey()))->toBeTrue()
        ->and(projectPolicy()->create($all, $elsewhere->getKey()))->toBeTrue();
});

it('does not let OWN or ASSIGNED fabricate cross-office creation rights', function (): void {
    // Neither predicate has a record to match against at creation time. Reading
    // them as "may create anything they will own" would let an office-scoped
    // actor create anywhere, inverting the boundary.
    foreach ([DataScope::OWN, DataScope::ASSIGNED] as $scope) {
        [$actor] = projectActor(['projects.create'], $scope);
        $elsewhere = Office::factory()->create();

        expect(projectPolicy()->create($actor, $elsewhere->getKey()))->toBeFalse($scope->value);
    }
});

/*
|--------------------------------------------------------------------------
| Restore reaches an archived record — D-093
|--------------------------------------------------------------------------
*/

it('lets restore reach a soft-deleted project that other abilities cannot', function (): void {
    // Without this the permission would be unusable by construction: the
    // ordinary predicate excludes soft-deleted rows, so `restore` would answer
    // false for every record it exists to govern.
    [$actor, $office] = projectActor(['projects.view', 'projects.restore']);
    $project = Project::factory()->for($office)->create();
    $project->delete();

    expect(projectPolicy()->restore($actor, $project))->toBeTrue()
        ->and(projectPolicy()->view($actor, $project))->toBeFalse();
});

it('still applies scope to restore', function (): void {
    // Reaching archived rows widens which records are considered, never which
    // scopes apply.
    [$actor] = projectActor(['projects.restore']);
    $elsewhere = Project::factory()->create();
    $elsewhere->delete();

    expect(projectPolicy()->restore($actor, $elsewhere))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| view_all is not a reach mechanism — D-090
|--------------------------------------------------------------------------
*/

it('grants no cross-office reach through projects.view_all', function (): void {
    // The decision this pins: view_all stays registered for compatibility and is
    // superseded by Data Scope ALL. If it ever became a second reach mechanism,
    // two answers to one question would disagree and the looser would win.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'projects.view_all', DataScope::ALL);

    $elsewhere = Project::factory()->create();

    expect(projectPolicy()->view($actor->fresh(), $elsewhere))->toBeFalse()
        ->and(projectPolicy()->viewAny($actor->fresh()))->toBeTrue();
});

it('never mentions view_all in the Project authorization surface', function (): void {
    foreach ([
        app_path('Policies/ProjectPolicy.php'),
        app_path('Domains/Project/ProjectVisibility.php'),
    ] as $path) {
        $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        expect($code)->not->toContain('view_all');
    }
});

/*
|--------------------------------------------------------------------------
| Authorization architecture — no forbidden backend authority
|--------------------------------------------------------------------------
*/

it('routes every Project decision through the resolver and the predicate', function (): void {
    foreach ([
        app_path('Policies/ProjectPolicy.php'),
        app_path('Domains/Project/ProjectVisibility.php'),
    ] as $path) {
        $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        foreach ([
            'hasPermissionTo(', 'getAllPermissions(', 'Gate::allows(', 'Gate::check(',
            'hasRole(', 'hasAnyRole(', 'SUPER_ADMIN', "->can('", '->can("',
        ] as $forbidden) {
            expect($code)->not->toContain($forbidden, "{$path} :: {$forbidden}");
        }
    }
});

/*
|--------------------------------------------------------------------------
| Scope rules metadata
|--------------------------------------------------------------------------
*/

it('offers exactly the four Project scopes in the matrix', function (string $permission): void {
    $allowed = array_map(
        fn (DataScope $scope): string => $scope->value,
        app(PermissionScopeRules::class)->allowedFor($permission),
    );

    expect($allowed)->toBe(['OWN', 'ASSIGNED', 'OFFICE', 'ALL']);
})->with([
    'projects.view', 'projects.create', 'projects.update', 'projects.assign',
    'projects.change_status', 'projects.archive', 'projects.restore',
]);

it('never offers TEAM for a Project permission', function (): void {
    $rules = app(PermissionScopeRules::class);

    foreach (array_keys($rules->all()) as $permission) {
        if (! str_starts_with($permission, 'projects.')) {
            continue;
        }

        expect($rules->permits($permission, DataScope::TEAM))->toBeFalse($permission);
    }
});

it('adds no permission to the canonical registry', function (): void {
    expect(count(PermissionRegistry::all()))->toBe(171);
});
