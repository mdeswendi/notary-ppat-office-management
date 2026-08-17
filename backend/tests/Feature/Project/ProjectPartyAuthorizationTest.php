<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use App\Policies\ProjectPartyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function participationPolicy(): ProjectPartyPolicy
{
    return app(ProjectPartyPolicy::class);
}

/**
 * An actor holding the given participation permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function participationActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/*
|--------------------------------------------------------------------------
| The registry
|--------------------------------------------------------------------------
*/

it('registers exactly two participation capabilities', function (): void {
    $participation = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'projects.parties.'),
    ));

    sort($participation);

    expect($participation)->toBe(['projects.parties.manage', 'projects.parties.view']);
});

it('registers no projects.parties.view_all', function (): void {
    // Reach is Data Scope ALL against the parent Project. A second reach
    // mechanism is exactly what D-090 refuses.
    expect(PermissionRegistry::has('projects.parties.view_all'))->toBeFalse();
});

it('offers the four Project scopes to both participation capabilities', function (string $permission): void {
    // Participation is judged against the parent Project, so it takes the
    // Project predicates rather than the Party ones.
    $allowed = array_map(
        fn (DataScope $scope): string => $scope->value,
        app(PermissionScopeRules::class)->allowedFor($permission),
    );

    expect($allowed)->toBe(['OWN', 'ASSIGNED', 'OFFICE', 'ALL']);
})->with(['projects.parties.view', 'projects.parties.manage']);

/*
|--------------------------------------------------------------------------
| Data Scope predicates against the parent Project
|--------------------------------------------------------------------------
*/

it('reaches a Project the predicate selects', function (string $scope, string $shape): void {
    $dataScope = DataScope::from($scope);
    [$actor, $office] = participationActor(['projects.parties.view'], $dataScope);

    $project = match ($shape) {
        'created' => Project::factory()->for($office)->create(['created_by' => $actor->getKey()]),
        'assigned' => Project::factory()->for($office)->create(['pic_user_id' => $actor->getKey()]),
        'office' => Project::factory()->for($office)->create(),
        'elsewhere' => Project::factory()->for(Office::factory()->create())->create(),
    };

    expect(participationPolicy()->viewAny($actor, $project))->toBeTrue();
})->with([
    ['OWN', 'created'],
    ['ASSIGNED', 'assigned'],
    ['OFFICE', 'office'],
    ['ALL', 'elsewhere'],
]);

it('refuses a Project the predicate does not select', function (string $scope, string $shape): void {
    $dataScope = DataScope::from($scope);
    [$actor, $office] = participationActor(['projects.parties.view'], $dataScope);

    $project = match ($shape) {
        // Somebody else opened it, and nobody is assigned.
        'other_creator' => Project::factory()->for($office)->create([
            'created_by' => User::factory()->for($office)->create()->getKey(),
        ]),
        'unassigned' => Project::factory()->for($office)->create(),
        'elsewhere' => Project::factory()->for(Office::factory()->create())->create(),
    };

    expect(participationPolicy()->viewAny($actor, $project))->toBeFalse();
})->with([
    ['OWN', 'other_creator'],
    ['OWN', 'elsewhere'],
    ['ASSIGNED', 'unassigned'],
    ['ASSIGNED', 'elsewhere'],
    ['OFFICE', 'elsewhere'],
]);

it('fails closed for TEAM', function (string $permission): void {
    // No Team entity exists (D-042), so a grant carrying only TEAM matches
    // nothing rather than defaulting to something.
    [$actor, $office] = participationActor([$permission], DataScope::TEAM);
    $project = Project::factory()->for($office)->create([
        'created_by' => $actor->getKey(),
        'pic_user_id' => $actor->getKey(),
    ]);

    $ability = $permission === 'projects.parties.view' ? 'viewAny' : 'manage';

    expect(participationPolicy()->{$ability}($actor, $project))->toBeFalse();
})->with(['projects.parties.view', 'projects.parties.manage']);

it('unions predicates across grants rather than ranking them', function (): void {
    // D-028: scopes are predicates and multiple grants union. OWN reaches what
    // the actor created; ASSIGNED reaches what they are PIC of; together they
    // reach both, and neither outranks the other.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.parties.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.parties.view', DataScope::ASSIGNED);
    $actor = $actor->fresh();

    $created = Project::factory()->for($office)->create(['created_by' => $actor->getKey()]);
    $assigned = Project::factory()->for($office)->create(['pic_user_id' => $actor->getKey()]);
    $neither = Project::factory()->for($office)->create();

    expect(participationPolicy()->viewAny($actor, $created))->toBeTrue()
        ->and(participationPolicy()->viewAny($actor, $assigned))->toBeTrue()
        ->and(participationPolicy()->viewAny($actor, $neither))->toBeFalse();
});

