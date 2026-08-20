<?php

use App\Domains\Project\Enums\ProjectPriority;
use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

it('carries exactly one internal reference column', function (): void {
    // **Narrowed at M3.2, not deleted.** This asserted `project_number` did not
    // exist, which was true while D-094 held allocation back to M3.2 — the
    // column and its allocator arrive together, so neither ships alone. M3.2
    // brought both, so that half of the claim expired.
    //
    // What has not expired is the part worth keeping: there must be exactly one
    // reference column. A second one would be two answers to "what is this
    // Project called", and they would drift.
    expect(Schema::hasColumn('projects', 'project_number'))->toBeTrue()
        ->and(Schema::hasColumn('projects', 'reference'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'internal_reference'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'number'))->toBeFalse();
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
    // D-087: Matter is M4. Project does not point at it either — Matter
    // references Project, not the reverse.
    //
    // **Narrowed at M4.2, not deleted.** `matters` was on this list while Matter
    // was unbuilt; M4.2 owns it now (D-107) and its own schema test asserts its
    // shape. **Narrowed again at M4.5**, which builds `matter_parties` (D-105).
    // The extension tables remain M6/M7 (D-102). What this test was always
    // really about is unchanged and still asserted below — **Project gains no
    // column pointing at any of it.**
    foreach (['notary_matters', 'ppat_matters'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    expect(Schema::hasColumn('projects', 'matter_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'current_stage_id'))->toBeFalse();
});

it('introduces no workflow or later-milestone table', function (): void {
    // **Narrowed at M3.4, not deleted.** `project_parties` was on this list
    // while participation was still unbuilt; M3.4 owns it now, and its own
    // schema test asserts its shape. Everything else is M4 and beyond and stays
    // exactly as it was.
    //
    // **Narrowed again at M4.1**, which builds `service_types` (D-106). The
    // Service Type catalogue was on this list while master data was unbuilt; it
    // has its own schema test now. What this test was always really about is
    // unchanged and still asserted below: **Project gains no foreign key into
    // any of it.**
    //
    // `project_reference_counters` is deliberately absent from this list — M3.2
    // added it, and it is Project allocator infrastructure rather than a
    // later-milestone surface.
    // **Narrowed again at M4.6**, which builds `workflow_templates` and
    // `workflow_stages` (D-111). Both have their own schema test; what is left
    // here is M4.7 and beyond.
    foreach ([
        'matter_workflows', 'matter_stage_instances', 'documents', 'properties', 'tasks',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    // The point that survives every narrowing: Project points at none of it —
    // and now that a workflow template genuinely exists, the second assertion
    // finally has something real to be false about.
    expect(Schema::hasColumn('projects', 'service_type_id'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'workflow_template_id'))->toBeFalse();
});

it('generalizes the counter into no legal numbering framework', function (): void {
    // The allocator is Project-specific on purpose (M3.2). A shared
    // `legal_number_sequences` or `deed_sequences` table would pull deed,
    // repertorium, and register numbering — none of which has a validated
    // domain rule — into a milestone that owns none of them.
    //
    // **Narrowed at M4.3, not deleted.** `matter_reference_counters` was on this
    // list as a stand-in for "the Project counter got generalized". M4.3 creates
    // it as a **separate, dedicated** table (D-108), which is what M3.2 said
    // should happen rather than what it warned against — so the table's existence
    // is now checked for the opposite reason, below.
    foreach ([
        'legal_number_sequences', 'number_sequences', 'sequences', 'deed_sequences',
        'matter_sequences', 'reference_counters',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    // The Project counter stayed Project-shaped: it gained no domain dimension
    // and no Matter column when Matter got its own allocator.
    expect(Schema::hasColumn('project_reference_counters', 'domain'))->toBeFalse()
        ->and(Schema::hasColumn('project_reference_counters', 'matter_id'))->toBeFalse();
});

it('exposes exactly the expected Project routes and nothing more', function (): void {
    // **Narrowed at M3.3, not deleted.** M3.1 asserted there was no Project route
    // at all, which was true while it shipped schema and authorization only.
    // M3.3 ships the product surface, so the assertion becomes an inventory —
    // which is the more useful guard anyway: a new route now has to be added
    // here deliberately.
    //
    // Extended at M3.4 with the five participation routes, deliberately and in
    // one place.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/projects'))
        ->map(fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
        ->unique()->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE api/v1/projects/{project}',
        'DELETE api/v1/projects/{project}/parties/{projectParty}',
        'GET api/v1/projects',
        'GET api/v1/projects/archived',
        'GET api/v1/projects/{project}',
        'GET api/v1/projects/{project}/assignment/options',
        'GET api/v1/projects/{project}/parties',
        'GET api/v1/projects/{project}/party-options',
        'PATCH api/v1/projects/{project}',
        'PATCH api/v1/projects/{project}/assignment',
        'PATCH api/v1/projects/{project}/parties/{projectParty}',
        'PATCH api/v1/projects/{project}/status',
        'POST api/v1/projects',
        'POST api/v1/projects/{project}/parties',
        'POST api/v1/projects/{project}/restore',
    ]);
});

it('routes the literal archived path before the id binding', function (): void {
    // Registration order matters: `projects/{project}` would otherwise swallow
    // `projects/archived` and answer 404 for a surface that exists.
    $matched = app('router')->getRoutes()->match(
        Request::create('/api/v1/projects/archived', 'GET')
    );

    expect($matched->getName())->toBe('api.v1.projects.archived.index');
});
