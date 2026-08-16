<?php

use App\Domains\Authorization\EffectiveAccess;
use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Project\ProjectVisibility;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * The Projects one actor actually reaches through `projects.view`.
 *
 * Goes through the real resolver rather than a hand-built EffectiveAccess, so
 * these tests exercise the whole chain the Policy uses.
 *
 * @return array<int, string>
 */
function reachableProjectIds(User $actor): array
{
    $access = app(EffectiveAccessResolver::class)->resolve($actor->fresh(), 'projects.view');

    return app(ProjectVisibility::class)
        ->scope(Project::query(), $actor->fresh(), $access)
        ->orderBy('title')
        ->pluck('id')
        ->all();
}

/*
|--------------------------------------------------------------------------
| Each predicate, proven independently
|--------------------------------------------------------------------------
*/

it('reaches a project the actor created when granted OWN', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OWN);

    $mine = Project::factory()->for($office)->createdBy($actor)->create();

    expect(reachableProjectIds($actor))->toBe([$mine->id]);
});

it('does not let OWN reach a colleague project merely because the office matches', function (): void {
    // The distinction that makes OWN worth having. If this passed, OWN would be
    // OFFICE under another name and every OWN grant would silently be wider than
    // the administrator intended.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OWN);

    Project::factory()->for($office)->createdBy($colleague)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('reaches a project the actor is PIC of when granted ASSIGNED', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::ASSIGNED);

    $assigned = Project::factory()->for($office)->assignedTo($actor)->create();

    expect(reachableProjectIds($actor))->toBe([$assigned->id]);
});

