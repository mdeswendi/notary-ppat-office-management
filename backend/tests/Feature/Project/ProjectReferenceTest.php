<?php

use App\Domains\Project\AllocateProjectReference;
use App\Domains\Project\ProjectReference;
use App\Models\Office;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function allocator(): AllocateProjectReference
{
    return app(AllocateProjectReference::class);
}

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('adds a nullable reference column wide enough for the format', function (): void {
    // Nullable by design, not by accident: M3.2 ships no creation path that
    // assigns a reference — that is M3.3 — so NOT NULL would make Project
    // unwritable for a whole milestone. The persistent development table was
    // verified empty first, so this is a design choice rather than a data-forced
    // one.
    expect(Schema::hasColumn('projects', 'project_number'))->toBeTrue();

    $project = Project::factory()->create();

    expect($project->project_number)->toBeNull();
});

it('creates the counter table keyed by office and year', function (): void {
    expect(Schema::hasTable('project_reference_counters'))->toBeTrue();

    foreach (['office_id', 'reference_year', 'last_value'] as $column) {
        expect(Schema::hasColumn('project_reference_counters', $column))->toBeTrue($column);
    }
});

it('refuses a second counter row for the same office and year', function (): void {
    $office = Office::factory()->create();

    allocator()->nextValue($office->getKey(), 2026);

    expect(fn () => DB::table('project_reference_counters')->insert([
        'office_id' => $office->getKey(),
        'reference_year' => 2026,
        'last_value' => 99,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('scopes reference uniqueness to the office, never globally', function (): void {
    // Each Office runs its own annual sequence, so the same reference in two
    // Offices is correct rather than a collision. A global unique index would
    // make the second Office's first project of the year fail for no reason
    // anybody could explain.
    $a = Office::factory()->create();
    $b = Office::factory()->create();

    $first = Project::factory()->for($a)->create();
    $second = Project::factory()->for($b)->create();

    $first->forceFill(['project_number' => 'PRJ-2026-000001'])->save();
    $second->forceFill(['project_number' => 'PRJ-2026-000001'])->save();

    expect($first->fresh()->project_number)->toBe('PRJ-2026-000001')
        ->and($second->fresh()->project_number)->toBe('PRJ-2026-000001');
});

it('refuses the same reference twice within one office', function (): void {
    $office = Office::factory()->create();

    Project::factory()->for($office)->create()
        ->forceFill(['project_number' => 'PRJ-2026-000001'])->save();

    $duplicate = Project::factory()->for($office)->create();

    expect(fn () => $duplicate->forceFill(['project_number' => 'PRJ-2026-000001'])->save())
        ->toThrow(QueryException::class);
});

it('allows many unassigned projects in one office', function (): void {
    // Both engines treat NULLs as distinct in a unique index, which is what lets
    // the column be nullable under a composite unique constraint at all.
    $office = Office::factory()->create();

    Project::factory()->for($office)->count(3)->create();

    expect(Project::where('office_id', $office->getKey())->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

it('formats a reference deterministically with exact padding', function (): void {
    expect(ProjectReference::format(2026, 1))->toBe('PRJ-2026-000001')
        ->and(ProjectReference::format(2026, 42))->toBe('PRJ-2026-000042')
        ->and(ProjectReference::format(2026, 999999))->toBe('PRJ-2026-999999')
        ->and(ProjectReference::format(2027, 1))->toBe('PRJ-2027-000001');
});

it('grows past six digits rather than wrapping or truncating', function (): void {
    // Uniqueness is the one property an identifier may not lose. Seven digits is
    // ugly; a wrapped or truncated reference is wrong.
    expect(ProjectReference::format(2026, 1000000))->toBe('PRJ-2026-1000000')
        ->and(strlen(ProjectReference::format(2026, 1000000)))->toBeLessThanOrEqual(32);
});

it('recognizes its own format without parsing it', function (): void {
    expect(ProjectReference::matchesFormat('PRJ-2026-000001'))->toBeTrue()
        ->and(ProjectReference::matchesFormat('PRJ-2026-1'))->toBeFalse()
        ->and(ProjectReference::matchesFormat('AJB-2026-000001'))->toBeFalse()
        ->and(ProjectReference::matchesFormat('PRJ/2026/000001'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Allocation
|--------------------------------------------------------------------------
*/

it('allocates the first reference of an office year as 000001', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(allocator()->forOffice($office))->toBe('PRJ-2026-000001');
});

it('increments within the same office and year', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $office = Office::factory()->create();

    expect(allocator()->forOffice($office))->toBe('PRJ-2026-000001')
        ->and(allocator()->forOffice($office))->toBe('PRJ-2026-000002')
        ->and(allocator()->forOffice($office))->toBe('PRJ-2026-000003');

    expect(DB::table('project_reference_counters')
        ->where('office_id', $office->getKey())->where('reference_year', 2026)
        ->value('last_value'))->toBe(3);
});

it('gives each office an independent sequence', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');

    $a = Office::factory()->create();
    $b = Office::factory()->create();

    allocator()->forOffice($a);
    allocator()->forOffice($a);

    // B starts at 1, untouched by A having reached 2.
    expect(allocator()->forOffice($b))->toBe('PRJ-2026-000001')
        ->and(allocator()->forOffice($a))->toBe('PRJ-2026-000003')
        ->and(allocator()->forOffice($b))->toBe('PRJ-2026-000002');
});

it('restarts each office sequence at the year boundary', function (): void {
    // Frozen time, so this proves rollover rather than depending on when the
    // suite happens to run.
    $office = Office::factory()->create();
    $other = Office::factory()->create();

    Date::setTestNow('2026-12-31 23:59:59');
    allocator()->forOffice($office);
    allocator()->forOffice($office);
    expect(allocator()->forOffice($office))->toBe('PRJ-2026-000003');

    Date::setTestNow('2027-01-01 00:00:01');
    expect(allocator()->forOffice($office))->toBe('PRJ-2027-000001')
        ->and(allocator()->forOffice($office))->toBe('PRJ-2027-000002');

    // The 2026 counter is untouched by the new year, and the other Office's 2027
    // sequence is its own.
    expect(DB::table('project_reference_counters')
        ->where('office_id', $office->getKey())->where('reference_year', 2026)
        ->value('last_value'))->toBe(3)
        ->and(allocator()->forOffice($other))->toBe('PRJ-2027-000001');
});

it('takes the year from the application clock, not from any input', function (): void {
    Date::setTestNow('2026-03-01 12:00:00');
    $office = Office::factory()->create();

    expect(allocator()->forOffice($office))->toStartWith('PRJ-2026-');

    Date::setTestNow('2030-03-01 12:00:00');

    expect(allocator()->forOffice($office))->toStartWith('PRJ-2030-');
});

/*
|--------------------------------------------------------------------------
| Gaps and reuse — D-094
|--------------------------------------------------------------------------
*/

it('does not reuse a reference after the project is archived', function (): void {
    // Once a reference belongs to a persisted record it is spent. Soft delete
    // does not release it, and no reuse feature exists.
    Date::setTestNow('2026-05-17 09:00:00');
    $office = Office::factory()->create();

    $project = Project::factory()->for($office)->create();
    $project->forceFill(['project_number' => allocator()->forOffice($office)])->save();
    $project->delete();

    expect(allocator()->forOffice($office))->toBe('PRJ-2026-000002');
});

it('leaves a gap when an allocation is not used', function (): void {
    // Documented behaviour, not a defect: the alternatives are reusing numbers
    // or serializing every create behind one lock. The number is not a count.
    Date::setTestNow('2026-05-17 09:00:00');
    $office = Office::factory()->create();

    allocator()->forOffice($office);          // 000001, discarded
    $used = allocator()->forOffice($office);  // 000002

    $project = Project::factory()->for($office)->create();
    $project->forceFill(['project_number' => $used])->save();

    expect($used)->toBe('PRJ-2026-000002')
        ->and(Project::whereNotNull('project_number')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Immutability and lifecycle
|--------------------------------------------------------------------------
*/

it('refuses to rewrite an allocated reference', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    $office = Office::factory()->create();

    $project = Project::factory()->for($office)->create();
    $project->forceFill(['project_number' => allocator()->forOffice($office)])->save();

    $project->project_number = 'PRJ-2026-000999';

    expect(fn () => $project->save())
        ->toThrow(RuntimeException::class, 'immutable once allocated');
});

it('permits the initial assignment from null', function (): void {
    // The guard must not block the operation the column exists for. M3.3 stamps
    // a new Project exactly this way.
    Date::setTestNow('2026-05-17 09:00:00');
    $office = Office::factory()->create();
    $project = Project::factory()->for($office)->create();

    $project->project_number = allocator()->forOffice($office);
    $project->save();

    expect($project->fresh()->project_number)->toBe('PRJ-2026-000001');
});

it('keeps the reference out of mass assignment', function (): void {
    expect((new Project)->isFillable('project_number'))->toBeFalse();
});

it('keeps the reference through soft delete and restore', function (): void {
    Date::setTestNow('2026-05-17 09:00:00');
    $office = Office::factory()->create();

    $project = Project::factory()->for($office)->create();
    $project->forceFill(['project_number' => allocator()->forOffice($office)])->save();

    $project->delete();
    expect(Project::withTrashed()->find($project->id)->project_number)->toBe('PRJ-2026-000001');

    Project::withTrashed()->find($project->id)->restore();
    expect(Project::find($project->id)->project_number)->toBe('PRJ-2026-000001');
});

it('keeps the office immutable so the namespace stays stable', function (): void {
    // D-089 is what makes (office_id, project_number) a stable key: if a Project
    // could move Office, its reference could collide with one already issued
    // there.
    $project = Project::factory()->create();
    $project->office_id = Office::factory()->create()->getKey();

    expect(fn () => $project->save())->toThrow(RuntimeException::class, 'immutable during M3');
});

/*
|--------------------------------------------------------------------------
| Prohibited allocation strategies — D-094
|--------------------------------------------------------------------------
*/

it('allocates without MAX, COUNT, or a read-then-write', function (): void {
    $source = (string) file_get_contents(app_path('Domains/Project/AllocateProjectReference.php'));
    $code = preg_replace('#/\*.*?\*/#s', '', $source);
    $code = preg_replace('#//.*$#m', '', (string) $code);

    foreach (['max(', 'MAX(', 'count(', 'COUNT(', 'latest(', 'orderByDesc('] as $forbidden) {
        expect($code)->not->toContain($forbidden, $forbidden);
    }

    // The whole strategy is one statement: the database increments and returns.
    expect($code)->toContain('ON CONFLICT')
        ->and($code)->toContain('RETURNING last_value');
});

it('introduces no legal numbering vocabulary', function (): void {
    foreach ([
        app_path('Domains/Project/AllocateProjectReference.php'),
        app_path('Domains/Project/ProjectReference.php'),
    ] as $path) {
        $code = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
        $code = strtolower((string) preg_replace('#//.*$#m', '', (string) $code));

        foreach (['deed', 'repertorium', 'warkah', 'akta', 'minuta', 'register'] as $legal) {
            expect($code)->not->toContain($legal, $legal);
        }
    }
});

it('reads the year from the clock rather than parsing a reference', function (): void {
    $code = (string) file_get_contents(app_path('Domains/Project/AllocateProjectReference.php'));

    // No substring/regex extraction of a year from an existing reference.
    foreach (['substr(', 'preg_match(', 'explode('] as $parsing) {
        expect($code)->not->toContain($parsing, $parsing);
    }
});

it('exposes no Project reference HTTP surface', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'project'));

    expect($routes)->toBeEmpty();
});
