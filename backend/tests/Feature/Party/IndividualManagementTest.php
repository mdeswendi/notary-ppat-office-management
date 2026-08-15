<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Actions\CreateIndividual;
use App\Domains\Party\Enums\PartyType;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding one Party permission at one scope, plus their Office.
 *
 * @return array{0: User, 1: Office}
 */
function partyActor(string $permission, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, $permission, $scope);

    return [$actor->fresh(), $office];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeIndividualIn(Office $office, array $attributes = []): Individual
{
    return Individual::factory()
        ->for(Party::factory()->individual()->for($office), 'party')
        ->create($attributes);
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated create', function (): void {
    $this->postJson('/api/v1/individuals', ['full_name' => 'X'])->assertUnauthorized();
});

it('denies creation without parties.create', function (): void {
    [$actor, $office] = partyActor('parties.view');

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi Santoso',
    ])->assertForbidden();

    expect(Party::query()->count())->toBe(0);
});

it('creates a Party and Individual atomically', function (): void {
    [$actor, $office] = partyActor('parties.create');

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi Santoso',
        'primary_email' => 'budi@example.test',
    ])->assertCreated();

    expect(Party::query()->count())->toBe(1)
        ->and(Individual::query()->count())->toBe(1);

    $party = Party::query()->first();

    expect($party->party_type)->toBe(PartyType::INDIVIDUAL)
        ->and($party->office_id)->toBe($office->getKey())
        ->and($party->created_by)->toBe($actor->getKey())
        ->and($response->json('data.id'))->toBe($party->getKey());
});

it('derives display_name from the canonical full name', function (): void {
    [$actor, $office] = partyActor('parties.create');

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Siti Rahayu',
    ])->assertCreated();

    expect(Party::query()->first()->display_name)->toBe('Siti Rahayu');
});

it('leaves no orphan Party when subtype creation fails', function (): void {
    // The one invariant the database cannot carry (D-078 invariant 5) rests
    // entirely on this transaction, so it is proven rather than assumed.
    [$actor, $office] = partyActor('parties.create');

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'insert into "individuals"')) {
            throw new RuntimeException('simulated subtype failure');
        }
    });

    expect(fn () => app(CreateIndividual::class)->handle(
        $actor,
        $office->getKey(),
        [],
        ['full_name' => 'Rolled Back'],
    ))->toThrow(RuntimeException::class);

    expect(Party::withTrashed()->count())->toBe(0)
        ->and(Individual::query()->count())->toBe(0);
});

it('never lets the caller choose the party type', function (): void {
    [$actor, $office] = partyActor('parties.create');

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi',
        'party_type' => 'COMPANY',
    ])->assertStatus(422);

    expect(Party::query()->count())->toBe(0);
});

it('refuses sensitive identity on the create endpoint', function (): void {
    // parties.create must not become a way to write identity data.
    [$actor, $office] = partyActor('parties.create');

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi',
        'nik' => '3174012345678901',
    ])->assertStatus(422)->assertJsonValidationErrors('nik');
});

it('returns no raw identity in the create response', function (): void {
    [$actor, $office] = partyActor('parties.create');

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi',
    ])->assertCreated();

    expect($response->json('data'))->not->toHaveKey('nik')
        ->and($response->json('data'))->not->toHaveKey('npwp');
});

/*
|--------------------------------------------------------------------------
| Create — target Office authorization
|--------------------------------------------------------------------------
*/

it('lets an OFFICE actor create in their own office', function (): void {
    [$actor, $office] = partyActor('parties.create', DataScope::OFFICE);

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi',
    ])->assertCreated();
});

it('denies an OFFICE actor creating in another office', function (): void {
    [$actor] = partyActor('parties.create', DataScope::OFFICE);
    $elsewhere = Office::factory()->create();

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $elsewhere->getKey(),
        'full_name' => 'Budi',
    ])->assertForbidden();

    expect(Party::query()->count())->toBe(0);
});

