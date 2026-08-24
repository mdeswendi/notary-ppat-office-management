<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Date::setTestNow('2026-08-25 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

/**
 * An actor holding the named permissions at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function taskManager(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * Everything an office worker managing tasks would hold.
 *
 * @return array<int, string>
 */
function taskCapabilities(): array
{
    return [
        'tasks.view', 'tasks.create', 'tasks.update',
        'tasks.assign', 'tasks.complete', 'tasks.reopen', 'tasks.delete',
        'projects.view', 'notary.matters.view',
    ];
}

/*
|--------------------------------------------------------------------------
| Raising work
|--------------------------------------------------------------------------
*/

it('raises a task in the actor\'s own office', function (): void {
    [$actor] = taskManager(taskCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Perpanjang izin kantor',
        'priority' => 'HIGH',
    ])->assertCreated()
        ->assertJsonPath('data.title', 'Perpanjang izin kantor')
        ->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.priority', 'HIGH')
        ->assertJsonPath('data.created_by.id', $actor->getKey())
        // Work often exists before anybody holds it.
        ->assertJsonPath('data.assigned_to', null)
        ->assertJsonPath('data.is_overdue', false);

    $task = Task::query()->firstOrFail();

    expect($task->office_id)->toBe($actor->office_id)
        ->and($task->created_by)->toBe($actor->getKey())
        ->and($task->assigned_by)->toBeNull();
});

it('accepts a past due date and reports it overdue', function (): void {
    // An office records work that was already due — a deadline that slipped, a
    // task entered on Monday for something owed on Friday. The plan asked to
    // refuse a past date, which would make the system unable to describe the
    // situation it most needs to show.
    [$actor] = taskManager(taskCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Sudah lewat',
        'due_at' => '2026-08-01 09:00:00',
    ])->assertCreated()
        ->assertJsonPath('data.is_overdue', true);
});

it('assigns at creation only for an actor holding the assign capability', function (): void {
    [$actor, $office] = taskManager(['tasks.view', 'tasks.create']);
    $colleague = User::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'assigned_to' => $colleague->getKey(),
    ])->assertForbidden();

    expect(Task::query()->count())->toBe(0);

    grantPermissionScope($actor, 'tasks.assign', DataScope::OFFICE);

    $this->actingAs($actor->fresh())->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'assigned_to' => $colleague->getKey(),
    ])->assertCreated()
        ->assertJsonPath('data.assigned_to.id', $colleague->getKey())
        ->assertJsonPath('data.assigned_by.id', $actor->getKey());
});

it('refuses an assignee from another office', function (): void {
    [$actor] = taskManager(taskCapabilities());
    $stranger = User::factory()->for(Office::factory()->create())->create();

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'assigned_to' => $stranger->getKey(),
    ])->assertStatus(422);
});

it('refuses an inactive assignee', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $retired = User::factory()->for($office)->create(['is_active' => false]);

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'assigned_to' => $retired->getKey(),
    ])->assertStatus(422);
});

it('attaches a task to a project and a matter the caller can reach', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());

    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create();

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'project_id' => $project->getKey(),
        'matter_id' => $matter->getKey(),
    ])->assertCreated()
        ->assertJsonPath('data.project.id', $project->getKey())
        ->assertJsonPath('data.matter.id', $matter->getKey())
        ->assertJsonPath('data.matter.domain', 'NOTARY');
});

it('refuses a project the caller cannot reach', function (): void {
    // `tasks.create` is authority to raise work, never authority to discover
    // which records exist.
    [$actor, $office] = taskManager(['tasks.view', 'tasks.create']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'project_id' => $project->getKey(),
    ])->assertStatus(422);
});

it('refuses a matter reached under the other domain\'s capability', function (): void {
    [$actor, $office] = taskManager(['tasks.view', 'tasks.create', 'notary.matters.view']);

    $ppat = Matter::factory()
        ->for(Project::factory()->for($office)->create())
        ->domain(MatterDomain::PPAT)
        ->create();

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        'matter_id' => $ppat->getKey(),
    ])->assertStatus(422);
});

