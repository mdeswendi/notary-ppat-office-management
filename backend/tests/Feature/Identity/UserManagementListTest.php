<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @return array{0: Office, 1: Office}
 */
function listOffices(): array
{
    $organization = Organization::factory()->create();

    return [
        Office::factory()->for($organization)->create(['name' => 'Office A']),
        Office::factory()->for($organization)->create(['name' => 'Office B']),
    ];
}

/*
|--------------------------------------------------------------------------
| Ordering and pagination
|--------------------------------------------------------------------------
*/

it('orders users by name and pages deterministically', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create(['name' => 'Zulkifli Actor']);
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    foreach (['Citra', 'Ahmad', 'Budi', 'Dewi'] as $name) {
        User::factory()->for($officeA)->create(['name' => $name]);
    }

    $names = collect($this->actingAs($actor)->getJson('/api/v1/users')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Ahmad', 'Budi', 'Citra', 'Dewi', 'Zulkifli Actor']);
});

it('breaks ties on a stable key so paging cannot repeat or skip a row', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create(['name' => 'Actor']);
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    // Five people who share a name: without a tiebreaker the page boundary
    // would be arbitrary.
    User::factory()->count(5)->for($officeA)->create(['name' => 'Same Name']);

    $first = collect($this->actingAs($actor)->getJson('/api/v1/users?per_page=3&page=1')->json('data'))->pluck('id');
    $second = collect($this->actingAs($actor)->getJson('/api/v1/users?per_page=3&page=2')->json('data'))->pluck('id');

    expect($first)->toHaveCount(3)
        ->and($second)->toHaveCount(3)
        ->and($first->intersect($second))->toBeEmpty()
        ->and($first->merge($second)->unique())->toHaveCount(6);
});

it('reports pagination metadata', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->count(9)->for($officeA)->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/users?per_page=4')->assertOk();

    expect($response->json('meta.total'))->toBe(10)
        ->and($response->json('meta.per_page'))->toBe(4)
        ->and($response->json('meta.current_page'))->toBe(1)
        ->and($response->json('meta.last_page'))->toBe(3)
        ->and($response->json('data'))->toHaveCount(4);
});

it('defaults to twenty per page and caps the maximum', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->count(25)->for($officeA)->create();

    expect($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.per_page'))->toBe(20)
        ->and($this->actingAs($actor)->getJson('/api/v1/users?per_page=5000')->json('meta.per_page'))->toBe(100)
        ->and($this->actingAs($actor)->getJson('/api/v1/users?per_page=0')->json('meta.per_page'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Search and filters
|--------------------------------------------------------------------------
*/

it('searches by name, case-insensitively', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create(['name' => 'Actor']);
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeA)->create(['name' => 'Siti Rahayu']);
    User::factory()->for($officeA)->create(['name' => 'Budi Santoso']);

    $names = collect($this->actingAs($actor)->getJson('/api/v1/users?search=siti')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Siti Rahayu']);
});

it('searches by email', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeA)->create(['email' => 'siti.rahayu@example.test']);
    User::factory()->for($officeA)->create(['email' => 'budi@example.test']);

    $emails = collect($this->actingAs($actor)->getJson('/api/v1/users?search=RAHAYU')->json('data'))->pluck('email')->all();

    expect($emails)->toBe(['siti.rahayu@example.test']);
});

it('keeps search inside the visible set', function (): void {
    // The search is grouped, so it narrows what the scope already permits
    // rather than replacing the constraint.
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create(['name' => 'Actor']);
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    User::factory()->for($officeB)->create(['name' => 'Siti Rahayu']);

    expect($this->actingAs($actor)->getJson('/api/v1/users?search=siti')->json('data'))->toBe([]);
});

it('filters by office inside the authorized scope', function (): void {
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    User::factory()->for($officeA)->create();
    User::factory()->for($officeB)->create();

    expect($this->actingAs($actor)->getJson("/api/v1/users?office_id={$officeB->getKey()}")->json('meta.total'))->toBe(1)
        ->and($this->actingAs($actor)->getJson("/api/v1/users?office_id={$officeA->getKey()}")->json('meta.total'))->toBe(2);
});

it('cannot widen an office-scoped caller through the office filter', function (): void {
    // The filter intersects with the scope constraint; it cannot replace it.
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    User::factory()->for($officeB)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/users?office_id={$officeB->getKey()}")->assertOk();

    expect($response->json('data'))->toBe([])
        // The count leaks nothing either — it is derived from the same query.
        ->and($response->json('meta.total'))->toBe(0);
});

it('filters by activation state', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    $disabled = User::factory()->for($officeA)->create();
    $disabled->is_active = false;
    $disabled->save();

    expect($this->actingAs($actor)->getJson('/api/v1/users?is_active=0')->json('meta.total'))->toBe(1)
        ->and($this->actingAs($actor)->getJson('/api/v1/users?is_active=1')->json('meta.total'))->toBe(1)
        ->and($this->actingAs($actor)->getJson('/api/v1/users')->json('meta.total'))->toBe(2);
});

