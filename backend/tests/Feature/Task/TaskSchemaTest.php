<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Task\Enums\TaskStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Table shape
|--------------------------------------------------------------------------
*/

it('gives a task a generated ULID primary key', function (): void {
    $task = Task::factory()->create();

    expect($task->getKeyType())->toBe('string')
        ->and($task->getIncrementing())->toBeFalse()
        ->and(strlen($task->id))->toBe(26)
        ->and(Str::isUlid($task->id))->toBeTrue();
});

it('carries exactly the canonical M5.4 task columns', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 15, with one addition and one
    // omission, both decided rather than drifted into: `created_by` is added
    // because Data Scope OWN needs an owner and `assigned_by` cannot be it, and
    // `workflow_stage_instance_id` is left out because nothing raises tasks from
    // stages (D-104, D-119).
    $columns = Schema::getColumnListing('tasks');
    sort($columns);

    $expected = [
        'assigned_by', 'assigned_to', 'completed_at', 'completed_by', 'created_at',
        'created_by', 'deleted_at', 'description', 'due_at', 'id', 'matter_id',
        'office_id', 'priority', 'project_id', 'status', 'title', 'updated_at',
    ];
    sort($expected);

    expect($columns)->toBe($expected)
        ->and(Schema::hasColumn('tasks', 'workflow_stage_instance_id'))->toBeFalse();
});

it('carries exactly the canonical task_comments columns', function (): void {
    // The ERD gives this table no `office_id`: a comment is reached through its
    // Task, which carries the Office, so a carrier column would be a second answer
    // to a question the parent already answers.
    $columns = Schema::getColumnListing('task_comments');
    sort($columns);

    expect($columns)->toBe([
        'comment', 'created_at', 'deleted_at', 'id', 'task_id', 'updated_at', 'user_id',
    ])->and(Schema::hasColumn('task_comments', 'office_id'))->toBeFalse();
});

it('names exactly the five canonical statuses and borrows the priority vocabulary', function (): void {
    expect(TaskStatus::values())
        ->toBe(['OPEN', 'IN_PROGRESS', 'WAITING', 'COMPLETED', 'CANCELLED']);

    // The ERD gives Task LOW NORMAL HIGH URGENT — which is ProjectPriority
    // exactly. A third identical enum would be three places for one vocabulary to
    // drift, so Task borrows it as Matter does (D-095).
    expect(array_map(
        static fn (ProjectPriority $case): string => $case->value,
        ProjectPriority::cases(),
    ))->toBe(['LOW', 'NORMAL', 'HIGH', 'URGENT']);

    expect(enum_exists('App\Domains\Task\Enums\TaskPriority'))->toBeFalse();
});

it('stores machine codes rather than translated labels', function (): void {
    $task = Task::factory()->create();

    $row = DB::table('tasks')->where('id', $task->getKey())->first();

    expect($row->status)->toBe('OPEN')
        ->and($row->priority)->toBe('NORMAL');
});

it('refuses a status the enum does not name', function (): void {
    Task::factory()->create(['status' => 'Sedang Dikerjakan']);
})->throws(ValueError::class);

/*
|--------------------------------------------------------------------------
| The two ownership predicates
|--------------------------------------------------------------------------
*/

it('requires a creator and leaves the assignee optional', function (): void {
    // Work often exists before anybody holds it, so a Task with no assignee is
    // complete rather than a draft. The plan proposed defaulting `assigned_to` to
    // the creator, which would have made every unassigned task look assigned.
    $task = Task::factory()->create();

    expect($task->created_by)->not->toBeNull()
        ->and($task->assigned_to)->toBeNull()
        ->and($task->assigned_by)->toBeNull();

    expect(fn () => Task::factory()->create(['created_by' => null]))
        ->toThrow(QueryException::class);
});

it('keeps created_by immutable', function (): void {
    // It is the OWN scope predicate: changing it would move a task between
    // people's reach without anybody deciding it.
    $task = Task::factory()->create();
    $task->created_by = User::factory()->for(Office::query()->findOrFail($task->office_id))->create()->getKey();
    $task->save();
})->throws(RuntimeException::class, 'immutable');