it('lets an ALL actor create in another office', function (): void {
    [$actor] = partyActor('parties.create', DataScope::ALL);
    $elsewhere = Office::factory()->create();

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $elsewhere->getKey(),
        'full_name' => 'Budi',
    ])->assertCreated();

    expect(Party::query()->first()->office_id)->toBe($elsewhere->getKey());
});

it('grants no creation for unsupported scopes', function (string $scope): void {
    [$actor, $office] = partyActor('parties.create', DataScope::from($scope));

    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi',
    ])->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

it('shows an OFFICE actor only their own office', function (): void {
    [$actor, $office] = partyActor('parties.view', DataScope::OFFICE);
    makeIndividualIn($office, ['full_name' => 'Mine']);
    makeIndividualIn(Office::factory()->create(), ['full_name' => 'Theirs']);

    $response = $this->actingAs($actor)->getJson('/api/v1/individuals')->assertOk();

    expect($response->json('data.*.full_name'))->toBe(['Mine'])
        ->and($response->json('meta.total'))->toBe(1);
});

it('shows an ALL actor every office', function (): void {
    [$actor, $office] = partyActor('parties.view', DataScope::ALL);
    makeIndividualIn($office, ['full_name' => 'Mine']);
    makeIndividualIn(Office::factory()->create(), ['full_name' => 'Theirs']);

    expect($this->actingAs($actor)->getJson('/api/v1/individuals')->json('meta.total'))->toBe(2);
});

it('grants no list access for unsupported scopes', function (string $scope): void {
    [$actor, $office] = partyActor('parties.view', DataScope::from($scope));
    makeIndividualIn($office);

    $this->actingAs($actor)->getJson('/api/v1/individuals')->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('excludes archived individuals from the list', function (): void {
    [$actor, $office] = partyActor('parties.view');
    $individual = makeIndividualIn($office);
    $individual->party->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/individuals')->json('meta.total'))->toBe(0);
});

it('never returns raw identity in the list', function (): void {
    [$actor, $office] = partyActor('parties.view');
    makeIndividualIn($office, ['nik' => '3174012345678901', 'npwp' => '091234567890123']);

    $response = $this->actingAs($actor)->getJson('/api/v1/individuals')->assertOk();

    expect($response->getContent())->not->toContain('3174012345678901')
        ->and($response->getContent())->not->toContain('091234567890123')
        ->and($response->json('data.0.nik_masked'))->toBe('************8901');
});

it('searches only non-sensitive fields', function (): void {
    // Permanent, not pending: M2.5 shipped and D-084 settled the rule strictly.
    // Allowing identifier search here would make the directory an existence
    // oracle for identity data, which the Office-scoped, permission-gated
    // duplicate check exists to prevent.
    [$actor, $office] = partyActor('parties.view');
    makeIndividualIn($office, ['full_name' => 'Budi', 'nik' => '3174012345678901']);

    $byName = $this->actingAs($actor)->getJson('/api/v1/individuals?search=Budi');
    $byNik = $this->actingAs($actor)->getJson('/api/v1/individuals?search=3174012345678901');

    expect($byName->json('meta.total'))->toBe(1)
        ->and($byNik->json('meta.total'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Detail and route binding
|--------------------------------------------------------------------------
*/

it('serves detail within the office boundary', function (): void {
    [$actor, $office] = partyActor('parties.view');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$individual->party_id}")->assertOk();
});

it('denies cross-office detail to an OFFICE actor', function (): void {
    [$actor] = partyActor('parties.view', DataScope::OFFICE);
    $elsewhere = makeIndividualIn(Office::factory()->create());

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$elsewhere->party_id}")->assertForbidden();
});

it('answers 404 for a Company party id on an individual route', function (): void {
    // 404 rather than 403: saying "wrong type" would confirm a record exists in
    // a namespace the caller did not ask about.
    [$actor, $office] = partyActor('parties.view', DataScope::ALL);
    $company = Company::factory()->for(Party::factory()->company()->for($office), 'party')->create();

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$company->party_id}")->assertNotFound();
});

it('answers 404 for an unknown id', function (): void {
    [$actor] = partyActor('parties.view', DataScope::ALL);

    $this->actingAs($actor)->getJson('/api/v1/individuals/'.Str::ulid())->assertNotFound();
});

it('makes an archived individual unreachable', function (): void {
    [$actor, $office] = partyActor('parties.view', DataScope::ALL);
    $individual = makeIndividualIn($office);
    $individual->party->delete();

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$individual->party_id}")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('updates ordinary fields and syncs display_name', function (): void {
    [$actor, $office] = partyActor('parties.update');
    $individual = makeIndividualIn($office, ['full_name' => 'Old Name']);

    $this->actingAs($actor)->patchJson("/api/v1/individuals/{$individual->party_id}", [
        'full_name' => 'New Name',
        'primary_phone' => '0812-1111-2222',
    ])->assertOk();

    $fresh = $individual->fresh(['party']);

    expect($fresh->full_name)->toBe('New Name')
        ->and($fresh->party->display_name)->toBe('New Name')
        ->and($fresh->party->primary_phone)->toBe('0812-1111-2222');
});

it('refuses identity fields on the ordinary update', function (): void {
    [$actor, $office] = partyActor('parties.update');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/individuals/{$individual->party_id}", [
        'nik' => '3174012345678901',
    ])->assertStatus(422)->assertJsonValidationErrors('nik');

    expect($individual->fresh()->nik)->toBeNull();
});

