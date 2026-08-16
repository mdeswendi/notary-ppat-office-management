<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Project\Enums\ProjectPriority;
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
| Create — authorization
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated create', function (): void {
    $this->postJson('/api/v1/projects', ['title' => 'X'])->assertUnauthorized();
});

it('creates in the actor Office for every scope that can match the new record', function (
    string $scope,
): void {
    // OWN matches because created_by will be the actor; OFFICE because office_id
    // will be their Office; ALL because it subsumes both. Creation is still in
    // the actor's own Office in every case (D-097).
    [$actor, $office] = projectActor(['projects.create', 'projects.view'], DataScope::from($scope));

    $response = $this->actingAs($actor)
        ->postJson('/api/v1/projects', ['title' => 'Akuisisi Tanah PT ABC'])
        ->assertCreated();

    expect($response->json('data.office.id'))->toBe($office->getKey());
})->with(['OWN', 'OFFICE', 'ALL']);

it('refuses creation for a scope that cannot match the new record', function (string $scope): void {
    // ASSIGNED has no PIC to match — a new Project is unassigned — and TEAM has
    // no entity at all. Neither can describe the record about to exist.
    [$actor] = projectActor(['projects.create'], DataScope::from($scope));

    $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => 'X'])->assertForbidden();
})->with(['ASSIGNED', 'TEAM']);

it('refuses creation without the capability', function (): void {
    [$actor] = projectActor(['projects.view']);

    $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => 'X'])->assertForbidden();
});

it('refuses creation when a role grant carries no Data Scope', function (): void {
    // D-039: scope metadata is what makes a grant usable. Fail closed.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = makeRole('SCOPELESS_PROJECT_CREATE');
    $role->givePermissionTo(makePermission('projects.create'));
    $actor->assignRole($role);

    $this->actingAs($actor->fresh())->postJson('/api/v1/projects', ['title' => 'X'])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Create — system-controlled fields
|--------------------------------------------------------------------------
*/

it('refuses every system-controlled field rather than ignoring it', function (
    string $field,
    mixed $value,
): void {
    // Prohibited, not silently dropped. An interface that appears to accept
    // `office_id` and then ignores it is worse than one that refuses: the caller
    // believes the Project went somewhere it did not.
    [$actor] = projectActor(['projects.create', 'projects.view']);

    $this->actingAs($actor)
        ->postJson('/api/v1/projects', ['title' => 'X', $field => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['project_number', 'PRJ-2026-000999'],
    ['status', 'COMPLETED'],
    ['pic_user_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['created_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['updated_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['deleted_at', '2026-01-01 00:00:00'],
    ['completed_at', '2026-01-01 00:00:00'],
]);

it('refuses a system-controlled field sent as an explicit null', function (string $field): void {
    // Found by the M3.3 HTTP smoke. `prohibited` means "missing or empty" to
    // Laravel, so a null satisfied it and creation answered 201 with the key
    // quietly discarded. Nothing was ever written — the create Action fills an
    // allow-list and these columns are not fillable — but a 201 told the caller
    // an instruction had been accepted. The refusal keys on presence now: an
    // empty instruction is still an instruction.
    [$actor] = projectActor(['projects.create', 'projects.view']);

    $this->actingAs($actor)
        ->postJson('/api/v1/projects', ['title' => 'X', $field => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'office_id',
    'project_number',
    'status',
    'pic_user_id',
    'created_by',
    'updated_by',
    'deleted_at',
    'completed_at',
]);

it('cannot be pushed into another Office by payload', function (): void {
    // The decisive case for an ALL-scoped actor: ALL is cross-office reach over
    // existing Projects, never cross-office creation.
    [$actor, $office] = projectActor(['projects.create', 'projects.view'], DataScope::ALL);
    $elsewhere = Office::factory()->create();

    $this->actingAs($actor)
        ->postJson('/api/v1/projects', ['title' => 'X', 'office_id' => $elsewhere->getKey()])
        ->assertStatus(422);

    // And with no payload at all it lands in the actor's Office, not elsewhere.
    $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => 'X'])->assertCreated();

    expect(Project::first()->office_id)->toBe($office->getKey());
});

it('stamps the new Project with system values', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.create', 'projects.view']);

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'title' => 'Akuisisi Tanah PT ABC',
        'description' => 'Dua bidang.',
        'priority' => 'HIGH',
    ])->assertCreated();

    $project = Project::firstOrFail();

    expect($response->json('data.project_number'))->toBe('PRJ-2026-000001')
        ->and($response->json('data.status'))->toBe('OPEN')
        ->and($response->json('data.pic'))->toBeNull()
        ->and($project->office_id)->toBe($office->getKey())
        ->and($project->created_by)->toBe($actor->getKey())
        ->and($project->status)->toBe(ProjectStatus::OPEN)
        ->and($project->pic_user_id)->toBeNull()
        ->and($project->priority)->toBe(ProjectPriority::HIGH);
});

it('allocates a distinct reference per created Project', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor] = projectActor(['projects.create', 'projects.view']);

    foreach (['A', 'B', 'C'] as $title) {
        $this->actingAs($actor)->postJson('/api/v1/projects', ['title' => $title])->assertCreated();
    }

    expect(Project::pluck('project_number')->sort()->values()->all())
        ->toBe(['PRJ-2026-000001', 'PRJ-2026-000002', 'PRJ-2026-000003']);
});

it('requires a title', function (): void {
    [$actor] = projectActor(['projects.create']);

    $this->actingAs($actor)->postJson('/api/v1/projects', [])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);
});