it('refuses to move a task between offices', function (): void {
    $task = Task::factory()->create();
    $task->office_id = Office::factory()->create()->getKey();
    $task->save();
})->throws(RuntimeException::class, 'immutable');

/*
|--------------------------------------------------------------------------
| Office boundary
|--------------------------------------------------------------------------
*/

it('makes a cross-office assignee unrepresentable', function (): void {
    // The composite key resolves through this table's own `office_id`, so an
    // assignee from another Office cannot be recorded — including for an actor
    // holding ALL. The `UNIQUE (id, office_id)` this needs was added to `users` by
    // its own migration (D-119).
    $task = Task::factory()->create();
    $stranger = User::factory()->for(Office::factory()->create())->create();

    DB::table('tasks')->where('id', $task->getKey())
        ->update(['assigned_to' => $stranger->getKey()]);
})->throws(QueryException::class);

it('makes a cross-office project unrepresentable', function (): void {
    $task = Task::factory()->create();
    $elsewhere = Project::factory()->create();

    DB::table('tasks')->where('id', $task->getKey())
        ->update(['project_id' => $elsewhere->getKey()]);
})->throws(QueryException::class);

it('carries the users support key the composite assignment keys need', function (): void {
    // Redundant for uniqueness — `id` is already the primary key — and required
    // for a composite foreign key. Asserted because dropping it would silently
    // take four foreign keys with it.
    $office = Office::factory()->create();
    $first = User::factory()->for($office)->create();

    expect($first->office_id)->toBe($office->getKey());

    // The same id in two Offices is impossible anyway; what matters is that the
    // pair is indexed, which the assignment keys below prove by existing.
    $task = Task::factory()->inOffice($office)->create();
    $colleague = User::factory()->for($office)->create();

    $task->assigned_to = $colleague->getKey();
    $task->assigned_by = $first->getKey();
    $task->save();

    expect($task->fresh()->assigned_to)->toBe($colleague->getKey());
});

/*
|--------------------------------------------------------------------------
| Completion is a pair
|--------------------------------------------------------------------------
*/

