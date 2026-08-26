<?php

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Activity\Services\ActivityRecorder;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Project\Enums\ProjectStatus;
use App\Domains\Task\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

/**
 * The Dashboard (M8.1, D-122).
 *
 * The rule under test throughout: **a count is a disclosure and obeys Data
 * Scope**, and a panel the actor may not see is `null` rather than `0`.
 */
function dashboardActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
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
| No capability of its own
|--------------------------------------------------------------------------
*/

it('serves the dashboard to any authenticated actor', function (string $path): void {
    // There is no `dashboard.*` code and none is needed. An actor holding
    // nothing gets a 200 and a page of nulls — correct behaviour, not an error
    // state (D-122).
    [$actor] = dashboardActor();

    $this->actingAs($actor)->getJson("/api/v1/dashboard/{$path}")->assertOk();
})->with(['stats', 'tasks', 'needs-attention', 'workload', 'activity', 'deeds']);

it('refuses the dashboard to an unauthenticated caller', function (string $path): void {
    $this->getJson("/api/v1/dashboard/{$path}")->assertUnauthorized();
})->with(['stats', 'tasks', 'needs-attention', 'workload', 'activity', 'deeds']);

it('nulls every panel the actor holds no capability for', function (): void {
    [$actor] = dashboardActor();

    $this->actingAs($actor)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        // Null, never zero. A zero would be a lie about records the actor may
        // not know the number of — the position O-046 took for document counts.
        ->assertJsonPath('data.active_projects', null)
        ->assertJsonPath('data.active_matters', null)
        ->assertJsonPath('data.pending_reviews', null)
        ->assertJsonPath('data.overdue_tasks', null)
        ->assertJsonPath('data.total_deeds_this_month', null);
});

it('reports zero rather than null once the capability is held', function (): void {
    // The other half of the distinction: permitted and empty is a real zero.
    [$actor] = dashboardActor(['projects.view']);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.active_projects', 0)
        ->assertJsonPath('data.active_matters', null);
});

/*
|--------------------------------------------------------------------------
| A count is a disclosure
|--------------------------------------------------------------------------
*/