/*
|--------------------------------------------------------------------------
| List and detail — Data Scope
|--------------------------------------------------------------------------
*/

it('lists only what the scope reaches', function (): void {
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $colleague = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OWN);

    $mine = Project::factory()->for($office)->createdBy($actor)->create();
    Project::factory()->for($office)->createdBy($colleague)->create();
    Project::factory()->for($elsewhere)->create();

    $response = $this->actingAs($actor->fresh())->getJson('/api/v1/projects')->assertOk();

    expect($response->json('data.*.id'))->toBe([$mine->getKey()])
        ->and($response->json('meta.total'))->toBe(1);
});

it('unions scopes in the list without ranking them', function (): void {
    $office = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OWN);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);

    $ownedElsewhere = Project::factory()->for($elsewhere)->createdBy($actor)->create();
    $inMyOffice = Project::factory()->for($office)->create();
    Project::factory()->for($elsewhere)->create();

    $ids = $this->actingAs($actor->fresh())->getJson('/api/v1/projects')->assertOk()->json('data.*.id');

    expect(collect($ids)->sort()->values()->all())
        ->toBe(collect([$ownedElsewhere->getKey(), $inMyOffice->getKey()])->sort()->values()->all());
});

it('reaches an assigned Project through ASSIGNED', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, 'projects.view', DataScope::ASSIGNED);

    $assigned = Project::factory()->for($office)->assignedTo($actor)->create();
    Project::factory()->for($office)->create();

    expect($this->actingAs($actor->fresh())->getJson('/api/v1/projects')->json('data.*.id'))
        ->toBe([$assigned->getKey()]);
});

it('refuses the list outright when no scope reaches a Project', function (): void {
    [$teamOnly] = projectActor(['projects.view'], DataScope::TEAM);

    $this->actingAs($teamOnly)->getJson('/api/v1/projects')->assertForbidden();
});

it('refuses a detail read outside the scope', function (): void {
    [$actor] = projectActor(['projects.view']);
    $elsewhere = Project::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/projects/{$elsewhere->getKey()}")->assertForbidden();
});

it('excludes soft-deleted Projects from ordinary list and detail', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.restore']);
    $project = Project::factory()->for($office)->create();
    $project->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/projects')->json('data'))->toBe([]);

    $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}")->assertNotFound();
});

