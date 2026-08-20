<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Matter\AllocateMatterReference;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterReference;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function matterAllocator(): AllocateMatterReference
{
    return app(AllocateMatterReference::class);
}

/**
 * A Matter in the given Office, optionally carrying a reference.
 */
function referencedMatter(Office $office, MatterDomain $domain, ?string $number = null): Matter
{
    $project = Project::factory()->for($office)->create();

    return Matter::factory()->for($project)->domain($domain)->create([
        'matter_number' => $number,
    ]);
}

afterEach(function (): void {
    Date::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('requires a matter number on every matter', function (): void {
    // **Narrowed at M4.4, not deleted.** M4.3 asserted the column was nullable,
    // which was true while no creation path allocated. M4.4 ships creation, every
    // path allocates, and the column carries the guarantee it always wanted
    // (D-109). What stays true is the column's shape and that the allocator is
    // the only thing that fills it.
    expect(Schema::hasColumn('matters', 'matter_number'))->toBeTrue();

    expect(fn () => Matter::factory()->create(['matter_number' => null]))
        ->toThrow(QueryException::class);
});

it('creates the counter table keyed by office, year, and domain', function (): void {
    expect(Schema::hasTable('matter_reference_counters'))->toBeTrue();

    $columns = Schema::getColumnListing('matter_reference_counters');
    sort($columns);

    expect($columns)->toBe([
        'created_at', 'domain', 'last_value', 'office_id', 'reference_year', 'updated_at',
    ]);
});

it('refuses a second counter row for the same office, year, and domain', function (): void {
    $office = Office::factory()->create();

    $row = [
        'office_id' => $office->getKey(),
        'reference_year' => 2026,
        'domain' => MatterDomain::NOTARY->value,
        'last_value' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('matter_reference_counters')->insert($row);

    expect(fn () => DB::table('matter_reference_counters')->insert($row))
        ->toThrow(QueryException::class);
});

it('keeps notary and ppat counters independent within one office year', function (): void {
    // The third namespace dimension: without it, `N-` and `P-` would compete for
    // one value.
    $office = Office::factory()->create();

    foreach (MatterDomain::cases() as $domain) {
        DB::table('matter_reference_counters')->insert([
            'office_id' => $office->getKey(),
            'reference_year' => 2026,
            'domain' => $domain->value,
            'last_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('matter_reference_counters')->count())->toBe(2);
});

it('scopes reference uniqueness to the office, never globally', function (): void {
    $first = referencedMatter(Office::factory()->create(), MatterDomain::NOTARY, 'N-2026-000001');
    $second = referencedMatter(Office::factory()->create(), MatterDomain::NOTARY, 'N-2026-000001');

    expect($first->office_id)->not->toBe($second->office_id)
        ->and($second->exists)->toBeTrue();
});

it('refuses the same reference twice within one office', function (): void {
    $office = Office::factory()->create();
    referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001');

    expect(fn () => referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001'))
        ->toThrow(QueryException::class);
});

it('lets one office hold both domain references for the same sequence', function (): void {
    // `N-2026-000001` and `P-2026-000001` are different strings, so the
    // `(office_id, matter_number)` key admits both — which is why `domain` is
    // deliberately absent from that key.
    $office = Office::factory()->create();

    referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001');
    $ppat = referencedMatter($office, MatterDomain::PPAT, 'P-2026-000001');

    expect($ppat->exists)->toBeTrue()
        ->and(Matter::query()->whereNotNull('matter_number')->count())->toBe(2);
});

it('keeps the current stage deferred to M4.7', function (): void {
    expect(Schema::hasColumn('matters', 'current_stage_id'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

it('formats a reference deterministically with exact padding', function (): void {
    expect(MatterReference::format(MatterDomain::NOTARY, 2026, 1))->toBe('N-2026-000001')
        ->and(MatterReference::format(MatterDomain::NOTARY, 2026, 42))->toBe('N-2026-000042')
        ->and(MatterReference::format(MatterDomain::NOTARY, 2026, 999999))->toBe('N-2026-999999')
        ->and(MatterReference::format(MatterDomain::PPAT, 2026, 1))->toBe('P-2026-000001')
        ->and(MatterReference::format(MatterDomain::PPAT, 2027, 128))->toBe('P-2027-000128');
});

it('maps each domain to its own prefix', function (): void {
    expect(MatterReference::prefix(MatterDomain::NOTARY))->toBe('N')
        ->and(MatterReference::prefix(MatterDomain::PPAT))->toBe('P');
});

it('grows past six digits rather than wrapping or truncating', function (): void {
    // Uniqueness is the one property an identifier may not lose. Seven digits is
    // ugly; a wrapped or truncated reference is wrong.
    expect(MatterReference::format(MatterDomain::NOTARY, 2026, 1000000))->toBe('N-2026-1000000')
        ->and(strlen(MatterReference::format(MatterDomain::NOTARY, 2026, 1000000)))->toBeLessThanOrEqual(32);
});

it('recognizes its own format without parsing it', function (): void {
    expect(MatterReference::matchesFormat('N-2026-000001'))->toBeTrue()
        ->and(MatterReference::matchesFormat('P-2026-000001'))->toBeTrue()
        ->and(MatterReference::matchesFormat('N-2026-1'))->toBeFalse()
        ->and(MatterReference::matchesFormat('PRJ-2026-000001'))->toBeFalse()
        ->and(MatterReference::matchesFormat('AJB-2026-000001'))->toBeFalse()
        ->and(MatterReference::matchesFormat('N/2026/000001'))->toBeFalse();
});

it('can check a reference against one specific domain', function (): void {
    expect(MatterReference::matchesFormat('N-2026-000001', MatterDomain::NOTARY))->toBeTrue()
        ->and(MatterReference::matchesFormat('N-2026-000001', MatterDomain::PPAT))->toBeFalse()
        ->and(MatterReference::matchesFormat('P-2026-000001', MatterDomain::PPAT))->toBeTrue()
        ->and(MatterReference::matchesFormat('P-2026-000001', MatterDomain::NOTARY))->toBeFalse();
});

it('exposes no parser', function (): void {
    // Nothing may read the year, sequence, or domain back out of a formatted
    // reference: that would make displayed text an input to logic.
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(MatterReference::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($methods);

    expect($methods)->toBe(['format', 'matchesFormat', 'prefix']);
});

/*
|--------------------------------------------------------------------------
| Allocation
|--------------------------------------------------------------------------
*/

it('allocates the first reference of an office year domain as 000001', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000001');
});

it('increments within the same office, year, and domain', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000001')
        ->and(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000002')
        ->and(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000003');
});

it('gives each domain an independent sequence', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    matterAllocator()->forOffice($office, MatterDomain::NOTARY);
    matterAllocator()->forOffice($office, MatterDomain::NOTARY);

    // PPAT starts at 1 despite two Notary allocations in the same Office-year.
    expect(matterAllocator()->forOffice($office, MatterDomain::PPAT))->toBe('P-2026-000001')
        ->and(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000003');
});

it('gives each office an independent sequence', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $first = Office::factory()->create();
    $second = Office::factory()->create();

    matterAllocator()->forOffice($first, MatterDomain::NOTARY);
    matterAllocator()->forOffice($first, MatterDomain::NOTARY);

    expect(matterAllocator()->forOffice($second, MatterDomain::NOTARY))->toBe('N-2026-000001');
});

it('restarts each sequence at the year boundary', function (): void {
    $office = Office::factory()->create();

    Date::setTestNow('2026-12-31 23:59:59');
    expect(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000001');

    Date::setTestNow('2027-01-01 00:00:01');
    expect(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2027-000001');
});

it('takes the year from the application clock, not from any input', function (): void {
    Date::setTestNow('2029-03-04 12:00:00');

    $office = Office::factory()->create();

    expect(matterAllocator()->forOffice($office, MatterDomain::PPAT))->toBe('P-2029-000001');
});

it('records the allocation on the counter row', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    matterAllocator()->forOffice($office, MatterDomain::NOTARY);
    matterAllocator()->forOffice($office, MatterDomain::NOTARY);

    $counter = DB::table('matter_reference_counters')
        ->where('office_id', $office->getKey())
        ->where('reference_year', 2026)
        ->where('domain', MatterDomain::NOTARY->value)
        ->first();

    expect((int) $counter->last_value)->toBe(2);
});

it('exposes the raw sequence value without re-parsing the string', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(matterAllocator()->nextValue($office->getKey(), MatterDomain::NOTARY, 2026))->toBe(1)
        ->and(matterAllocator()->nextValue($office->getKey(), MatterDomain::NOTARY, 2026))->toBe(2);
});

it('leaves a permanent gap when a committed allocation is not used', function (): void {
    // The honest statement: an allocation that commits and is then not used skips
    // its number forever. Gaps carry no meaning — the sequence is not a record
    // count and has no legal weight.
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    $wasted = matterAllocator()->forOffice($office, MatterDomain::NOTARY);
    $used = matterAllocator()->forOffice($office, MatterDomain::NOTARY);

    expect($wasted)->toBe('N-2026-000001')
        ->and($used)->toBe('N-2026-000002')
        ->and(Matter::query()->whereNotNull('matter_number')->count())->toBe(0);
});

it('returns the number to the sequence when the allocating transaction rolls back', function (): void {
    // The other half of the gap story, and the reason the allocator opens no
    // transaction of its own: it participates in the caller's, so a rollback
    // takes the counter increment with it.
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    try {
        DB::transaction(function () use ($office): void {
            matterAllocator()->forOffice($office, MatterDomain::NOTARY);

            throw new RuntimeException('deliberate rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000001');
});

it('derives no reference from a row count', function (): void {
    // **Narrowed at M4.4.** The factory now allocates, so "rows exist and the
    // sequence is untouched" is no longer constructible. The point survives in a
    // form that is: the counter, not the table, decides the next value — deleting
    // rows does not rewind it, which `COUNT(*) + 1` would.
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();

    Matter::factory()->for($project)->count(3)->create();

    Matter::query()->delete();

    expect(Matter::query()->count())->toBe(0)
        ->and(matterAllocator()->forOffice($office, MatterDomain::NOTARY))->toBe('N-2026-000004');
});

/*
|--------------------------------------------------------------------------
| Prefix and domain agreement
|--------------------------------------------------------------------------
*/

it('always allocates a reference whose prefix matches the domain', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(MatterReference::matchesFormat(
        matterAllocator()->forOffice($office, MatterDomain::NOTARY),
        MatterDomain::NOTARY,
    ))->toBeTrue()
        ->and(MatterReference::matchesFormat(
            matterAllocator()->forOffice($office, MatterDomain::PPAT),
            MatterDomain::PPAT,
        ))->toBeTrue();
});

it('rejects a reference whose prefix contradicts the matter domain', function (): void {
    // The database's belt-and-braces invariant, independent of PHP. Full format
    // correctness stays in MatterReference; this only refuses a wrong prefix.
    $office = Office::factory()->create();

    expect(fn () => referencedMatter($office, MatterDomain::NOTARY, 'P-2026-000001'))
        ->toThrow(QueryException::class);

    expect(fn () => referencedMatter($office, MatterDomain::PPAT, 'N-2026-000001'))
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are PostgreSQL-only here; MatterReference refuses it on SQLite.',
);

it('refuses a matter with no reference at all', function (): void {
    // The other half of the M4.4 tightening: what M4.3 accepted, the database now
    // refuses, because every creation path allocates (D-109).
    $office = Office::factory()->create();

    expect(fn () => referencedMatter($office, MatterDomain::NOTARY, null))
        ->toThrow(QueryException::class);
});

it('refuses a negative counter value or year on postgresql', function (): void {
    // `unsignedSmallInteger` and `unsignedInteger` are MySQL concepts; PostgreSQL
    // maps both to signed columns, so the CHECKs are what actually enforce this.
    $office = Office::factory()->create();

    expect(fn () => DB::table('matter_reference_counters')->insert([
        'office_id' => $office->getKey(),
        'reference_year' => 2026,
        'domain' => MatterDomain::NOTARY->value,
        'last_value' => -1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are PostgreSQL-only here.',
);

/*
|--------------------------------------------------------------------------
| Immutability
|--------------------------------------------------------------------------
*/

it('refuses to rewrite an allocated reference', function (): void {
    $office = Office::factory()->create();
    $matter = referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001');

    expect(function () use ($matter): void {
        $matter->matter_number = 'N-2026-000002';
        $matter->save();
    })->toThrow(RuntimeException::class);
});

// The `null -> reference` case the M4.3 guard also refused is **unreachable
// since M4.4**: the column is NOT NULL, so no Matter can exist without one to be
// given later. The guard still covers the branch and the branch can no longer be
// entered — the assertion was removed rather than left as dead reassurance,
// exactly as M3.3 did to the Project guard's equivalent case.

it('refuses to clear an allocated reference', function (): void {
    $office = Office::factory()->create();
    $matter = referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001');

    expect(function () use ($matter): void {
        $matter->matter_number = null;
        $matter->save();
    })->toThrow(RuntimeException::class);
});

it('does not block the allocation itself', function (): void {
    // The guard fires on `updating` only, so stamping a new model is an insert.
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();
    $reference = matterAllocator()->forOffice($office, MatterDomain::NOTARY);

    $matter = referencedMatter($office, MatterDomain::NOTARY, $reference);

    expect($matter->fresh()->matter_number)->toBe('N-2026-000001');
});

it('keeps the reference out of mass assignment', function (): void {
    expect((new Matter)->isFillable('matter_number'))->toBeFalse();
});

it('leaves ordinary content editable without touching the reference', function (): void {
    $office = Office::factory()->create();
    $matter = referencedMatter($office, MatterDomain::NOTARY, 'N-2026-000001');

    $matter->fill(['title' => 'Pekerjaan Uji Diperbarui', 'notes' => 'Catatan']);
    $matter->save();

    expect($matter->fresh()->matter_number)->toBe('N-2026-000001')
        ->and($matter->fresh()->title)->toBe('Pekerjaan Uji Diperbarui');
});

/*
|--------------------------------------------------------------------------
| Architecture guards
|--------------------------------------------------------------------------
*/

/**
 * Executable code with comments removed — the forbidden patterns are named in the
 * allocator's own docblock, which explains why it does not use them.
 */
function matterReferenceCode(string $relativePath): string
{
    $stripped = '';

    foreach (token_get_all(file_get_contents(app_path($relativePath))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

function matterAllocatorCode(): string
{
    return matterReferenceCode('Domains/Matter/AllocateMatterReference.php');
}

it('allocates without MAX, COUNT, or a read-then-write', function (): void {
    $source = matterAllocatorCode();

    expect($source)->not->toContain('MAX(')
        ->and($source)->not->toContain('max(')
        ->and($source)->not->toContain('COUNT(')
        ->and($source)->not->toContain('count(')
        ->and($source)->not->toContain('latest(')
        ->and($source)->not->toContain('->increment(')
        ->and($source)->not->toContain('lockForUpdate');

    // The one statement that does the work is the atomic upsert.
    expect($source)->toContain('ON CONFLICT')
        ->and($source)->toContain('RETURNING last_value');
});

it('never reads an existing matter reference', function (): void {
    $source = matterAllocatorCode();

    expect($source)->not->toContain('matter_number')
        ->and($source)->not->toContain('matters');
});

it('opens no transaction of its own', function (): void {
    // It participates in the caller's transaction, so M4.4 can allocate and
    // insert atomically together.
    $source = matterAllocatorCode();

    expect($source)->not->toContain('transaction(')
        ->and($source)->not->toContain('beginTransaction')
        ->and($source)->not->toContain('->commit(');
});

it('introduces no legal numbering vocabulary', function (): void {
    // Comments are stripped first: both classes discuss at length what they are
    // *not*, and a raw scan would fail on its own disclaimers. The M3 equivalent
    // strips for the same reason.
    foreach ([
        'Domains/Matter/MatterReference.php',
        'Domains/Matter/AllocateMatterReference.php',
    ] as $path) {
        $code = strtolower(matterReferenceCode($path));

        foreach (['deed', 'repertorium', 'warkah', 'akta', 'minuta', 'register'] as $legal) {
            expect($code)->not->toContain($legal, "{$path} :: {$legal}");
        }
    }
});

it('builds no generic numbering engine', function (): void {
    // D-103 locks a dedicated allocator; the configurable numbering master data
    // sketched in the ERD belongs to `master.numbering.*` and another milestone.
    foreach (['numbering_sequences', 'number_sequences', 'sequences', 'legal_number_sequences'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

it('does not share or generalize the project counter', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    matterAllocator()->forOffice($office, MatterDomain::NOTARY);

    // The Project counter is untouched by a Matter allocation.
    expect(DB::table('project_reference_counters')->count())->toBe(0)
        ->and(DB::table('matter_reference_counters')->count())->toBe(1);
});

it('exposes no route that writes a reference', function (): void {
    // **Narrowed at M4.4, not deleted.** This asserted there was no Matter route
    // at all, which was true while M4.3 shipped allocation without a product
    // surface. M4.4 ships that surface, so the assertion becomes the part that
    // always mattered: no endpoint accepts or mutates a reference. It is
    // allocated server-side and immutable, so there is nothing for a caller to
    // send — the create and update Requests both refuse the field outright.
    $forbidden = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'matter-number')
            || str_contains($uri, 'matter_number')
            || str_contains($uri, 'reference'));

    expect($forbidden)->toBeEmpty();
});

it('adds no matter allocation permission', function (): void {
    // Reference allocation is system-controlled infrastructure, not a user
    // capability. The registry's existing `master.numbering.*` and
    // `*.deeds.number` codes belong to other modules and are untouched.
    $codes = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'matters.')
            && (str_contains($code, 'number')
                || str_contains($code, 'allocate')
                || str_contains($code, 'reference')),
    ));

    // The total is pinned once, in `PermissionRegistryTest`. This file asserted
    // it too until M4.5, which legitimately moved it to 177 and made a second
    // copy of the number a thing to update in two places (D-105). What this file
    // owns is the claim above: allocation invented no capability.
    expect($codes)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Migration reversibility
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // **Three steps since M4.5**: this migration, M4.4's tightening of the
    // column, and M4.5's participation table, which holds a foreign key into
    // `matters` and so must come off before anything below it.
    $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

    expect(Schema::hasTable('matter_reference_counters'))->toBeFalse()
        ->and(Schema::hasColumn('matters', 'matter_number'))->toBeFalse()
        ->and(Schema::hasTable('matter_parties'))->toBeFalse()
        // M4.2 survives untouched.
        ->and(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasTable('service_types'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('matter_reference_counters'))->toBeTrue()
        ->and(Schema::hasColumn('matters', 'matter_number'))->toBeTrue();
});