it('refuses a system-controlled field that is present', function (string $field, mixed $value): void {
    [$actor] = taskManager(taskCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/tasks', [
        'title' => 'Uji',
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'office id' => ['office_id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'status' => ['status', 'COMPLETED'],
    'created by' => ['created_by', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'assigned by' => ['assigned_by', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'completed at' => ['completed_at', '2026-08-25 09:00:00'],
    'workflow stage' => ['workflow_stage_instance_id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    // Present-but-null is refused too: a caller told "accepted" about an
    // instruction that was discarded is worse than one told no.
    'null status' => ['status', null],
]);

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('lists only tasks the caller may reach', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());

    Task::factory()->count(2)->inOffice($office)->create();
    Task::factory()->count(3)->create();

    $this->actingAs($actor)->getJson('/api/v1/tasks')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('filters by status, priority, assignee and relation', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());

    $colleague = User::factory()->for($office)->create();
    $project = Project::factory()->for($office)->create();

    $urgent = Task::factory()->inOffice($office)
        ->priority(ProjectPriority::URGENT)
        ->assignedTo($colleague)
        ->create();

    Task::factory()->forProject($project)->status(TaskStatus::WAITING)->create();

    $this->actingAs($actor)->getJson('/api/v1/tasks?priority=URGENT')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $urgent->getKey());

    $this->actingAs($actor)->getJson('/api/v1/tasks?status=WAITING')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($actor)->getJson("/api/v1/tasks?assigned_to={$colleague->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $urgent->getKey());

    $this->actingAs($actor)->getJson("/api/v1/tasks?project_id={$project->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('filters to open and overdue work', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $creator = User::factory()->for($office)->create();

    Task::factory()->createdBy($creator)->due('2026-08-01 09:00:00')->create();
    Task::factory()->createdBy($creator)->due('2099-01-01 09:00:00')->create();
    Task::factory()->createdBy($creator)->completed($creator)->create();

    $this->actingAs($actor)->getJson('/api/v1/tasks?open=true')
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($actor)->getJson('/api/v1/tasks?overdue=true')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_overdue', true);
});

it('ignores an unrecognized filter rather than erroring', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    Task::factory()->count(2)->inOffice($office)->create();

    $this->actingAs($actor)->getJson('/api/v1/tasks?status=NOT_A_STATUS&sort_by=office_id')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('costs no extra query per row in a list', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    Task::factory()->inOffice($office)->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($actor)->getJson('/api/v1/tasks')->assertOk();
    $small = $queries;

    Task::factory()->count(9)->inOffice($office)->create();

    $queries = 0;
    $this->actingAs($actor)->getJson('/api/v1/tasks')->assertOk();

    expect($queries)->toBe($small);
});

it('answers 404 for a task in another office', function (): void {
    [$actor] = taskManager(taskCapabilities());
    $elsewhere = Task::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/tasks/{$elsewhere->getKey()}")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Editing
|--------------------------------------------------------------------------
*/

it('corrects ordinary attributes and the three live statuses', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}", [
        'title' => 'Judul Baru',
        'status' => 'IN_PROGRESS',
        'priority' => 'HIGH',
    ])->assertOk()
        ->assertJsonPath('data.title', 'Judul Baru')
        ->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.priority', 'HIGH');
});

it('refuses to complete or cancel through an ordinary update', function (string $status): void {
    // Each answers to its own capability; letting `tasks.update` write either
    // would make one grant a silent superset of another (D-091).
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}", [
        'status' => $status,
    ])->assertStatus(422)->assertJsonValidationErrors('status');
})->with(['COMPLETED', 'CANCELLED']);

it('refuses to reassign or re-parent through an ordinary update', function (string $field): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}", [
        $field => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with(['assigned_to', 'project_id', 'matter_id', 'office_id', 'created_by']);

it('refuses to edit a settled task', function (string $status): void {
    // Changing the title of something already finished would rewrite what was
    // done. Reopening first is the honest route.
    [$actor, $office] = taskManager(taskCapabilities());
    $creator = User::factory()->for($office)->create();

    $task = $status === 'COMPLETED'
        ? Task::factory()->inOffice($office)->completed($creator)->create()
        : Task::factory()->inOffice($office)->status(TaskStatus::CANCELLED)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}", ['title' => 'x'])
        ->assertStatus(422);
})->with(['COMPLETED', 'CANCELLED']);