it('grants no alternate reach through projects.view_all', function (): void {
    // D-090: superseded by Data Scope ALL, and never a second reach mechanism.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'projects.view_all', DataScope::ALL);

    Project::factory()->for($office)->create();
    $elsewhere = Project::factory()->create();

    $ids = $this->actingAs($actor->fresh())->getJson('/api/v1/projects')->assertOk()->json('data.*.id');

    expect($ids)->not->toContain($elsewhere->getKey())->toHaveCount(1);

    $this->actingAs($actor->fresh())
        ->getJson("/api/v1/projects/{$elsewhere->getKey()}")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| List — filters and search
|--------------------------------------------------------------------------
*/

it('searches title and reference within the scope', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.view']);

    $wanted = Project::factory()->for($office)->create(['title' => 'Akuisisi Tanah']);
    Project::factory()->for($office)->create(['title' => 'Pendirian PT']);

    expect($this->actingAs($actor)->getJson('/api/v1/projects?search=Akuisisi')->json('data.*.id'))
        ->toBe([$wanted->getKey()]);

    expect($this->actingAs($actor)->getJson("/api/v1/projects?search={$wanted->project_number}")
        ->json('data.*.id'))->toBe([$wanted->getKey()]);
});

it('filters by status and priority', function (): void {
    [$actor, $office] = projectActor(['projects.view']);

    $onHold = Project::factory()->for($office)->status(ProjectStatus::ON_HOLD)->create();
    Project::factory()->for($office)->status(ProjectStatus::OPEN)->create();

    expect($this->actingAs($actor)->getJson('/api/v1/projects?status=ON_HOLD')->json('data.*.id'))
        ->toBe([$onHold->getKey()]);

    // An unrecognized value is ignored rather than a 422: a stale bookmark
    // should show the unfiltered list.
    expect($this->actingAs($actor)->getJson('/api/v1/projects?status=NOT_A_STATUS')->json('data'))
        ->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Capability flags
|--------------------------------------------------------------------------
*/

it('reports capability flags computed from the real Policy', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    $data = $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}")
        ->assertOk()->json('data');

    expect($data['can_update'])->toBeTrue()
        ->and($data['can_assign'])->toBeFalse()
        ->and($data['can_change_status'])->toBeFalse()
        ->and($data['can_archive'])->toBeFalse();
});

it('does not grow its query cost as the list grows', function (): void {
    // The capability flags are computed in bulk. Asking the Policy per row would
    // be four uncached resolver calls plus four exists() per Project — the N+1
    // M2.6 found in the Party reverse view.
    [$actor, $office] = projectActor([
        'projects.view', 'projects.update', 'projects.assign',
        'projects.change_status', 'projects.archive',
    ]);

    Project::factory()->for($office)->create();

    $count = function () use ($actor): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($actor)->getJson('/api/v1/projects')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $one = $count();

    Project::factory()->for($office)->count(9)->create();

    expect($count())->toBe($one);
});

/*
|--------------------------------------------------------------------------
| Ordinary update
|--------------------------------------------------------------------------
*/

it('updates ordinary attributes when authorized', function (): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create(['title' => 'Lama']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", [
            'title' => 'Baru', 'priority' => 'URGENT',
        ])->assertOk()->assertJsonPath('data.title', 'Baru');

    expect($project->fresh()->priority)->toBe(ProjectPriority::URGENT)
        ->and($project->fresh()->updated_by)->toBe($actor->getKey());
});

it('refuses an update without the capability', function (): void {
    [$actor, $office] = projectActor(['projects.view']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", ['title' => 'X'])
        ->assertForbidden();
});

it('refuses every separately-governed field on generic update', function (string $field, mixed $value): void {
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", [$field => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['project_number', 'PRJ-2026-000999'],
    ['status', 'COMPLETED'],
    ['pic_user_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['created_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['updated_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['deleted_at', '2026-01-01 00:00:00'],
]);

it('refuses a separately-governed field sent as an explicit null', function (string $field): void {
    // `{"pic_user_id": null}` is the case that matters: it is an unassign
    // instruction, and unassigning belongs to `projects.assign` like every other
    // assignment (D-091). Laravel's `prohibited` treats null as absent, so the
    // ordinary update used to answer 200 and change nothing — a silent no-op is
    // not a refusal, and the caller could not tell the difference.
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", [$field => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'office_id',
    'project_number',
    'status',
    'pic_user_id',
    'created_by',
    'updated_by',
    'deleted_at',
]);

it('keeps the PIC when an ordinary update tries to clear it', function (): void {
    // The refusal is a refusal all the way down: nothing was written, so the
    // person in charge is exactly who they were.
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $pic = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->create(['pic_user_id' => $pic->getKey()]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", ['pic_user_id' => null])
        ->assertStatus(422);

    expect($project->fresh()->pic_user_id)->toBe($pic->getKey());
});

it('leaves the reference and Office untouched after an ordinary update', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    [$actor, $office] = projectActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();
    $reference = $project->project_number;

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}", ['title' => 'Baru'])->assertOk();

    expect($project->fresh()->project_number)->toBe($reference)
        ->and($project->fresh()->office_id)->toBe($office->getKey());
});
