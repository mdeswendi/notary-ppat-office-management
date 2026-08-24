<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\Enums\UserPermissionEffect;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Task\TaskVisibility;
use App\Models\Office;
use App\Models\Task;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor in a fresh Office holding the named permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function taskActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function taskPolicy(): TaskPolicy
{
    return app(TaskPolicy::class);
}

/*
|--------------------------------------------------------------------------
| OWN and ASSIGNED are separate predicates
|--------------------------------------------------------------------------
*/

/**
 * **The three tests below all work inside one Office, and that is not a
 * simplification.** `(created_by, office_id)` and `(assigned_to, office_id)` are
 * composite foreign keys, so neither a creator nor an assignee can sit in another
 * Office — the distinction between `OWN` and `ASSIGNED` is therefore always a
 * distinction *within* an Office, which is exactly where it needs to hold.
 */
it('reaches only what the actor raised at OWN', function (): void {
    // The decision M5.4 owed (lock section 11.2). The plan proposed defining OWN
    // as "created_by OR assigned_to"; that would have made ASSIGNED unable to
    // express anything OWN did not already — a ranking between scopes, which
    // D-028 forbids.
    [$actor, $office] = taskActor(['tasks.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    $raisedByMe = Task::factory()->createdBy($actor)->create();
    $heldByMe = Task::factory()->createdBy($colleague)->assignedTo($actor, $colleague)->create();

    expect(taskPolicy()->view($actor, $raisedByMe))->toBeTrue()
        // Assigned to them but raised by somebody else: OWN does not reach it.
        ->and(taskPolicy()->view($actor, $heldByMe))->toBeFalse();
});

it('reaches only what the actor holds at ASSIGNED', function (): void {
    [$actor, $office] = taskActor(['tasks.view'], DataScope::ASSIGNED);

    $colleague = User::factory()->for($office)->create();

    $heldByMe = Task::factory()->createdBy($colleague)->assignedTo($actor, $colleague)->create();
    $raisedByMe = Task::factory()->createdBy($actor)->create();

    expect(taskPolicy()->view($actor, $heldByMe))->toBeTrue()
        // Raised by them but held by nobody: ASSIGNED does not reach it.
        ->and(taskPolicy()->view($actor, $raisedByMe))->toBeFalse();
});

it('unions the two rather than ranking them', function (): void {
    // D-028: multiple grants union. Holding both gives exactly the "work I raised
    // or was given" the plan wanted — arrived at through the mechanism the model
    // already has, rather than by collapsing two predicates into one.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'tasks.view', DataScope::OWN);
    grantPermissionScope($actor, 'tasks.view', DataScope::ASSIGNED);
    $actor = $actor->fresh();

    $raised = Task::factory()->createdBy($actor)->create();
    $held = Task::factory()->createdBy($colleague)->assignedTo($actor, $colleague)->create();
    $neither = Task::factory()->createdBy($colleague)->create();

    expect(taskPolicy()->view($actor, $raised))->toBeTrue()
        ->and(taskPolicy()->view($actor, $held))->toBeTrue()
        ->and(taskPolicy()->view($actor, $neither))->toBeFalse();
});

it('reaches only its own office at OFFICE', function (): void {
    [$actor, $office] = taskActor(['tasks.view'], DataScope::OFFICE);

    $mine = Task::factory()->inOffice($office)->create();
    $theirs = Task::factory()->create();

    expect(taskPolicy()->view($actor, $mine))->toBeTrue()
        ->and(taskPolicy()->view($actor, $theirs))->toBeFalse();
});

it('reaches every office at ALL', function (): void {
    [$actor] = taskActor(['tasks.view'], DataScope::ALL);

    expect(taskPolicy()->view($actor, Task::factory()->create()))->toBeTrue()
        ->and(taskPolicy()->view($actor, Task::factory()->create()))->toBeTrue();
});

it('reaches nothing at TEAM, because no Team entity exists', function (): void {
    [$actor, $office] = taskActor([]);

    $permission = Permission::firstOrCreate(['name' => 'tasks.view', 'guard_name' => 'web']);
    $role = makeRole('TEAM_SCOPED_TASKS');
    $role->givePermissionTo($permission);
    grantScope($role, $permission, DataScope::TEAM);
    $actor->assignRole($role);
    $actor = $actor->fresh();

    $task = Task::factory()->inOffice($office)->create();

    expect(taskPolicy()->view($actor, $task))->toBeFalse()
        ->and(taskPolicy()->viewAny($actor))->toBeFalse();
});

it('grants nothing when a role carries the permission with no scope', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = makeRole('UNSCOPED_TASKS');
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'tasks.view', 'guard_name' => 'web']));
    $actor->assignRole($role);

    $task = Task::factory()->inOffice($office)->create();

    expect(taskPolicy()->view($actor->fresh(), $task))->toBeFalse();
});

