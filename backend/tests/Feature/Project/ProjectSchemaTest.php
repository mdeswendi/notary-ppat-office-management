<?php

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ValueError;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Aggregate shape
|--------------------------------------------------------------------------
*/

it('gives a project a generated ULID primary key', function (): void {
    $project = Project::factory()->create();

    expect($project->getKeyType())->toBe('string')
        ->and($project->getIncrementing())->toBeFalse()
        ->and(strlen($project->id))->toBe(26)
        ->and(Str::isUlid($project->id))->toBeTrue();
});

it('requires a project to belong to an office', function (): void {
    expect(fn () => Project::factory()->create(['office_id' => null]))
        ->toThrow(QueryException::class);
});

it('rejects a project pointing at a nonexistent office', function (): void {
    expect(fn () => Project::factory()->create(['office_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

it('refuses to delete an office that still has projects', function (): void {
    // RESTRICT, matching parties.office_id and users.office_id: removing an
    // Office must not silently take its work with it.
    $office = Office::factory()->create();
    Project::factory()->for($office)->create();

    expect(fn () => $office->delete())->toThrow(QueryException::class);
});

it('requires a title and a status', function (): void {
    expect(fn () => Project::factory()->create(['title' => null]))->toThrow(QueryException::class);
    expect(fn () => Project::factory()->create(['status' => null]))->toThrow(QueryException::class);
});

it('allows the optional fields to be absent', function (): void {
    // A Project can exist before anyone has been put in charge of it or a date
    // agreed. Nothing here is required by any canonical document.
    $project = Project::factory()->create([
        'description' => null,
        'priority' => null,
        'pic_user_id' => null,
        'opened_at' => null,
        'target_completion_date' => null,
        'completed_at' => null,
        'created_by' => null,
        'updated_by' => null,
    ]);

    expect($project->fresh())->not->toBeNull();
});

it('rejects a pic or actor pointing at a nonexistent user', function (): void {
    foreach (['pic_user_id', 'created_by', 'updated_by'] as $column) {
        expect(fn () => Project::factory()->create([$column => (string) Str::ulid()]))
            ->toThrow(QueryException::class);
    }
});

/*
|--------------------------------------------------------------------------
| Stable codes
|--------------------------------------------------------------------------
*/

it('stores status and priority as stable codes, never translated labels', function (): void {
    $project = Project::factory()->create([
        'status' => ProjectStatus::IN_PROGRESS,
        'priority' => ProjectPriority::HIGH,
    ]);

    $row = DB::table('projects')->where('id', $project->id)->first();

    expect($row->status)->toBe('IN_PROGRESS')
        ->and($row->priority)->toBe('HIGH')
        ->and($project->fresh()->status)->toBe(ProjectStatus::IN_PROGRESS)
        ->and($project->fresh()->priority)->toBe(ProjectPriority::HIGH);
});

it('rejects an invalid status or priority through the model cast', function (): void {
    // SQLite cannot add a CHECK after the fact, so on the test connection the
    // enum cast is what refuses the value. PostgreSQL additionally refuses it at
    // the database, which the disposable-database verification covers.
    expect(fn () => Project::factory()->create(['status' => 'Sedang Diproses']))
        ->toThrow(ValueError::class);

    expect(fn () => Project::factory()->create(['priority' => 'Mendesak']))
        ->toThrow(ValueError::class);
});

/*
|--------------------------------------------------------------------------
| Lifecycle: three separate concerns
|--------------------------------------------------------------------------
*/

it('soft deletes a project without touching its business status', function (): void {
    // D-093: business status ARCHIVED and deleted_at are different states with
    // unfortunately similar names. Deleting the record must not rewrite the
    // status, and the status must not imply the record is gone.
    $project = Project::factory()->status(ProjectStatus::IN_PROGRESS)->create();

    $project->delete();

    $trashed = Project::withTrashed()->find($project->id);

    expect(Project::find($project->id))->toBeNull()
        ->and($trashed->trashed())->toBeTrue()
        ->and($trashed->status)->toBe(ProjectStatus::IN_PROGRESS);
});

it('keeps a business status of ARCHIVED distinct from a deleted record', function (): void {
    $archivedStatus = Project::factory()->status(ProjectStatus::ARCHIVED)->create();

    expect($archivedStatus->trashed())->toBeFalse()
        ->and(Project::find($archivedStatus->id))->not->toBeNull();
});

it('restores a soft-deleted project', function (): void {
    $project = Project::factory()->create();
    $project->delete();

    Project::withTrashed()->find($project->id)->restore();

    expect(Project::find($project->id))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Mutation boundaries — D-091 expressed in the schema layer
|--------------------------------------------------------------------------
*/

it('withholds the separately-governed fields from mass assignment', function (): void {
    // The D-091 boundary, enforced where it cannot be forgotten: an Action that
    // fills a request body cannot reassign a Project or move its status, because
    // the model refuses the fields rather than trusting the Action to filter.
    $project = new Project;

    foreach (['office_id', 'pic_user_id', 'status', 'created_by', 'updated_by'] as $field) {
        expect($project->isFillable($field))->toBeFalse($field);
    }

    foreach (['title', 'description', 'priority'] as $field) {
        expect($project->isFillable($field))->toBeTrue($field);
    }
});

it('refuses to move a project between offices', function (): void {
    // D-089: no Office-transfer operation is designed for M3. An engineering
    // boundary, not a claim of legal impossibility.
    $project = Project::factory()->create();
    $elsewhere = Office::factory()->create();

    $project->office_id = $elsewhere->getKey();

    expect(fn () => $project->save())->toThrow(RuntimeException::class, 'immutable during M3');
});

it('records actor metadata that survives the person', function (): void {
    $actor = User::factory()->create();
    $project = Project::factory()->createdBy($actor)->assignedTo($actor)->create();

    expect($project->createdBy->is($actor))->toBeTrue()
        ->and($project->picUser->is($actor))->toBeTrue();

    // RESTRICT: attribution must survive, and no user-deletion path exists
    // anyway (D-050).
    expect(fn () => DB::table('users')->where('id', $actor->getKey())->delete())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Recorded absences — each one a decision, not an oversight
|--------------------------------------------------------------------------
*/

it('carries no primary_client_party_id', function (): void {
    // D-092: rejected as duplicate persistence. project_parties already carries
    // participation and has is_primary; a column here would be a second
    // mechanism for one fact, and would re-create the "client" concept D-078
    // refused, one column at a time.
    expect(Schema::hasColumn('projects', 'primary_client_party_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'client_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'party_id'))->toBeFalse();
});

it('carries no internal reference column yet', function (): void {
    // D-094: the column and its allocator arrive together in M3.2. Adding it
    // nullable-and-empty now would leave every M3.1 Project with a null
    // reference and hand M3.2 a backfill plus an unanswered uniqueness question
    // — exactly the speculation D-086 refused for the fingerprint column.
    expect(Schema::hasColumn('projects', 'project_number'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'reference'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'internal_reference'))->toBeFalse();
});

it('copies no Party sensitive identity into the project table', function (): void {
    foreach (['nik', 'npwp', 'tax_id', 'nik_masked', 'npwp_fingerprint'] as $column) {
        expect(Schema::hasColumn('projects', $column))->toBeFalse($column);
    }
});

/*
|--------------------------------------------------------------------------
| Milestone boundary — M3.1 built Project and nothing beyond it
|--------------------------------------------------------------------------
*/

it('introduces no Matter persistence', function (): void {
    // D-087: Matter is M4. Project does not point at it either — Matter will
    // reference Project, not the reverse.
    foreach (['matters', 'matter_parties', 'notary_matters', 'ppat_matters'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    expect(Schema::hasColumn('projects', 'matter_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'current_stage_id'))->toBeFalse();
});

it('introduces no participation, workflow, or later-milestone table', function (): void {
    // project_parties is M3.4 (D-092); the rest are M4 and beyond.
    foreach ([
        'project_parties', 'service_types', 'workflow_templates', 'workflow_stages',
        'matter_workflows', 'matter_stage_instances', 'documents', 'properties', 'tasks',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

it('exposes no Project HTTP surface yet', function (): void {
    // M3.1 is schema and authorization foundation only; CRUD is M3.3. The Policy
    // is tested directly instead, exactly as M2.1 tested Party's.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'project'));

    expect($routes)->toBeEmpty();
});