it('counts only projects inside the actor office', function (): void {
    [$actor, $office] = dashboardActor(['projects.view']);

    Project::factory()->count(3)->create([
        'office_id' => $office->getKey(),
        'status' => ProjectStatus::OPEN,
    ]);

    // Another Office's work must not reach the figure.
    Project::factory()->count(5)->create([
        'office_id' => Office::factory()->create()->getKey(),
        'status' => ProjectStatus::OPEN,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.active_projects', 3);
});

it('counts only the actor own tasks under OWN scope', function (): void {
    // The rule that makes this milestone's headline claim true: an actor whose
    // scope is narrower than OFFICE gets a narrower **count**, not the office
    // total. A count plus a filter would otherwise reconstruct the list.
    [$actor, $office] = dashboardActor(['tasks.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();
    $past = Date::now()->subDays(3);

    Task::factory()->count(2)->create([
        'office_id' => $office->getKey(),
        'created_by' => $actor->getKey(),
        'status' => TaskStatus::OPEN,
        'due_at' => $past,
    ]);

    Task::factory()->count(7)->create([
        'office_id' => $office->getKey(),
        'created_by' => $colleague->getKey(),
        'status' => TaskStatus::OPEN,
        'due_at' => $past,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        // Two, not nine.
        ->assertJsonPath('data.overdue_tasks', 2);
});

it('counts only the matter domain the actor may read', function (): void {
    // `notary.matters.view` and `ppat.matters.view` are separate grants (D-101).
    // Holding one must never produce a total that includes the other.
    [$actor, $office] = dashboardActor(['notary.matters.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->count(2)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
        'status' => MatterStatus::OPEN,
    ]);

    Matter::factory()->count(4)->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::PPAT,
        'status' => MatterStatus::OPEN,
    ]);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/stats')
        ->assertOk()
        ->assertJsonPath('data.active_matters', 2);
});

/*
|--------------------------------------------------------------------------
| Panels
|--------------------------------------------------------------------------
*/

it('buckets the actor own work by when it is due', function (): void {
    [$actor, $office] = dashboardActor(['tasks.view']);

    $make = fn (string $title, ?string $due) => Task::factory()->create([
        'office_id' => $office->getKey(),
        'created_by' => $actor->getKey(),
        'assigned_to' => $actor->getKey(),
        'status' => TaskStatus::OPEN,
        'title' => $title,
        'due_at' => $due,
    ]);

    $make('kemarin', Date::now()->subDay()->toDateTimeString());
    $make('hari ini', Date::now()->addHours(2)->toDateTimeString());
    $make('minggu depan', Date::now()->addDays(3)->toDateTimeString());

    $response = $this->actingAs($actor)->getJson('/api/v1/dashboard/tasks')->assertOk();

    expect($response->json('data.overdue'))->toHaveCount(1)
        ->and($response->json('data.overdue.0.title'))->toBe('kemarin')
        ->and($response->json('data.today'))->toHaveCount(1)
        ->and($response->json('data.upcoming'))->toHaveCount(2);
});

it('surfaces a waiting matter without inventing a staleness threshold', function (): void {
    // The brief proposed "waiting more than 3 days". How long an office tolerates
    // a stalled Matter is an office policy nobody has written down, so every
    // waiting Matter is reported and `days_waiting` lets the reader judge.
    [$actor, $office] = dashboardActor(['notary.matters.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    Matter::factory()->create([
        'office_id' => $office->getKey(),
        'project_id' => $project->getKey(),
        'domain' => MatterDomain::NOTARY,
        'status' => MatterStatus::WAITING,
        'title' => 'Menunggu NPWP penjual',
    ]);

    $response = $this->actingAs($actor)->getJson('/api/v1/dashboard/needs-attention')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.type'))->toBe('MATTER_WAITING')
        ->and($response->json('data.0.title'))->toBe('Menunggu NPWP penjual')
        ->and($response->json('data.0'))->toHaveKey('days_waiting');
});

it('builds workload from assignment rather than from role names', function (): void {
    // The brief specified "only users with role NOTARY_STAFF, PPAT_STAFF,
    // OFFICE_MANAGER". That is role-name authorization, which CLAUDE.md §24 and
    // D-048 forbid. The panel lists whoever the actor may read and who actually
    // holds live work.
    [$actor, $office] = dashboardActor(['users.view', 'tasks.view']);

    $busy = User::factory()->for($office)->create(['name' => 'Rina']);
    User::factory()->for($office)->create(['name' => 'Tidak Sibuk']);

    Task::factory()->count(3)->create([
        'office_id' => $office->getKey(),
        'created_by' => $actor->getKey(),
        'assigned_to' => $busy->getKey(),
        'status' => TaskStatus::OPEN,
    ]);

    $response = $this->actingAs($actor)->getJson('/api/v1/dashboard/workload')->assertOk();

    $names = collect($response->json('data'))->pluck('user_name');

    expect($names)->toContain('Rina')
        // Somebody with nothing assigned does not appear: the panel answers who
        // is busy, never who is supposed to be.
        ->and($names)->not->toContain('Tidak Sibuk');

    expect(collect($response->json('data'))->firstWhere('user_name', 'Rina')['task_count'])->toBe(3);
});

it('nulls workload for an actor who may not read users', function (): void {
    [$actor] = dashboardActor(['tasks.view']);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/workload')
        ->assertOk()
        ->assertJsonPath('data', null);
});

/*
|--------------------------------------------------------------------------
| Activity — authorized per row, by its subject
|--------------------------------------------------------------------------
*/

it('starts the activity feed empty and does not backfill', function (): void {
    // Seven milestones of work happened before `activities` existed and those
    // events were not recorded. D-123 forbids manufacturing rows for them.
    [$actor, $office] = dashboardActor(['projects.view']);

    Project::factory()->count(3)->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/activity')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('shows an activity row whose subject the actor can reach', function (): void {
    [$actor, $office] = dashboardActor(['projects.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    app(ActivityRecorder::class)->record(ActivityType::PROJECT_CREATED, $project, $actor);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/activity')
        ->assertOk()
        ->assertJsonPath('data.0.activity_type', 'PROJECT_CREATED')
        ->assertJsonPath('data.0.subject_type', 'Project')
        ->assertJsonPath('data.0.description_key', 'activity.types.PROJECT_CREATED');
});

it('hides an activity row whose subject the actor cannot reach', function (): void {
    // Absent, not redacted — D-098's treatment of unreachable records applied to
    // a feed. This is the whole authorization story for a table with no
    // capability of its own (O-047).
    [$actor] = dashboardActor(['projects.view']);

    $elsewhere = Office::factory()->create();
    $stranger = User::factory()->for($elsewhere)->create();
    $project = Project::factory()->create(['office_id' => $elsewhere->getKey()]);

    app(ActivityRecorder::class)->record(ActivityType::PROJECT_CREATED, $project, $stranger);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/activity')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('hides every activity row from an actor who can reach no subject at all', function (): void {
    [$actor, $office] = dashboardActor();

    $author = User::factory()->for($office)->create();
    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    app(ActivityRecorder::class)->record(ActivityType::PROJECT_CREATED, $project, $author);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/activity')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('ships a translation key rather than a rendered sentence', function (): void {
    // CLAUDE.md §6: a server that returned "Rina menyetujui akta" would have
    // picked a language for a bilingual product.
    [$actor, $office] = dashboardActor(['projects.view']);

    $project = Project::factory()->create(['office_id' => $office->getKey()]);

    app(ActivityRecorder::class)->record(ActivityType::PROJECT_CREATED, $project, $actor, [
        'title' => $project->title,
    ]);

    $row = $this->actingAs($actor)->getJson('/api/v1/dashboard/activity')->json('data.0');

    expect($row['description_key'])->toStartWith('activity.types.')
        ->and($row['metadata'])->toHaveKey('title');
});

/*
|--------------------------------------------------------------------------
| Deeds
|--------------------------------------------------------------------------
*/

it('nulls a deed domain the actor may not read', function (): void {
    [$actor] = dashboardActor(['notary.deeds.view']);

    $this->actingAs($actor)->getJson('/api/v1/dashboard/deeds')
        ->assertOk()
        ->assertJsonPath('data.ppat', null);

    expect($this->actingAs($actor)->getJson('/api/v1/dashboard/deeds')->json('data.notary'))
        ->toBeArray()
        // Every status appears, including the ones at zero: a chart with holes in
        // it reads as a bug.
        ->toHaveKey('DRAFT');
});