it('lets an active DENY override win', function (): void {
    [$actor, $office] = taskActor(['tasks.view']);
    $task = Task::factory()->inOffice($office)->create();

    expect(taskPolicy()->view($actor, $task))->toBeTrue();

    makeOverride($actor, Permission::findByName('tasks.view'), UserPermissionEffect::DENY);

    expect(taskPolicy()->view($actor->fresh(), $task))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Creation
|--------------------------------------------------------------------------
*/

it('lets an actor raise work only in their own office', function (): void {
    [$actor, $office] = taskActor(['tasks.create']);
    $elsewhere = Office::factory()->create();

    expect(taskPolicy()->create($actor, $office->getKey()))->toBeTrue()
        ->and(taskPolicy()->create($actor))->toBeTrue()
        ->and(taskPolicy()->create($actor, $elsewhere->getKey()))->toBeFalse();
});

it('does not let ALL choose which office new work belongs to', function (): void {
    [$actor] = taskActor(['tasks.create'], DataScope::ALL);
    $elsewhere = Office::factory()->create();

    expect(taskPolicy()->create($actor))->toBeTrue()
        ->and(taskPolicy()->create($actor, $elsewhere->getKey()))->toBeFalse();
});

it('refuses creation to an actor holding only ASSIGNED', function (): void {
    // `assigned_to` is null until somebody is given the work, so the ASSIGNED
    // predicate is false of the record about to exist — the exclusion D-107 made
    // for Matter creation, where a new Matter has no PIC yet.
    [$actor] = taskActor(['tasks.create'], DataScope::ASSIGNED);

    expect(taskPolicy()->create($actor))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Seven independent capabilities
|--------------------------------------------------------------------------
*/

it('maps each ability to its own canonical capability', function (string $ability, string $capability): void {
    [$actor, $office] = taskActor([$capability]);
    $task = Task::factory()->inOffice($office)->create();

    expect(taskPolicy()->{$ability}($actor, $task))->toBeTrue("{$ability} / {$capability}");
})->with([
    ['view', 'tasks.view'],
    ['update', 'tasks.update'],
    ['assign', 'tasks.assign'],
    ['complete', 'tasks.complete'],
    ['reopen', 'tasks.reopen'],
    ['delete', 'tasks.delete'],
]);

it('lets no task capability imply another', function (): void {
    // The discipline D-091 applies to Project and D-110 to participation.
    // `tasks.reopen` in particular is its own code: the plan folded it into
    // completion, and the registry does not.
    $abilities = [
        'tasks.view' => 'view',
        'tasks.update' => 'update',
        'tasks.assign' => 'assign',
        'tasks.complete' => 'complete',
        'tasks.reopen' => 'reopen',
        'tasks.delete' => 'delete',
    ];

    foreach (array_keys($abilities) as $held) {
        [$actor, $office] = taskActor([$held]);
        $task = Task::factory()->inOffice($office)->create();

        foreach ($abilities as $capability => $ability) {
            expect(taskPolicy()->{$ability}($actor, $task))
                ->toBe($capability === $held, "holding {$held}, asked {$capability}");
        }
    }
});

it('lets commenting answer to tasks.view rather than tasks.update', function (): void {
    // A person who may read the task may say something about it. Requiring the
    // edit capability would mean only those who can change the work may discuss
    // it — and there is no `tasks.comment` code to invent.
    [$reader, $office] = taskActor(['tasks.view']);
    [$editor] = taskActor(['tasks.update']);

    $task = Task::factory()->inOffice($office)->create();

    expect(taskPolicy()->comment($reader, $task))->toBeTrue()
        ->and(taskPolicy()->comment($editor, $task))->toBeFalse();
});

it('refuses every ability to an actor holding nothing', function (): void {
    [$actor, $office] = taskActor([]);
    $task = Task::factory()->inOffice($office)->create();

    foreach (['view', 'update', 'assign', 'complete', 'reopen', 'delete', 'comment'] as $ability) {
        expect(taskPolicy()->{$ability}($actor, $task))->toBeFalse($ability);
    }

    expect(taskPolicy()->viewAny($actor))->toBeFalse()
        ->and(taskPolicy()->create($actor))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The registry and the matrix agree
|--------------------------------------------------------------------------
*/

it('registers no new task permission', function (): void {
    // All eight `tasks.*` codes have been canonical since the catalogue was
    // transcribed. The M5.4 plan proposed registering six as new and putting the
    // total at 183; they are not new, there are eight, and the total is unchanged.
    $tasks = array_values(array_filter(
        PermissionRegistry::all(),
        static fn (string $code): bool => str_starts_with($code, 'tasks.'),
    ));

    sort($tasks);

    expect($tasks)->toBe([
        'tasks.assign', 'tasks.complete', 'tasks.create', 'tasks.delete',
        'tasks.reopen', 'tasks.update', 'tasks.view', 'tasks.view_all',
    ])->and(PermissionRegistry::all())->toHaveCount(177);
});

it('offers all four assignable scopes for every task permission', function (string $permission): void {
    expect(app(PermissionScopeRules::class)->allowedFor($permission))
        ->toBe([DataScope::OWN, DataScope::ASSIGNED, DataScope::OFFICE, DataScope::ALL]);
})->with([
    'tasks.view', 'tasks.create', 'tasks.update',
    'tasks.assign', 'tasks.complete', 'tasks.reopen', 'tasks.delete',
]);

it('never allows TEAM for a task permission', function (): void {
    $rules = app(PermissionScopeRules::class);

    foreach (PermissionRegistry::all() as $permission) {
        if (! str_starts_with($permission, 'tasks.')) {
            continue;
        }

        expect($rules->permits($permission, DataScope::TEAM))->toBeFalse($permission);
    }
});

it('consults tasks.view_all nowhere', function (): void {
    // Superseded by Data Scope ALL for reach (D-090), exactly as
    // `projects.view_all` and the two `*.matters.view_all` codes are. A second
    // reach mechanism is what must not exist.
    $executable = '';

    foreach ([
        app_path('Policies/TaskPolicy.php'),
        app_path('Domains/Task/TaskVisibility.php'),
        app_path('Http/Controllers/Api/V1/TaskController.php'),
    ] as $file) {
        foreach (token_get_all(file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $executable .= is_array($token) ? $token[1] : $token;
        }
    }

    expect($executable)->not->toContain('tasks.view_all');
});

/*
|--------------------------------------------------------------------------
| The visibility query itself
|--------------------------------------------------------------------------
*/

it('selects nothing rather than everything when no predicate applies', function (): void {
    [$actor] = taskActor([]);

    Task::factory()->count(3)->create();

    $reachable = app(TaskVisibility::class)
        ->scope(Task::query(), $actor, resolveAccess($actor, 'tasks.view'))
        ->count();

    expect($reachable)->toBe(0);
});

it('introduces no scope ranking', function (): void {
    $methods = array_map(
        fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(TaskVisibility::class))->getMethods(),
    );

    foreach (['widest', 'max', 'rank', 'level', 'weight', 'priority', 'compare', 'strongest'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
});
