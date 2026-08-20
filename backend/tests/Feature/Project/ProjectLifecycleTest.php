<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| PIC assignment
|--------------------------------------------------------------------------
*/

it('assigns an active same-Office user as PIC', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $candidate = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => $candidate->getKey(),
        ])->assertOk()->assertJsonPath('data.pic.id', $candidate->getKey());

    expect($project->fresh()->pic_user_id)->toBe($candidate->getKey());
});

it('unassigns with an explicit null', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $candidate = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->assignedTo($candidate)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", ['pic_user_id' => null])
        ->assertOk()->assertJsonPath('data.pic', null);

    expect($project->fresh()->pic_user_id)->toBeNull();
});

it('requires the field to be present so an empty body cannot silently unassign', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [])
        ->assertStatus(422)->assertJsonValidationErrors(['pic_user_id']);
});

it('refuses a cross-Office PIC even for an ALL-scoped actor', function (): void {
    // The hole this closes: ASSIGNED grants reach when pic_user_id == actor.id
    // (D-088), so naming somebody from another Office would hand them reach over
    // a Project their own scope never included — with no role edited.
    [$actor, $office] = projectActor(['projects.view', 'projects.assign'], DataScope::ALL);
    $outsider = User::factory()->for(Office::factory())->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => $outsider->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors(['pic_user_id']);

    expect($project->fresh()->pic_user_id)->toBeNull();
});

it('refuses a disabled or deleted user as PIC', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $project = Project::factory()->for($office)->create();

    $disabled = User::factory()->for($office)->create(['is_active' => false]);
    $deleted = User::factory()->for($office)->create();
    $deleted->delete();

    foreach ([$disabled, $deleted] as $candidate) {
        $this->actingAs($actor)
            ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
                'pic_user_id' => $candidate->getKey(),
            ])->assertStatus(422)->assertJsonValidationErrors(['pic_user_id']);
    }
});

it('does not disclose why a candidate is ineligible', function (): void {
    // One message for every ineligibility. Distinguishing "no such user" from
    // "disabled" from "another Office" would make this endpoint a directory probe.
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $project = Project::factory()->for($office)->create();
    $outsider = User::factory()->for(Office::factory())->create();

    $missing = $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ])->json('errors.pic_user_id');

    $elsewhere = $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => $outsider->getKey(),
        ])->json('errors.pic_user_id');

    expect($elsewhere)->toBe($missing);
});

it('refuses assignment without the capability', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $candidate = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => $candidate->getKey(),
        ])->assertForbidden();
});

it('makes the Project reachable to the new PIC through ASSIGNED', function (): void {
    $office = Office::factory()->create();
    [$assigner] = projectActor(['projects.view', 'projects.assign']);
    $assigner->forceFill(['office_id' => $office->getKey()])->save();

    $worker = User::factory()->for($office)->create();
    grantPermissionScope($worker, 'projects.view', DataScope::ASSIGNED);

    $project = Project::factory()->for($office)->create();

    expect($this->actingAs($worker->fresh())->getJson('/api/v1/projects')->json('data'))->toBe([]);

    $this->actingAs($assigner->fresh())
        ->patchJson("/api/v1/projects/{$project->getKey()}/assignment", [
            'pic_user_id' => $worker->getKey(),
        ])->assertOk();

    expect($this->actingAs($worker->fresh())->getJson('/api/v1/projects')->json('data.*.id'))
        ->toBe([$project->getKey()]);
});

it('offers only active same-Office users as assignee options', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.assign']);
    $eligible = User::factory()->for($office)->create(['name' => 'Eligible']);
    User::factory()->for($office)->create(['is_active' => false, 'name' => 'Disabled']);
    User::factory()->for(Office::factory())->create(['name' => 'Elsewhere']);

    $project = Project::factory()->for($office)->create();

    $users = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/assignment/options")
        ->assertOk()->json('data.users');

    $names = collect($users)->pluck('name');

    expect($names)->toContain('Eligible', $actor->name)
        ->and($names)->not->toContain('Disabled')
        ->and($names)->not->toContain('Elsewhere');

    // Two fields, not a user-administration payload.
    expect(array_keys($users[0]))->toBe(['id', 'name']);
});