it('applies the office predicate in SQL rather than after fetching', function (): void {
    // A guard against "select everything, filter in PHP", which would leak
    // through the count and scale badly.
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::OFFICE);

    User::factory()->count(3)->for($officeB)->create();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $this->actingAs($actor)->getJson('/api/v1/users')->assertOk();

    $selects = array_values(array_filter(
        $statements,
        fn (array $s): bool => str_contains(strtolower($s['sql']), 'from "users"'),
    ));

    expect($selects)->not->toBeEmpty();

    foreach ($selects as $statement) {
        expect(strtolower($statement['sql']))->toContain('office_id')
            ->and($statement['bindings'])->toContain($officeA->getKey());
    }
});

/*
|--------------------------------------------------------------------------
| Office options
|--------------------------------------------------------------------------
*/

it('returns every active office to an ALL-scoped caller', function (): void {
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::ALL);

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/users/options')->assertOk()->json('data.offices'))
        ->pluck('id')->all();

    expect($ids)->toContain($officeA->getKey(), $officeB->getKey())
        ->and($ids)->toHaveCount(2);
});

it('returns only the caller\'s office to an OFFICE-scoped caller', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::OFFICE);

    $offices = $this->actingAs($actor)->getJson('/api/v1/users/options')->assertOk()->json('data.offices');

    expect($offices)->toHaveCount(1)
        ->and($offices[0]['id'])->toBe($officeA->getKey())
        ->and($offices[0])->toHaveKeys(['id', 'code', 'name']);
});

it('omits inactive offices', function (): void {
    [$officeA, $officeB] = listOffices();

    $officeB->is_active = false;
    $officeB->save();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::ALL);

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/users/options')->json('data.offices'))->pluck('id')->all();

    expect($ids)->toBe([$officeA->getKey()]);
});

it('unions the create and update capabilities', function (): void {
    // Create only in their own Office, but update across all of them: the form
    // needs every Office either grant reaches.
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::OFFICE);
    grantPermissionScope($actor, 'users.update', DataScope::ALL);

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/users/options')->json('data.offices'))->pluck('id')->all();

    expect($ids)->toContain($officeA->getKey(), $officeB->getKey());
});

it('forbids the options endpoint without a management capability', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.view', DataScope::ALL);

    $this->actingAs($actor)->getJson('/api/v1/users/options')->assertForbidden();
});

it('forbids the options endpoint from a non-administrative scope', function (): void {
    [$officeA] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::OWN);

    $this->actingAs($actor)->getJson('/api/v1/users/options')->assertForbidden();
});

it('mutates no office while reading the options', function (): void {
    [$officeA, $officeB] = listOffices();

    $actor = User::factory()->for($officeA)->create();
    grantPermissionScope($actor, 'users.create', DataScope::ALL);

    $before = DB::table('offices')->orderBy('id')->get()->toArray();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->actingAs($actor)->getJson('/api/v1/users/options')->assertOk();

    foreach ($statements as $sql) {
        expect(strtolower(ltrim($sql)))->toStartWith('select');
    }

    expect(DB::table('offices')->orderBy('id')->get()->toArray())->toEqual($before);
});
