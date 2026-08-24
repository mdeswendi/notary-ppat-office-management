<?php

use App\Domains\Document\AllocateDocumentReference;
use App\Domains\Document\DocumentReference;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The format
|--------------------------------------------------------------------------
*/

it('formats an internal document reference as DOC-YYYY-NNNNNN', function (): void {
    expect(DocumentReference::format(2026, 1))->toBe('DOC-2026-000001')
        ->and(DocumentReference::format(2026, 999_999))->toBe('DOC-2026-999999');
});

it('grows past six digits rather than wrapping or truncating', function (): void {
    // Six digits are a minimum, not a maximum. Wrapping to `000000` or
    // truncating would silently break uniqueness, the one property an identifier
    // may not lose. `varchar(32)` is sized for it.
    expect(DocumentReference::format(2026, 1_000_000))->toBe('DOC-2026-1000000')
        ->and(strlen(DocumentReference::format(2026, 1_000_000)))->toBeLessThanOrEqual(32);
});

it('recognizes its own format without parsing it back', function (): void {
    expect(DocumentReference::matchesFormat('DOC-2026-000001'))->toBeTrue()
        ->and(DocumentReference::matchesFormat('DOC-2026-1000000'))->toBeTrue()
        ->and(DocumentReference::matchesFormat('DOC-2026-1'))->toBeFalse()
        ->and(DocumentReference::matchesFormat('PRJ-2026-000001'))->toBeFalse()
        ->and(DocumentReference::matchesFormat('N-2026-000001'))->toBeFalse();
});

it('exposes no way to read the year or sequence back out of a reference', function (): void {
    // A formatter, not a parser (D-108's rule, one domain across). Reading values
    // back out would make displayed text an input to logic, and the moment it
    // does, changing the display format becomes a breaking change.
    $methods = array_map(
        fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(DocumentReference::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    foreach (['parse', 'extract', 'yearof', 'sequenceof', 'decode', 'split'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }

    expect($methods)->toBe(['format', 'matchesformat']);
});

/*
|--------------------------------------------------------------------------
| Allocation
|--------------------------------------------------------------------------
*/

it('allocates consecutive values within one office and year', function (): void {
    $office = Office::factory()->create();
    $allocator = app(AllocateDocumentReference::class);

    expect($allocator->nextValue($office->getKey(), 2026))->toBe(1)
        ->and($allocator->nextValue($office->getKey(), 2026))->toBe(2)
        ->and($allocator->nextValue($office->getKey(), 2026))->toBe(3);
});

it('gives each office its own sequence', function (): void {
    $first = Office::factory()->create();
    $second = Office::factory()->create();
    $allocator = app(AllocateDocumentReference::class);

    $allocator->nextValue($first->getKey(), 2026);
    $allocator->nextValue($first->getKey(), 2026);

    expect($allocator->nextValue($second->getKey(), 2026))->toBe(1);
});

it('restarts the sequence in a new calendar year', function (): void {
    $office = Office::factory()->create();
    $allocator = app(AllocateDocumentReference::class);

    $allocator->nextValue($office->getKey(), 2026);
    $allocator->nextValue($office->getKey(), 2026);

    expect($allocator->nextValue($office->getKey(), 2027))->toBe(1)
        ->and($allocator->nextValue($office->getKey(), 2026))->toBe(3);
});

it('takes the year from the application clock rather than from a caller', function (): void {
    // Never from a request body, a browser, a document's own date, or a value
    // parsed back out of an existing reference.
    Date::setTestNow('2031-03-04 10:00:00');

    $office = Office::factory()->create();

    expect(app(AllocateDocumentReference::class)->forOffice($office))->toBe('DOC-2031-000001');

    Date::setTestNow();
});

it('accepts an Office model or its key', function (): void {
    $office = Office::factory()->create();
    $allocator = app(AllocateDocumentReference::class);

    expect($allocator->forOffice($office, 2026))->toBe('DOC-2026-000001')
        ->and($allocator->forOffice($office->getKey(), 2026))->toBe('DOC-2026-000002');
});

/*
|--------------------------------------------------------------------------
| How the number is produced
|--------------------------------------------------------------------------
*/

it('allocates in one atomic statement rather than reading then writing', function (): void {
    // `MAX+1`, `COUNT+1`, `latest()+1` and read-then-write are all unsafe under
    // concurrency (CLAUDE.md section 38), and a transaction alone would not fix a
    // SELECT-then-UPDATE: under READ COMMITTED two transactions can both read
    // before either writes.
    $source = file_get_contents(app_path('Domains/Document/AllocateDocumentReference.php'));

    $executable = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    foreach (['max(', 'MAX(', 'count(', 'latest(', '->increment('] as $forbidden) {
        expect($executable)->not->toContain($forbidden);
    }

    expect($executable)->toContain('ON CONFLICT')
        ->and($executable)->toContain('RETURNING last_value');
});

it('opens no transaction of its own', function (): void {
    // It participates in the caller's, so an allocation and the insert that uses
    // it can share one. A `beginTransaction` here would commit the increment
    // independently and turn every rolled-back create into a permanently skipped
    // number.
    $office = Office::factory()->create();

    DB::beginTransaction();
    app(AllocateDocumentReference::class)->nextValue($office->getKey(), 2026);
    DB::rollBack();

    expect(DB::table('document_reference_counters')->count())->toBe(0);
});

it('keeps the counter out of the documents table', function (): void {
    // Its own table, following M3.2 and M4.3. ERD section 27 sketches a
    // configurable numbering engine with prefix patterns and monthly resets; it
    // is deliberately not used, exactly as D-108 declined it.
    $office = Office::factory()->create();

    app(AllocateDocumentReference::class)->nextValue($office->getKey(), 2026);

    $row = DB::table('document_reference_counters')->first();

    expect($row->office_id)->toBe($office->getKey())
        ->and((int) $row->reference_year)->toBe(2026)
        ->and((int) $row->last_value)->toBe(1)
        ->and(Schema::hasTable('legal_number_sequences'))->toBeFalse()
        ->and(Schema::hasTable('deed_sequences'))->toBeFalse()
        ->and(Schema::hasTable('numbering_rules'))->toBeFalse();
});

it('takes the office counter with the office', function (): void {
    // The counter row is allocator infrastructure rather than work, so it
    // cascades — while `documents` separately restricts Office deletion, which is
    // what actually protects the records.
    $office = Office::factory()->create();

    app(AllocateDocumentReference::class)->nextValue($office->getKey(), 2026);

    DB::table('offices')->where('id', $office->getKey())->delete();

    expect(DB::table('document_reference_counters')->count())->toBe(0);
});