it('does not erase a field the patch omits', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create(['description' => 'Catatan asli']);

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}", ['title' => 'Baru'])
        ->assertOk();

    expect($task->fresh()->description)->toBe('Catatan asli');
});

/*
|--------------------------------------------------------------------------
| Assign, complete, reopen, cancel, delete
|--------------------------------------------------------------------------
*/

it('hands a task over and takes it back', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $colleague = User::factory()->for($office)->create();
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}/assignment", [
        'assigned_to' => $colleague->getKey(),
    ])->assertOk()
        ->assertJsonPath('data.assigned_to.id', $colleague->getKey())
        ->assertJsonPath('data.assigned_by.id', $actor->getKey());

    // Unassigning clears the pair rather than leaving a dangling assigner.
    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}/assignment", [
        'assigned_to' => null,
    ])->assertOk()
        ->assertJsonPath('data.assigned_to', null)
        ->assertJsonPath('data.assigned_by', null);
});

it('refuses an assignment payload that omits the key', function (): void {
    // `present` rather than `nullable`, so a malformed payload cannot quietly
    // take the work off somebody.
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$task->getKey()}/assignment", [])
        ->assertStatus(422)->assertJsonValidationErrors('assigned_to');
});

it('completes, then reopens to IN_PROGRESS', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED')
        ->assertJsonPath('data.completed_by.id', $actor->getKey());

    // Not back to OPEN: the work was started and finished once, and IN_PROGRESS
    // is the honest state for something being revisited.
    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.completed_by', null);
});

it('refuses to complete twice or reopen something live', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/reopen")
        ->assertStatus(422);

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/complete")->assertOk();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/complete")
        ->assertStatus(422);
});

it('cannot reopen a cancelled task', function (): void {
    // Completing states the work is done, which can be wrong; cancelling states
    // it will not happen, and un-saying that quietly would erase the decision.
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED');

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/reopen")
        ->assertStatus(422);
});

it('refuses to delete work still in flight', function (string $status): void {
    // Finish it or cancel it first, so nothing disappears without anybody saying
    // what became of it.
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->status(TaskStatus::from($status))->create();

    $this->actingAs($actor)->deleteJson("/api/v1/tasks/{$task->getKey()}")
        ->assertStatus(422);

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeTrue();
})->with(['OPEN', 'IN_PROGRESS', 'WAITING']);

it('deletes a settled task and keeps its comments', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();
    $comment = TaskComment::factory()->forTask($task)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/cancel")->assertOk();

    $this->actingAs($actor)->deleteJson("/api/v1/tasks/{$task->getKey()}")
        ->assertNoContent();

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeFalse()
        ->and(Task::withTrashed()->whereKey($task->getKey())->exists())->toBeTrue()
        ->and(DB::table('task_comments')->where('id', $comment->getKey())->exists())->toBeTrue();
});

it('exposes no restore endpoint', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/restore")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Comments
|--------------------------------------------------------------------------
*/

it('records a remark and signs it from the session', function (): void {
    [$actor, $office] = taskManager(['tasks.view']);
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/comments", [
        'comment' => 'Sudah dihubungi klien.',
        // Ignored: a comment is signed by the person sending it.
        'user_id' => User::factory()->for($office)->create()->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('user_id');

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/comments", [
        'comment' => 'Sudah dihubungi klien.',
    ])->assertCreated()
        ->assertJsonPath('data.comment', 'Sudah dihubungi klien.')
        ->assertJsonPath('data.author.id', $actor->getKey());
});

it('lets a reader comment on a settled task', function (): void {
    // Explaining why something was closed is the remark most worth having, and it
    // usually arrives just after the closing.
    [$actor, $office] = taskManager(['tasks.view', 'tasks.complete']);
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/complete")->assertOk();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$task->getKey()}/comments", [
        'comment' => 'Selesai lebih cepat.',
    ])->assertCreated();
});

