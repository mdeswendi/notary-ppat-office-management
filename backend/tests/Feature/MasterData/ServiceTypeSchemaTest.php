<?php

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Models\Office;
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

it('gives a service type a generated ULID primary key', function (): void {
    $serviceType = ServiceType::factory()->create();

    expect($serviceType->getKeyType())->toBe('string')
        ->and($serviceType->getIncrementing())->toBeFalse()
        ->and(strlen($serviceType->id))->toBe(26)
        ->and(Str::isUlid($serviceType->id))->toBeTrue();
});

it('carries exactly the canonical columns', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 8, minus the two fields M4.1
    // withholds. Asserting the exact set turns an accidental addition into a
    // failing test rather than a silent schema change.
    $columns = Schema::getColumnListing('service_types');
    sort($columns);

    $expected = [
        'code', 'created_at', 'default_duration_days', 'description_en', 'description_id',
        'domain', 'id', 'is_active', 'name_en', 'name_id', 'office_id', 'sort_order',
        'updated_at',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('withholds the fields whose semantics are not validated', function (): void {
    // `legal_term` and `preserve_legal_term` appear in the ERD field list and are
    // defined nowhere else in the repository. A separate `legal_terms` table
    // exists with its own permissions, so at least three readings are plausible.
    // Withheld until validated, exactly as M3.1 withheld `project_number`.
    expect(Schema::hasColumn('service_types', 'legal_term'))->toBeFalse()
        ->and(Schema::hasColumn('service_types', 'preserve_legal_term'))->toBeFalse();
});

it('records no actor metadata and no soft delete', function (): void {
    // Reference data is not owned by whoever typed it — which is also why `OWN`
    // is withheld from its Data Scopes. Retirement is `is_active`, so there is
    // nothing for a soft delete to mean.
    expect(Schema::hasColumn('service_types', 'created_by'))->toBeFalse()
        ->and(Schema::hasColumn('service_types', 'updated_by'))->toBeFalse()
        ->and(Schema::hasColumn('service_types', 'deleted_at'))->toBeFalse();
});

it('introduces no matter or workflow relation', function (): void {
    // M4.1 is the catalogue and nothing else. Workflow templates belong to M4.6.
    //
    // **Narrowed at M4.2**, which builds `matters` (D-107) — and the direction is
    // the point this test was always making: Matter references Service Type, not
    // the reverse, so `service_types` still gains no column pointing at it.
    expect(Schema::hasColumn('service_types', 'workflow_template_id'))->toBeFalse()
        ->and(Schema::hasColumn('service_types', 'matter_id'))->toBeFalse()
        ->and(Schema::hasTable('workflow_templates'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Office ownership
|--------------------------------------------------------------------------
*/

it('requires a service type to belong to an office', function (): void {
    expect(fn () => ServiceType::factory()->create(['office_id' => null]))
        ->toThrow(QueryException::class);
});

it('rejects a service type pointing at a nonexistent office', function (): void {
    expect(fn () => ServiceType::factory()->create(['office_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

it('refuses to delete an office that still has service types', function (): void {
    // RESTRICT, matching projects.office_id and parties.office_id: removing an
    // Office must not silently take its catalogue with it.
    $office = Office::factory()->create();
    ServiceType::factory()->for($office)->create();

    expect(fn () => $office->delete())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Required content and defaults
|--------------------------------------------------------------------------
*/

it('requires a code, a domain, and both names', function (): void {
    expect(fn () => ServiceType::factory()->create(['code' => null]))->toThrow(QueryException::class);
    expect(fn () => ServiceType::factory()->create(['domain' => null]))->toThrow(QueryException::class);
    expect(fn () => ServiceType::factory()->create(['name_id' => null]))->toThrow(QueryException::class);
    expect(fn () => ServiceType::factory()->create(['name_en' => null]))->toThrow(QueryException::class);
});

it('allows both descriptions and the default duration to be absent', function (): void {
    $serviceType = ServiceType::factory()->create([
        'description_id' => null,
        'description_en' => null,
        'default_duration_days' => null,
    ]);

    expect($serviceType->fresh())->not->toBeNull();
});

it('defaults a service type to active and unordered', function (): void {
    // Set through the database default rather than the factory, so the column
    // itself is what is being asserted.
    $office = Office::factory()->create();

    $id = (string) Str::ulid();
    DB::table('service_types')->insert([
        'id' => $id,
        'office_id' => $office->getKey(),
        'code' => 'UJI_DEFAULTS',
        'domain' => ServiceTypeDomain::NOTARY->value,
        'name_id' => 'Layanan Uji',
        'name_en' => 'Test Service',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $serviceType = ServiceType::query()->findOrFail($id);

    expect($serviceType->is_active)->toBeTrue()
        ->and($serviceType->sort_order)->toBe(0);
});

it('refuses a negative default duration', function (): void {
    // Unsigned, so the database refuses it rather than the application having to
    // remember. Informational planning metadata only — never an SLA.
    //
    // PostgreSQL-only: SQLite's dynamic typing accepts a negative value into an
    // `unsigned integer` column, so asserting this on the test connection would
    // pin behaviour the production engine does not share. Verified again on real
    // PostgreSQL during the disposable-database run.
    expect(fn () => ServiceType::factory()->create(['default_duration_days' => -1]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Unsigned columns are enforced by PostgreSQL here.');

/*
|--------------------------------------------------------------------------
| Code uniqueness
|--------------------------------------------------------------------------
*/

it('refuses two service types with the same code in one office', function (): void {
    $office = Office::factory()->create();
    ServiceType::factory()->for($office)->code('UJI_SAMA')->create();

    expect(fn () => ServiceType::factory()->for($office)->code('UJI_SAMA')->create())
        ->toThrow(QueryException::class);
});

it('permits the same code in two different offices', function (): void {
    // Composite, never global: two Offices may both run the same service, and a
    // global index would fail the second office's first entry for no explicable
    // reason. The O-023 shape, reached for the same reason.
    $first = ServiceType::factory()->for(Office::factory())->code('UJI_SAMA')->create();
    $second = ServiceType::factory()->for(Office::factory())->code('UJI_SAMA')->create();

    expect($first->office_id)->not->toBe($second->office_id)
        ->and($second->exists)->toBeTrue();
});

it('does not let domain create a second uniqueness namespace', function (): void {
    // `domain` is deliberately outside the uniqueness namespace: one code must
    // not mean two different things inside one Office.
    $office = Office::factory()->create();
    ServiceType::factory()->for($office)->code('UJI_DUA')->domain(ServiceTypeDomain::NOTARY)->create();

    expect(fn () => ServiceType::factory()->for($office)->code('UJI_DUA')->domain(ServiceTypeDomain::PPAT)->create())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The M4.2 support key
|--------------------------------------------------------------------------
*/

it('carries the same-office support key m4.2 will reference', function (): void {
    // `(id, office_id)` is what a composite foreign key from `matters` needs in
    // order to make a cross-office Service Type reference unrepresentable —
    // the pattern company_people (D-080) and project_parties (D-098) both use.
    // Added here rather than by a later ALTER; no Matter table exists yet.
    $office = Office::factory()->create();
    $serviceType = ServiceType::factory()->for($office)->create();

    $duplicate = DB::table('service_types')
        ->where('id', $serviceType->getKey())
        ->where('office_id', $office->getKey())
        ->count();

    expect($duplicate)->toBe(1);

    if (DB::connection()->getDriverName() === 'pgsql') {
        $exists = DB::selectOne(
            "SELECT 1 AS ok FROM pg_indexes WHERE tablename = 'service_types' AND indexname = 'service_types_id_office_id_unique'"
        );

        expect($exists)->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Migration reversibility
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // **Three steps since M4.3.** This rolled back one migration while
    // `service_types` was the newest, then two once `matters` landed on top of
    // it; M4.3's reference migration is now the newest, and each layer holds a
    // dependency on the one below, so all three come off together. The assertion
    // is unchanged in substance — this migration is reversible and repeatable.
    $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

    expect(Schema::hasTable('service_types'))->toBeFalse()
        ->and(Schema::hasTable('matters'))->toBeFalse()
        ->and(Schema::hasTable('matter_reference_counters'))->toBeFalse();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('service_types'))->toBeTrue()
        ->and(Schema::hasColumn('service_types', 'is_active'))->toBeTrue()
        ->and(Schema::hasTable('matters'))->toBeTrue();
});
