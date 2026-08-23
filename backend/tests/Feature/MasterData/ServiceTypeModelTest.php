<?php

use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Models\Office;
use App\Models\ServiceType;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Domain vocabulary
|--------------------------------------------------------------------------
*/

it('publishes exactly the canonical domain vocabulary', function (): void {
    expect(ServiceTypeDomain::values())->toBe(['NOTARY', 'PPAT']);
});

it('offers no domain-neutral or combined case', function (): void {
    // A Service Type belongs to exactly one domain. A `BOTH` case could not be
    // offered to either domain-split Matter surface without deciding which
    // permission governs it (D-101).
    $names = array_map(fn (ServiceTypeDomain $case): string => $case->name, ServiceTypeDomain::cases());

    expect($names)->not->toContain('BOTH')
        ->and($names)->not->toContain('ANY')
        ->and(count($names))->toBe(2);
});

it('casts the domain to the enum', function (): void {
    $serviceType = ServiceType::factory()->domain(ServiceTypeDomain::PPAT)->create();

    expect($serviceType->fresh()->domain)->toBe(ServiceTypeDomain::PPAT);
});

it('refuses a domain the enum does not name', function (): void {
    // The enum cast refuses it before SQL is reached, on either connection. The
    // database's own refusal is asserted separately below and again on real
    // PostgreSQL during the disposable-database verification.
    expect(fn () => ServiceType::factory()->create(['domain' => 'BOTH']))
        ->toThrow(ValueError::class);
});

it('rejects an invalid domain at the database level on postgresql', function (): void {
    // Independent of PHP: written through the query builder, so no cast runs.
    $office = Office::factory()->create();

    expect(fn () => DB::table('service_types')->insert([
        'id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
        'code' => 'UJI_CHECK',
        'domain' => 'LAINNYA',
        'name_id' => 'Layanan Uji',
        'name_en' => 'Test Service',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'CHECK constraints are PostgreSQL-only here.');

/*
|--------------------------------------------------------------------------
| Casts and relationships
|--------------------------------------------------------------------------
*/

it('casts activation, ordering, and duration', function (): void {
    $serviceType = ServiceType::factory()->create([
        'sort_order' => 5,
        'default_duration_days' => 14,
    ]);

    $fresh = $serviceType->fresh();

    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->sort_order)->toBe(5)
        ->and($fresh->default_duration_days)->toBe(14);
});

it('belongs to its office', function (): void {
    $office = Office::factory()->create();
    $serviceType = ServiceType::factory()->for($office)->create();

    expect($serviceType->office)->not->toBeNull()
        ->and($serviceType->office->getKey())->toBe($office->getKey());
});

/*
|--------------------------------------------------------------------------
| Identity is immutable; content is not
|--------------------------------------------------------------------------
*/

it('refuses to change the office', function (): void {
    $serviceType = ServiceType::factory()->create();
    $other = Office::factory()->create();

    expect(function () use ($serviceType, $other): void {
        $serviceType->office_id = $other->getKey();
        $serviceType->save();
    })->toThrow(RuntimeException::class);
});

it('refuses to change the code', function (): void {
    $serviceType = ServiceType::factory()->code('UJI_ASLI')->create();

    expect(function () use ($serviceType): void {
        $serviceType->code = 'UJI_BARU';
        $serviceType->save();
    })->toThrow(RuntimeException::class);
});

it('refuses to change the domain', function (): void {
    // Flipping this after Matters reference the service would silently
    // reclassify work already done.
    $serviceType = ServiceType::factory()->domain(ServiceTypeDomain::NOTARY)->create();

    expect(function () use ($serviceType): void {
        $serviceType->domain = ServiceTypeDomain::PPAT;
        $serviceType->save();
    })->toThrow(RuntimeException::class);
});

it('withholds identity from mass assignment', function (): void {
    $serviceType = ServiceType::factory()->create();

    expect($serviceType->isFillable('office_id'))->toBeFalse()
        ->and($serviceType->isFillable('code'))->toBeFalse()
        ->and($serviceType->isFillable('domain'))->toBeFalse();
});

it('withholds activation from generic mass assignment', function (): void {
    // Deactivating withdraws a service from every future selection, which is a
    // different act from correcting a description. It gets its own mutation
    // boundary when a write surface exists (the D-091 shape).
    $serviceType = ServiceType::factory()->create();

    expect($serviceType->isFillable('is_active'))->toBeFalse();
});

it('allows ordinary master-data content to be corrected', function (): void {
    $serviceType = ServiceType::factory()->create();

    $serviceType->fill([
        'name_id' => 'Layanan Uji Diperbarui',
        'name_en' => 'Updated Test Service',
        'description_id' => 'Keterangan',
        'description_en' => 'Description',
        'sort_order' => 3,
        'default_duration_days' => 7,
    ]);
    $serviceType->save();

    $fresh = $serviceType->fresh();

    expect($fresh->name_id)->toBe('Layanan Uji Diperbarui')
        ->and($fresh->name_en)->toBe('Updated Test Service')
        ->and($fresh->sort_order)->toBe(3)
        ->and($fresh->default_duration_days)->toBe(7);
});

/*
|--------------------------------------------------------------------------
| Retirement
|--------------------------------------------------------------------------
*/

it('retires through is_active rather than deletion', function (): void {
    // The only lifecycle a Service Type has in M4. An inactive entry stays
    // readable so records referencing it keep their classification.
    $serviceType = ServiceType::factory()->inactive()->create();

    $found = ServiceType::query()->find($serviceType->getKey());

    expect($found)->not->toBeNull()
        ->and($found->is_active)->toBeFalse();
});

it('uses no soft delete', function (): void {
    expect(in_array(
        SoftDeletes::class,
        class_uses_recursive(ServiceType::class),
        true,
    ))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No catalogue ships with the product
|--------------------------------------------------------------------------
*/

it('ships no service type rows', function (): void {
    // No validated Notary or PPAT catalogue exists (D-102), so nothing may seed
    // one. A migrated database holds zero.
    expect(ServiceType::query()->count())->toBe(0);
});