it('reads comments oldest first and never sends updated_at', function (): void {
    [$actor, $office] = taskManager(['tasks.view']);
    $task = Task::factory()->inOffice($office)->create();

    TaskComment::factory()->forTask($task)->create(['comment' => 'Pertama']);
    Date::setTestNow('2026-08-25 10:00:00');
    TaskComment::factory()->forTask($task)->create(['comment' => 'Kedua']);

    $response = $this->actingAs($actor)->getJson("/api/v1/tasks/{$task->getKey()}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.comment', 'Pertama')
        ->assertJsonPath('data.1.comment', 'Kedua');

    expect($response->getContent())->not->toContain('updated_at');
});

it('refuses comments on a task the caller cannot reach', function (): void {
    [$actor] = taskManager(['tasks.view']);
    $elsewhere = Task::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/tasks/{$elsewhere->getKey()}/comments")
        ->assertNotFound();

    $this->actingAs($actor)->postJson("/api/v1/tasks/{$elsewhere->getKey()}/comments", [
        'comment' => 'x',
    ])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Capability flags and options
|--------------------------------------------------------------------------
*/

it('reports flags that match what the endpoints will do', function (): void {
    [$actor, $office] = taskManager(['tasks.view', 'tasks.complete', 'tasks.delete']);
    $task = Task::factory()->inOffice($office)->create();

    $this->actingAs($actor)->getJson("/api/v1/tasks/{$task->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_complete', true)
        ->assertJsonPath('data.can_cancel', true)
        // Live work cannot be deleted, so the flag says so.
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath('data.can_reopen', false)
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.can_assign', false);
});

it('offers the vocabularies and the office\'s active colleagues', function (): void {
    [$actor, $office] = taskManager(taskCapabilities());

    User::factory()->for($office)->create(['name' => 'Aktif']);
    User::factory()->for($office)->create(['name' => 'Nonaktif', 'is_active' => false]);
    User::factory()->for(Office::factory()->create())->create(['name' => 'Kantor Lain']);

    $response = $this->actingAs($actor)->getJson('/api/v1/tasks/options')->assertOk();

    $names = array_column($response->json('data.assignees'), 'name');

    expect($response->json('data.statuses'))->toBe(TaskStatus::values())
        ->and($response->json('data.settable_statuses'))->toBe(['OPEN', 'IN_PROGRESS', 'WAITING'])
        ->and($response->json('data.priorities'))->toBe(['LOW', 'NORMAL', 'HIGH', 'URGENT'])
        ->and($names)->toContain('Aktif')
        ->and($names)->not->toContain('Nonaktif')
        ->and($names)->not->toContain('Kantor Lain');
});

/*
|--------------------------------------------------------------------------
| Surface boundary
|--------------------------------------------------------------------------
*/

it('exposes exactly the twelve task routes and nothing more', function (): void {
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->filter(fn (string $route): bool => str_contains($route, 'tasks'))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe([
        'DELETE api/v1/tasks/{task}',
        'GET api/v1/tasks',
        'GET api/v1/tasks/options',
        'GET api/v1/tasks/{task}',
        'GET api/v1/tasks/{task}/comments',
        'PATCH api/v1/tasks/{task}',
        'PATCH api/v1/tasks/{task}/assignment',
        'POST api/v1/tasks',
        'POST api/v1/tasks/{task}/cancel',
        'POST api/v1/tasks/{task}/comments',
        'POST api/v1/tasks/{task}/complete',
        'POST api/v1/tasks/{task}/reopen',
    ]);
});

it('refuses every task endpoint to a guest', function (string $method, string $path): void {
    $this->{$method}("/api/v1/tasks{$path}", [])->assertUnauthorized();
})->with([
    ['getJson', ''],
    ['postJson', ''],
    ['getJson', '/options'],
    ['getJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['patchJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['deleteJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['patchJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/assignment'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/complete'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/reopen'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/cancel'],
    ['getJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/comments'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/comments'],
]);

it('lets no capability reach another endpoint', function (): void {
    [$actor, $office] = taskManager(['tasks.view']);
    $task = Task::factory()->inOffice($office)->create();
    $id = $task->getKey();

    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($actor)->patchJson("/api/v1/tasks/{$id}/assignment", ['assigned_to' => null])->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/tasks/{$id}/complete")->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/tasks/{$id}/reopen")->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/tasks/{$id}/cancel")->assertForbidden();
    $this->actingAs($actor)->deleteJson("/api/v1/tasks/{$id}")->assertForbidden();
    $this->actingAs($actor)->postJson('/api/v1/tasks', ['title' => 'x'])->assertForbidden();
});