it('grants nothing when a role grant carries no Data Scope', function (): void {
    [$actor, $office] = participationActor([]);
    $project = Project::factory()->for($office)->create();

    expect(participationPolicy()->viewAny($actor, $project))->toBeFalse()
        ->and(participationPolicy()->manage($actor, $project))->toBeFalse();
});

it('reaches no archived Project', function (): void {
    [$actor, $office] = participationActor(['projects.parties.view', 'projects.parties.manage']);
    $project = Project::factory()->for($office)->create();
    $project->delete();

    expect(participationPolicy()->viewAny($actor, $project))->toBeFalse()
        ->and(participationPolicy()->manage($actor, $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The two capabilities are independent
|--------------------------------------------------------------------------
*/

it('does not let view imply manage', function (): void {
    [$actor, $office] = participationActor(['projects.parties.view']);
    $project = Project::factory()->for($office)->create();

    expect(participationPolicy()->viewAny($actor, $project))->toBeTrue()
        ->and(participationPolicy()->manage($actor, $project))->toBeFalse();
});

it('does not let manage imply view', function (): void {
    // The direction that feels wrong and is nonetheless correct: the registry
    // defines two codes, so an administrator who wants both grants both. A
    // silently implied capability is one nobody configured and nobody can
    // revoke (D-098).
    [$actor, $office] = participationActor(['projects.parties.manage']);
    $project = Project::factory()->for($office)->create();

    expect(participationPolicy()->manage($actor, $project))->toBeTrue()
        ->and(participationPolicy()->viewAny($actor, $project))->toBeFalse();
});

it('gives projects.update no participation authority', function (): void {
    [$actor, $office] = participationActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    expect(participationPolicy()->viewAny($actor, $project))->toBeFalse()
        ->and(participationPolicy()->manage($actor, $project))->toBeFalse();
});

it('gives projects.view no participation authority', function (): void {
    [$actor, $office] = participationActor(['projects.view']);
    $project = Project::factory()->for($office)->create();

    expect(participationPolicy()->viewAny($actor, $project))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Over HTTP
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated participation requests', function (): void {
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();

    $this->getJson("/api/v1/projects/{$project->getKey()}/parties")->assertUnauthorized();
    $this->postJson("/api/v1/projects/{$project->getKey()}/parties", [])->assertUnauthorized();
});

it('refuses the list to projects.view alone over HTTP', function (): void {
    [$actor, $office] = participationActor(['projects.view']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertForbidden();
});

it('serves the list to the dedicated view capability', function (): void {
    [$actor, $office] = participationActor(['projects.parties.view']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('refuses every mutation to the view capability alone', function (): void {
    [$actor, $office] = participationActor(['projects.parties.view']);
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $base = "/api/v1/projects/{$project->getKey()}/parties";

    $this->actingAs($actor)->postJson($base, ['party_id' => $party->getKey()])->assertForbidden();
    $this->actingAs($actor)->patchJson("{$base}/{$participation->getKey()}", ['notes' => 'x'])->assertForbidden();
    $this->actingAs($actor)->deleteJson("{$base}/{$participation->getKey()}")->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}/party-options")->assertForbidden();
});

it('refuses participation on a Project in another Office', function (): void {
    [$actor] = participationActor(['projects.parties.view', 'projects.parties.manage']);
    $elsewhere = Project::factory()->for(Office::factory()->create())->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$elsewhere->getKey()}/parties")
        ->assertForbidden();
});

it('answers 404 for a participation belonging to another Project', function (): void {
    // Nested binding must not become a way to reach a row by naming a Project
    // the caller can see instead of the one that owns it.
    [$actor, $office] = participationActor(['projects.parties.manage']);

    $mine = Project::factory()->for($office)->create();
    $other = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $foreign = ProjectParty::factory()->create([
        'project_id' => $other->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$mine->getKey()}/parties/{$foreign->getKey()}", ['notes' => 'x'])
        ->assertNotFound();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/projects/{$mine->getKey()}/parties/{$foreign->getKey()}")
        ->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});