it('does not let ASSIGNED mean creator', function (): void {
    // OWN and ASSIGNED describe different relationships to a record, not
    // different amounts of power. A person may open work they never run.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::ASSIGNED);

    Project::factory()->for($office)->createdBy($actor)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('does not let OWN mean PIC', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OWN);

    Project::factory()->for($office)->assignedTo($actor)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('does not let ASSIGNED reach an unassigned project', function (): void {
    // A null pic_user_id never matches an actor id. Fail-closed by construction
    // rather than by a special case.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::ASSIGNED);

    Project::factory()->for($office)->create(['pic_user_id' => null]);

    expect(reachableProjectIds($actor))->toBe([]);
});

it('reaches every project in the actor office when granted OFFICE', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    $theirs = Project::factory()->for($office)->createdBy($colleague)->create(['title' => 'A']);
    $mine = Project::factory()->for($office)->createdBy($actor)->create(['title' => 'B']);
    Project::factory()->create(['title' => 'C']);

    expect(reachableProjectIds($actor))->toBe([$theirs->id, $mine->id]);
});

it('does not let OFFICE reach another office', function (): void {
    $actor = User::factory()->for(Office::factory())->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    Project::factory()->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('reaches projects in every office when granted ALL', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::ALL);

    $here = Project::factory()->for($office)->create(['title' => 'A']);
    $elsewhere = Project::factory()->create(['title' => 'B']);

    expect(reachableProjectIds($actor))->toBe([$here->id, $elsewhere->id]);
});

it('grants no project reach for TEAM', function (): void {
    // No Team entity exists (D-042), so a grant carrying only TEAM must reach
    // nothing rather than quietly collapsing to OFFICE.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::TEAM);

    Project::factory()->for($office)->createdBy($actor)->assignedTo($actor)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Union, not ranking — D-028
|--------------------------------------------------------------------------
*/

it('unions OWN and ASSIGNED into both sets, not the wider one', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.view', DataScope::ASSIGNED);

    $created = Project::factory()->for($office)->createdBy($actor)->create(['title' => 'A']);
    $assigned = Project::factory()->for($office)->assignedTo($actor)->create(['title' => 'B']);
    Project::factory()->for($office)->createdBy($colleague)->create(['title' => 'C']);

    // Both, and only both. Neither scope was discarded and neither was widened.
    expect(reachableProjectIds($actor))->toBe([$created->id, $assigned->id]);
});

it('unions OWN with OFFICE without discarding either', function (): void {
    // The case a "widest scope wins" implementation would get right by accident
    // and a "narrowest wins" implementation would get wrong: OFFICE subsumes the
    // actor's own office projects, but OWN must still reach one created
    // elsewhere.
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    $ownedElsewhere = Project::factory()->for($elsewhere)->createdBy($actor)->create(['title' => 'A']);
    $inMyOffice = Project::factory()->for($office)->create(['title' => 'B']);
    Project::factory()->for($elsewhere)->create(['title' => 'C']);

    expect(reachableProjectIds($actor))->toBe([$ownedElsewhere->id, $inMyOffice->id]);
});

it('lets ALL subsume the others without special-casing them', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.view', DataScope::ALL);

    $a = Project::factory()->create(['title' => 'A']);
    $b = Project::factory()->for($office)->create(['title' => 'B']);

    expect(reachableProjectIds($actor))->toBe([$a->id, $b->id]);
});

/*
|--------------------------------------------------------------------------
| Fail closed
|--------------------------------------------------------------------------
*/

it('reaches nothing when the permission is not held at all', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    Project::factory()->for($office)->createdBy($actor)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('reaches nothing when a role grant carries no Data Scope', function (): void {
    // D-039: scope metadata is what makes a grant usable. A permission attached
    // to a role with no scope row grants nothing, and must not fall through to
    // some default.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = makeRole('SCOPELESS_PROJECT_ROLE');
    $role->givePermissionTo(makePermission('projects.view'));
    $actor->assignRole($role);

    Project::factory()->for($office)->createdBy($actor)->create();

    expect(reachableProjectIds($actor))->toBe([]);
});

it('reaches nothing for a denied access object', function (): void {
    $actor = User::factory()->for(Office::factory())->create();
    Project::factory()->create();

    $reached = app(ProjectVisibility::class)
        ->scope(Project::query(), $actor, EffectiveAccess::denied())
        ->pluck('id')->all();

    expect($reached)->toBe([]);
});

it('reaches nothing when only unusable scopes are granted', function (): void {
    // An access object that is granted but carries nothing this domain can
    // evaluate must produce no reach — never an unrestricted query.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    Project::factory()->for($office)->create();

    $teamOnly = EffectiveAccess::fromRoles([DataScope::TEAM]);

    $reached = app(ProjectVisibility::class)
        ->scope(Project::query(), $actor, $teamOnly)
        ->pluck('id')->all();

    expect($teamOnly->granted)->toBeTrue()
        ->and($reached)->toBe([]);
});

it('excludes soft-deleted projects from ordinary reach', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    $project = Project::factory()->for($office)->create();
    $project->delete();

    expect(reachableProjectIds($actor))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The record check is the list query
|--------------------------------------------------------------------------
*/

it('agrees between the list query and the single-record check', function (): void {
    // The failure this prevents: a record hidden from a listing yet still
    // fetchable by id. permits() runs the identical constraint rather than
    // reimplementing it.
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    $projects = [
        Project::factory()->for($office)->create(),
        Project::factory()->for($elsewhere)->createdBy($actor)->create(),
        Project::factory()->for($elsewhere)->create(),
    ];

    $visibility = app(ProjectVisibility::class);
    $access = app(EffectiveAccessResolver::class)->resolve($actor->fresh(), 'projects.view');
    $listed = reachableProjectIds($actor);

    foreach ($projects as $project) {
        expect($visibility->permits($actor->fresh(), $access, $project))
            ->toBe(in_array($project->id, $listed, true), $project->id);
    }
});

/*
|--------------------------------------------------------------------------
| No ranking machinery — D-028
|--------------------------------------------------------------------------
*/

it('exposes no widest, rank, or max scope helper', function (): void {
    // The same guard DataScope carries, restated for the Project predicate class
    // because this is where a "just take the widest" shortcut would be written.
    $methods = array_map(
        fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(ProjectVisibility::class))->getMethods(),
    );

    foreach (['widest', 'max', 'maxscope', 'rank', 'highest', 'strongest', 'compare'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }

    $source = file_get_contents(app_path('Domains/Project/ProjectVisibility.php'));

    expect($source)->not->toMatch('/\bmax\s*\(/')
        ->and($source)->not->toMatch('/\busort\s*\(/');
});