it('refuses assignee options without the assign capability', function (): void {
    [$actor, $office] = projectActor(['projects.view']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/assignment/options")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

it('changes status when authorized', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.change_status']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => 'IN_PROGRESS'])
        ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

    expect($project->fresh()->status)->toBe(ProjectStatus::IN_PROGRESS);
});

it('accepts every canonical status without a transition rule', function (string $status): void {
    // No matrix: M3 authorizes who may change status, never which changes are
    // legal (D-091). COMPLETED to OPEN is permitted precisely because nobody has
    // specified that it should not be.
    [$actor, $office] = projectActor(['projects.view', 'projects.change_status']);
    $project = Project::factory()->for($office)->status(ProjectStatus::COMPLETED)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => $status])
        ->assertOk();

    expect($project->fresh()->status->value)->toBe($status);
})->with(['OPEN', 'IN_PROGRESS', 'WAITING', 'ON_HOLD', 'COMPLETED', 'CANCELLED', 'ARCHIVED']);

it('refuses a translated label as a status', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.change_status']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => 'Sedang Diproses'])
        ->assertStatus(422)->assertJsonValidationErrors(['status']);
});

it('refuses a status change without the capability', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => 'IN_PROGRESS'])
        ->assertForbidden();
});

it('does not soft-delete when the business status becomes ARCHIVED', function (): void {
    // D-093: two states with unfortunately similar names, deliberately not wired
    // to each other in either direction.
    [$actor, $office] = projectActor(['projects.view', 'projects.change_status']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => 'ARCHIVED'])
        ->assertOk();

    expect($project->fresh()->trashed())->toBeFalse()
        ->and($this->actingAs($actor)->getJson('/api/v1/projects')->json('data'))->toHaveCount(1);
});

it('changes nothing but the status', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.view', 'projects.change_status']);
    $pic = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->assignedTo($pic)->create();
    $reference = $project->project_number;

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/status", ['status' => 'COMPLETED'])
        ->assertOk();

    $fresh = $project->fresh();

    expect($fresh->pic_user_id)->toBe($pic->getKey())
        ->and($fresh->project_number)->toBe($reference)
        ->and($fresh->office_id)->toBe($office->getKey())
        // completed_at is not coupled to COMPLETED — that rule is unspecified.
        ->and($fresh->completed_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Archive and restore
|--------------------------------------------------------------------------
*/

it('archives by soft deleting and preserves everything else', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.view', 'projects.archive', 'projects.restore']);
    $pic = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->assignedTo($pic)
        ->status(ProjectStatus::IN_PROGRESS)->create();

    $this->actingAs($actor)->deleteJson("/api/v1/projects/{$project->getKey()}")->assertNoContent();

    $trashed = Project::withTrashed()->find($project->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->status)->toBe(ProjectStatus::IN_PROGRESS)
        ->and($trashed->project_number)->toBe('PRJ-2026-000001')
        ->and($trashed->office_id)->toBe($office->getKey())
        ->and($trashed->pic_user_id)->toBe($pic->getKey());
});

it('refuses archiving without the capability', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->deleteJson("/api/v1/projects/{$project->getKey()}")->assertForbidden();
});

it('does not release the reference when archiving', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.create', 'projects.view', 'projects.archive']);

    $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => 'A'])->assertCreated();
    $first = Project::firstOrFail();

    $this->actingAs($actor)->deleteJson("/api/v1/projects/{$first->getKey()}")->assertNoContent();

    $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => 'B'])->assertCreated();

    expect(Project::firstOrFail()->project_number)->toBe('PRJ-2026-000002');
});

it('lists archived Projects under restore authority, not view', function (): void {
    $office = Office::factory()->create();
    $archived = Project::factory()->for($office)->create();
    $archived->delete();
    Project::factory()->for($office)->create();

    // projects.view alone reaches no archived record anywhere.
    $viewer = User::factory()->for($office)->create();
    grantPermissionScope($viewer, 'projects.view', DataScope::OFFICE);
    $this->actingAs($viewer->fresh())->getJson('/api/v1/projects/archived')->assertForbidden();

    // projects.restore reaches archived records in its own scope, and no live one.
    $restorer = User::factory()->for($office)->create();
    grantPermissionScope($restorer, 'projects.restore', DataScope::OFFICE);

    $data = $this->actingAs($restorer->fresh())
        ->getJson('/api/v1/projects/archived')->assertOk()->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$archived->getKey()])
        ->and($data[0]['can_restore'])->toBeTrue();
});