it('refuses an office transfer', function (): void {
    [$actor, $office] = partyActor('parties.update', DataScope::ALL);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/individuals/{$individual->party_id}", [
        'office_id' => Office::factory()->create()->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');

    expect($individual->fresh()->party->office_id)->toBe($office->getKey());
});

it('refuses a party_type change through update', function (): void {
    [$actor, $office] = partyActor('parties.update');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/individuals/{$individual->party_id}", [
        'party_type' => 'COMPANY',
    ])->assertStatus(422);
});

it('denies update without parties.update', function (): void {
    [$actor, $office] = partyActor('parties.view');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/individuals/{$individual->party_id}", [
        'full_name' => 'Changed',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Archive
|--------------------------------------------------------------------------
*/

it('archives the party root and keeps the subtype', function (): void {
    [$actor, $office] = partyActor('parties.archive');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/archive")
        ->assertNoContent();

    expect(Party::query()->count())->toBe(0)
        ->and(Party::withTrashed()->count())->toBe(1)
        ->and(Individual::query()->whereKey($individual->party_id)->exists())->toBeTrue();
});

it('denies archive without parties.archive', function (): void {
    [$actor, $office] = partyActor('parties.update');
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/archive")
        ->assertForbidden();
});

it('exposes no hard delete or restore route', function (): void {
    [$actor, $office] = partyActor('parties.archive', DataScope::ALL);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->deleteJson("/api/v1/individuals/{$individual->party_id}")->assertStatus(405);
    $this->actingAs($actor)->postJson("/api/v1/individuals/{$individual->party_id}/restore")->assertNotFound();

    expect(Individual::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Options metadata
|--------------------------------------------------------------------------
*/

it('offers an OFFICE actor only their own office', function (): void {
    [$actor, $office] = partyActor('parties.create', DataScope::OFFICE);
    Office::factory()->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/individuals/options')->assertOk();

    expect($response->json('data.offices.*.id'))->toBe([$office->getKey()]);
});

it('offers an ALL actor every active office', function (): void {
    [$actor] = partyActor('parties.create', DataScope::ALL);
    Office::factory()->create();

    expect($this->actingAs($actor)->getJson('/api/v1/individuals/options')->json('data.offices'))
        ->toHaveCount(2);
});

it('denies options without create capability', function (): void {
    [$actor] = partyActor('parties.view');

    $this->actingAs($actor)->getJson('/api/v1/individuals/options')->assertForbidden();
});