it('refuses half of a completion', function (array $half): void {
    // A PostgreSQL CHECK enforces this; the model guard is what holds it on the
    // SQLite connection the suite runs on.
    Task::factory()->create($half);
})->with([
    'when without who' => [['completed_at' => '2026-08-25 09:00:00']],
    'who without when' => [['completed_by' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']],
])->throws(RuntimeException::class);

it('writes and clears the completion pair together', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    $task = Task::factory()->inOffice($office)->create();

    $task->status = TaskStatus::COMPLETED;
    $task->completed_at = now();
    $task->completed_by = $actor->getKey();
    $task->save();

    expect($task->fresh()->completed_by)->toBe($actor->getKey());

    $task->status = TaskStatus::IN_PROGRESS;
    $task->completed_at = null;
    $task->completed_by = null;
    $task->save();

    expect($task->fresh()->completed_at)->toBeNull()
        ->and($task->fresh()->completed_by)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Overdue is computed, never stored
|--------------------------------------------------------------------------
*/

it('computes overdue from the due date and the status', function (): void {
    // Not a sixth status: the ERD names five, and overdue is a fact about a date
    // rather than a state somebody set. A row that went stale overnight would need
    // a job to notice; a comparison at read time is always right.
    expect(Schema::hasColumn('tasks', 'is_overdue'))->toBeFalse();

    $past = Task::factory()->due('2020-01-01 00:00:00')->create();
    $future = Task::factory()->due('2099-01-01 00:00:00')->create();
    $undated = Task::factory()->create();

    expect($past->isOverdue())->toBeTrue()
        ->and($future->isOverdue())->toBeFalse()
        // An absent deadline is not a missed one.
        ->and($undated->isOverdue())->toBeFalse();
});

it('stops calling a task overdue once it is settled', function (string $status): void {
    $task = Task::factory()->due('2020-01-01 00:00:00')->create();

    // Set through the column rather than the Action, so this tests the accessor
    // rather than the transition rules.
    $task->status = TaskStatus::from($status);

    if ($status === 'COMPLETED') {
        $task->completed_at = now();
        $task->completed_by = $task->created_by;
    }

    $task->save();

    expect($task->fresh()->isOverdue())->toBeFalse();
})->with(['COMPLETED', 'CANCELLED']);

/*
|--------------------------------------------------------------------------
| Comments
|--------------------------------------------------------------------------
*/

it('refuses to edit a comment once it is written', function (): void {
    $comment = TaskComment::factory()->create();

    $comment->comment = 'diubah';
    $comment->save();
})->throws(RuntimeException::class, 'written once');

it('retains comments through a soft delete', function (): void {
    // `task_id` cascades on a hard delete, which nothing performs. A soft delete
    // leaves every remark intact, so restoring a task later restores the
    // conversation with it.
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $task = Task::factory()->inOffice($office)->completed($actor)->create();
    $comment = TaskComment::factory()->forTask($task)->create();

    $task->delete();

    expect(Task::query()->whereKey($task->getKey())->exists())->toBeFalse()
        ->and(Task::withTrashed()->whereKey($task->getKey())->exists())->toBeTrue()
        ->and(DB::table('task_comments')->where('id', $comment->getKey())->exists())->toBeTrue();
});

it('refuses to delete a user who has spoken', function (): void {
    // Attribution survives the person who typed it (D-050).
    $comment = TaskComment::factory()->create();

    DB::table('users')->where('id', $comment->user_id)->delete();
})->throws(QueryException::class);

it('gives both models a soft-delete lifecycle', function (): void {
    expect(in_array(SoftDeletes::class, class_uses_recursive(Task::class), true))->toBeTrue()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(TaskComment::class), true))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Referential rules
|--------------------------------------------------------------------------
*/

it('refuses to delete a project or matter that still has tasks', function (): void {
    // RESTRICT, never SET NULL: work does not become ownerless because the
    // engagement it belongs to was deleted. SET NULL is also impossible on a
    // composite key here — it would null `office_id` too, which is NOT NULL.
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create();

    Task::factory()->forProject($project)->create();
    Task::factory()->forMatter($matter)->create();

    expect(fn () => DB::table('projects')->where('id', $project->getKey())->delete())
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('matters')->where('id', $matter->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('lets a task exist with no project and no matter', function (): void {
    // Office work is not always about a specific engagement: renewing a licence,
    // filing a return, chasing a signature.
    $task = Task::factory()->create();

    expect($task->project_id)->toBeNull()
        ->and($task->matter_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Milestone boundary
|--------------------------------------------------------------------------
*/

it('builds no task template table', function (): void {
    // 03_DATABASE_ERD.md section 15 lists `task_templates` with
    // `workflow_stage_id`, `default_assignee_role` and `due_days_offset` —
    // auto-creating tasks from a workflow stage. That is workflow content D-104
    // forbids inferring, and `default_assignee_role` would be role-name
    // authorization if anything read it as such (D-032, D-041).
    expect(Schema::hasTable('task_templates'))->toBeFalse();
});

it('improvises no audit or notification store', function (): void {
    // D-115: no half-measure ships. `assigned_by`, `created_by`, `completed_by`
    // and the timestamps record who and when on the row itself.
    expect(Schema::hasTable('audit_logs'))->toBeFalse()
        ->and(Schema::hasTable('notifications'))->toBeFalse()
        ->and(Schema::hasTable('activity_log'))->toBeFalse()
        ->and(class_exists('App\Models\Activity'))->toBeFalse();
});

it('registers no new permission', function (): void {
    // All eight `tasks.*` codes have been canonical since the catalogue was
    // transcribed. M5.4 decides their predicates; it adds nothing.
    expect(PermissionRegistry::all())->toHaveCount(177);
});

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    $steps = rollbackStepsTo('add_user_office_support_key');

    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    expect(Schema::hasTable('tasks'))->toBeFalse()
        ->and(Schema::hasTable('task_comments'))->toBeFalse()
        // Everything below M5.4 survives, which is the half a rollback test
        // usually forgets to make.
        ->and(Schema::hasTable('documents'))->toBeTrue()
        ->and(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasTable('task_comments'))->toBeTrue();
});
