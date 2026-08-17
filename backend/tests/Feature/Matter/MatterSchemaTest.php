<?php

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\ServiceType;
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

it('gives a matter a generated ULID primary key', function (): void {
    $matter = Matter::factory()->create();

    expect($matter->getKeyType())->toBe('string')
        ->and($matter->getIncrementing())->toBeFalse()
        ->and(strlen($matter->id))->toBe(26)
        ->and(Str::isUlid($matter->id))->toBeTrue();
});

it('carries exactly the canonical M4.2 columns', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 9, minus the two fields M4.2
    // defers. Asserting the exact set turns an accidental addition into a
    // failing test rather than a silent schema change.
    $columns = Schema::getColumnListing('matters');
    sort($columns);

    // `matter_number` joined at M4.3 with its allocator (D-103).
    $expected = [
        'completed_at', 'created_at', 'created_by', 'deleted_at', 'domain', 'id',
        'matter_number', 'notes', 'office_id', 'opened_at', 'pic_user_id', 'priority',
        'project_id', 'service_type_id', 'status', 'target_completion_date', 'title',
        'updated_at', 'updated_by',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('carries the internal reference delivered at M4.3', function (): void {
    // **Narrowed at M4.3 and again at M4.4.** M4.2 asserted the column was
    // absent; M4.3 added it nullable; M4.4 tightened it once every creation path
    // allocates (D-109). What stays true throughout is that the column exists and
    // only the allocator fills it.
    expect(Schema::hasColumn('matters', 'matter_number'))->toBeTrue();

    $matter = Matter::factory()->create();

    expect($matter->fresh()->matter_number)->not->toBeNull();
});

it('defers the current stage pointer to M4.7', function (): void {
    // A nullable ULID now would point at `matter_stage_instances`, which does not
    // exist — a pointer validated by nothing. M4.7 owns it together with the real
    // foreign key.
    expect(Schema::hasColumn('matters', 'current_stage_id'))->toBeFalse();
});

it('uses notes rather than a description column', function (): void {
    // The ERD gives Matter `notes` where it gives Project `description`. No
    // `description` is invented here.
    expect(Schema::hasColumn('matters', 'notes'))->toBeTrue()
        ->and(Schema::hasColumn('matters', 'description'))->toBeFalse();
});

it('builds no notary or ppat extension table', function (): void {
    // D-102: those belong to M6 and M7 with their domain content, and no column
    // stands in for one.
    expect(Schema::hasTable('notary_matters'))->toBeFalse()
        ->and(Schema::hasTable('ppat_matters'))->toBeFalse();

    foreach ([
        'deed_category', 'requires_minuta', 'requires_register_entry',
        'land_office_region', 'tax_processing_required', 'registration_required',
    ] as $column) {
        expect(Schema::hasColumn('matters', $column))->toBeFalse($column);
    }
});

it('builds no workflow or participation table', function (): void {
    foreach ([
        'matter_parties', 'workflow_templates', 'workflow_stages',
        'matter_workflows', 'matter_stage_instances', 'matter_stage_history',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

it('reserves deleted_at without a soft delete lifecycle', function (): void {
    // The column exists because the ERD carries it; the model deliberately does
    // not use SoftDeletes (D-102), so no global scope filters any query.
    expect(Schema::hasColumn('matters', 'deleted_at'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Required fields and defaults
|--------------------------------------------------------------------------
*/

it('requires a project, an office, a domain, a title, and a status', function (): void {
    expect(fn () => Matter::factory()->create(['project_id' => null]))->toThrow(QueryException::class);
    expect(fn () => Matter::factory()->create(['office_id' => null]))->toThrow(QueryException::class);
    expect(fn () => Matter::factory()->create(['domain' => null]))->toThrow(QueryException::class);
    expect(fn () => Matter::factory()->create(['title' => null]))->toThrow(QueryException::class);
    expect(fn () => Matter::factory()->create(['status' => null]))->toThrow(QueryException::class);
});

it('allows the optional fields to be absent', function (): void {
    $matter = Matter::factory()->create([
        'service_type_id' => null,
        'priority' => null,
        'pic_user_id' => null,
        'opened_at' => null,
        'target_completion_date' => null,
        'completed_at' => null,
        'notes' => null,
        'created_by' => null,
        'updated_by' => null,
    ]);

    expect($matter->fresh())->not->toBeNull();
});

it('gives status no database default', function (): void {
    // The database records what the application decided; it does not decide an
    // initial state. A default here would be the thin end of the transition
    // matrix D-102 refuses.
    $project = Project::factory()->create();

    expect(fn () => DB::table('matters')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'office_id' => $project->office_id,
        'domain' => MatterDomain::NOTARY->value,
        'title' => 'Pekerjaan Uji',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The Project same-Office invariant
|--------------------------------------------------------------------------
*/

it('rejects a matter pointing at a nonexistent project', function (): void {
    // `office_id` is supplied explicitly so the foreign key is what refuses this,
    // rather than a null Office produced by looking up a Project that is not
    // there.
    $office = Office::factory()->create();

    expect(fn () => Matter::factory()->create([
        'project_id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a matter whose office disagrees with its project', function (): void {
    // The invariant itself, structural rather than validated: both endpoints
    // resolve through the same `office_id` column, so a Matter cannot disagree
    // with its Project about which Office owns the work.
    $project = Project::factory()->create();
    $otherOffice = Office::factory()->create();

    expect(fn () => Matter::factory()->create([
        'project_id' => $project->getKey(),
        'office_id' => $otherOffice->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a project that still has matters', function (): void {
    $project = Project::factory()->create();
    Matter::factory()->create(['project_id' => $project->getKey(), 'office_id' => $project->office_id]);

    expect(fn () => $project->forceDelete())->toThrow(QueryException::class);
});

it('keeps a matter when its project is archived', function (): void {
    // Soft deletion leaves the row, so the foreign key is unaffected. An existing
    // Matter survives its Project being archived; whether a *new* Matter may be
    // opened under one is an authorization question, not a schema one.
    $project = Project::factory()->create();
    $matter = Matter::factory()->create(['project_id' => $project->getKey(), 'office_id' => $project->office_id]);

    $project->delete();

    expect($matter->fresh())->not->toBeNull()
        // The Project row is still there — soft deletion sets a timestamp — which
        // is exactly why the foreign key is unaffected.
        ->and($project->fresh()->trashed())->toBeTrue()
        ->and(Project::query()->find($project->getKey()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The Service Type same-Office, same-domain invariant
|--------------------------------------------------------------------------
*/

it('accepts a matter with no service type', function (): void {
    // A composite foreign key with a NULL component is satisfied, which is what
    // the nullable ruling requires (D-102).
    $matter = Matter::factory()->create(['service_type_id' => null]);

    expect($matter->fresh()->service_type_id)->toBeNull();
});

it('accepts a service type in the same office and domain', function (): void {
    $matter = Matter::factory()->withServiceType()->create();

    expect($matter->fresh()->service_type_id)->not->toBeNull();
});

it('refuses a service type from another office', function (): void {
    $matter = Matter::factory()->create();
    $foreign = ServiceType::factory()->for(Office::factory())->create();

    expect(fn () => $matter->forceFill(['service_type_id' => $foreign->getKey()])->save())
        ->toThrow(QueryException::class);
});

it('refuses a service type of the other domain', function (): void {
    // Same Office, wrong domain: a Notary Matter must not be classified with a
    // PPAT service. One composite key does both jobs.
    $project = Project::factory()->create();
    $matter = Matter::factory()->create([
        'project_id' => $project->getKey(),
        'office_id' => $project->office_id,
        'domain' => MatterDomain::NOTARY,
    ]);

    $ppatService = ServiceType::factory()
        ->for($project->office)
        ->domain(ServiceTypeDomain::PPAT)
        ->create();

    expect(fn () => $matter->forceFill(['service_type_id' => $ppatService->getKey()])->save())
        ->toThrow(QueryException::class);
});

it('refuses to delete a service type that still classifies a matter', function (): void {
    $matter = Matter::factory()->withServiceType()->create();
    $serviceType = ServiceType::query()->findOrFail($matter->service_type_id);

    expect(fn () => $serviceType->delete())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Support keys
|--------------------------------------------------------------------------
*/

it('carries the same-office support key m4.5 will reference', function (): void {
    // `(id, office_id)` is what a composite foreign key from `matter_parties`
    // needs to make a cross-office participation unrepresentable (D-105).
    $matter = Matter::factory()->create();

    expect(DB::table('matters')
        ->where('id', $matter->getKey())
        ->where('office_id', $matter->office_id)
        ->count())->toBe(1);

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(DB::selectOne(
            "SELECT 1 AS ok FROM pg_indexes WHERE tablename = 'matters' AND indexname = 'matters_id_office_id_unique'"
        ))->not->toBeNull();
    }
});

it('adds the service type support key the domain invariant needs', function (): void {
    // M4.1 shipped `(id, office_id)`; the domain half is added here because a
    // composite foreign key needs a unique index on the exact referenced columns.
    if (DB::connection()->getDriverName() !== 'pgsql') {
        expect(true)->toBeTrue();

        return;
    }

    expect(DB::selectOne(
        "SELECT 1 AS ok FROM pg_indexes WHERE tablename = 'service_types' AND indexname = 'service_types_id_office_id_domain_unique'"
    ))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Coded columns
|--------------------------------------------------------------------------
*/

it('rejects an invalid domain, status, or priority at the database level', function (string $column, string $value): void {
    $project = Project::factory()->create();

    $row = [
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'office_id' => $project->office_id,
        'domain' => MatterDomain::NOTARY->value,
        'title' => 'Pekerjaan Uji',
        'status' => MatterStatus::OPEN->value,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $row[$column] = $value;

    expect(fn () => DB::table('matters')->insert($row))->toThrow(QueryException::class);
})->with([
    'domain' => ['domain', 'KEDUANYA'],
    'status' => ['status', 'SEDANG_DIPROSES'],
    'priority' => ['priority', 'SANGAT_TINGGI'],
])->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are PostgreSQL-only here; the model enum casts cover SQLite.',
);

/*
|--------------------------------------------------------------------------
| Migration reversibility
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // **Two steps since M4.3, not one.** This rolled back a single migration
    // while `matters` was the newest; the reference migration is now, and it adds
    // a column and a counter table on top, so both must come off together. The
    // assertion is unchanged in substance — this migration is reversible and
    // repeatable.
    // Three steps since M4.4, which tightened the reference column on top of the
    // M4.3 migration that added it.
    $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

    expect(Schema::hasTable('matters'))->toBeFalse()
        ->and(Schema::hasTable('matter_reference_counters'))->toBeFalse()
        // The M4.1 table survives, and only the support key M4.2 added is gone.
        ->and(Schema::hasTable('service_types'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasColumn('matters', 'deleted_at'))->toBeTrue();
});