it('scopes the archived list by Data Scope', function (): void {
    [$actor] = projectActor(['projects.restore']);
    $elsewhere = Project::factory()->create();
    $elsewhere->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/projects/archived')->assertOk()->json('data'))
        ->toBe([]);
});

it('restores an archived Project and retains everything', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.view', 'projects.archive', 'projects.restore']);
    $pic = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->assignedTo($pic)
        ->status(ProjectStatus::ON_HOLD)->create();
    $project->delete();

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/restore")->assertOk();

    $fresh = Project::find($project->getKey());

    expect($fresh)->not->toBeNull()
        ->and($fresh->project_number)->toBe('PRJ-2026-000001')
        // Restoring the record does not reopen the business status.
        ->and($fresh->status)->toBe(ProjectStatus::ON_HOLD)
        ->and($fresh->office_id)->toBe($office->getKey())
        ->and($fresh->pic_user_id)->toBe($pic->getKey());
});

it('refuses restore without the capability', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.archive']);
    $project = Project::factory()->for($office)->create();
    $project->delete();

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/restore")->assertForbidden();
});

it('refuses restore outside the scope', function (): void {
    [$actor] = projectActor(['projects.restore']);
    $elsewhere = Project::factory()->create();
    $elsewhere->delete();

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$elsewhere->getKey()}/restore")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Boundary
|--------------------------------------------------------------------------
*/

it('introduces no Matter, workflow, or Office-transfer surface', function (): void {
    // **Narrowed at M3.4, not deleted.** Participation was on this list while it
    // was unbuilt; M3.4 owns `projects/{project}/parties` and the
    // `project_parties` table now, and its own tests assert their shape.
    //
    // Everything else is kept, including the two that were never about a
    // milestone boundary at all: no Office endpoint and no transfer endpoint on
    // a Project, because Project Office is immutable during M3 (D-089).
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri());

    // **Narrowed again at M4.4**, which ships the Matter product surface (D-109).
    // `matters` leaves this list; everything else stays, including the two that
    // were never about a milestone boundary — no Office endpoint and no transfer
    // endpoint on a Project.
    //
    // The point that survives is the one this guard was always making: **no
    // Matter surface hangs off a Project address.** A Matter is reached at
    // `/notary/matters` or `/ppat/matters`, never `/projects/{id}/matters`,
    // because the domain root is what selects the permission namespace (D-101).
    foreach ([
        'projects/{project}/participants', 'projects/{project}/matters',
        'workflow', 'stages', 'deeds', 'warkah', 'properties',
        'projects/{project}/office', 'projects/{project}/transfer',
    ] as $segment) {
        expect($uris->filter(fn (string $uri): bool => str_contains($uri, $segment)))->toBeEmpty($segment);
    }

    // **The table half is narrowed at M4.2**, which builds `matters` (D-107),
    // and again at M4.5, which builds `matter_parties` (D-105). Both exist now
    // and their own schema tests assert their shape. What this file still owns
    // is the claim the route list above makes: **Project exposes no surface
    // reaching into either**, and neither table points back at a Project.
    expect(Schema::hasTable('matter_parties'))->toBeTrue()
        ->and(Schema::hasColumn('matter_parties', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'matter_id'))->toBeFalse();
});

it('adds no permission to the canonical registry', function (): void {
    // Narrowed at M3.4, which legitimately added the two participation codes
    // (D-098). The global total is pinned once in `PermissionRegistryTest`; what
    // M3.3 actually claimed, and what stays true, is that the Project lifecycle
    // surface invented none of its own.
    $lifecycle = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_starts_with($code, 'projects.')
            && ! str_starts_with($code, 'projects.parties.'),
    ));

    expect($lifecycle)->toHaveCount(8);
});
