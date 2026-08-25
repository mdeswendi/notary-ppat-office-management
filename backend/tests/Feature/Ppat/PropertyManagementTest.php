<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Ppat\Enums\PropertyType;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\Property;
use App\Models\PropertyOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding the named Property capabilities at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function propertyApiActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * A Party in the given Office, reachable by an actor holding `parties.view`.
 */
function propertyPartyIn(Office $office): Party
{
    return Party::factory()->create(['office_id' => $office->getKey()]);
}

/**
 * A PPAT Matter under a fresh Project in the given Office.
 */
function propertyMatterIn(Office $office, ?MatterStatus $status = null): Matter
{
    return Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
        'status' => $status ?? MatterStatus::OPEN,
    ]);
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('lists only properties the caller may reach', function (): void {
    [$actor, $office] = propertyApiActor(['properties.view']);

    $mine = Property::factory()->inOffice($office)->create();
    Property::factory()->create();

    $this->actingAs($actor)->getJson('/api/v1/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->getKey())
        // The total counts only what the caller may see; no filter can widen it.
        ->assertJsonPath('meta.total', 1);
});

it('refuses the list without the capability', function (): void {
    [$actor] = propertyApiActor([]);

    $this->actingAs($actor)->getJson('/api/v1/properties')->assertForbidden();
});

it('refuses the list to a grant carrying only a scope that reaches nothing', function (): void {
    // A Property is office-owned reference data: `OWN` and `ASSIGNED` select no row,
    // so the endpoint refuses outright rather than serving a reliably empty page.
    foreach ([DataScope::OWN, DataScope::ASSIGNED] as $scope) {
        [$actor] = propertyApiActor(['properties.view'], $scope);

        $this->actingAs($actor)->getJson('/api/v1/properties')->assertForbidden();
    }
});

it('answers 404 for a property the caller cannot reach', function (): void {
    // Not 403: a 403 would confirm the record exists somewhere the caller may not
    // look (the D-098 convention).
    [$actor] = propertyApiActor(['properties.view']);

    $elsewhere = Property::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/properties/{$elsewhere->getKey()}")
        ->assertNotFound();
});

it('filters by type, right type, locality and certificate number', function (): void {
    [$actor, $office] = propertyApiActor(['properties.view']);

    $found = Property::factory()->inOffice($office)
        ->type(PropertyType::LAND_AND_BUILDING)
        ->rightType('HGB')
        ->create(['city' => 'Bandung', 'certificate_number' => 'UJI-CERT-1']);

    Property::factory()->inOffice($office)->create(['city' => 'Surabaya']);

    $this->actingAs($actor)->getJson('/api/v1/properties?property_type=LAND_AND_BUILDING')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $found->getKey());

    $this->actingAs($actor)->getJson('/api/v1/properties?right_type=HGB')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($actor)->getJson('/api/v1/properties?city=Bandung')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($actor)->getJson('/api/v1/properties?certificate_number=UJI-CERT-1')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('ignores an unrecognised filter rather than erroring', function (): void {
    // A stale bookmark should show the unfiltered list, not a 422.
    [$actor, $office] = propertyApiActor(['properties.view']);

    Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->getJson('/api/v1/properties?property_type=NONSENSE')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('searches reference, certificate and address without escaping visibility', function (): void {
    [$actor, $office] = propertyApiActor(['properties.view']);

    $found = Property::factory()->inOffice($office)->numbered('PROP-000042')
        ->create(['certificate_number' => 'UJI-XYZ']);
    Property::factory()->inOffice($office)->create(['certificate_number' => 'LAIN']);

    // A parcel in another Office carrying the same certificate must not surface.
    Property::factory()->create(['certificate_number' => 'UJI-XYZ']);

    $this->actingAs($actor)->getJson('/api/v1/properties?search=UJI-XYZ')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $found->getKey());

    $this->actingAs($actor)->getJson('/api/v1/properties?search=PROP-000042')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('offers the closed type list and the open right-type list separately', function (): void {
    // The whole point of the two lists: `property_type` is a vocabulary the ERD gives
    // flat and the database CHECKs; `right_type` is *"for example"*, so its values are
    // suggestions the interface renders as a datalist over free text.
    [$actor] = propertyApiActor(['properties.view']);

    $response = $this->actingAs($actor)->getJson('/api/v1/properties/options')->assertOk();

    expect($response->json('data.property_types'))->toBe([
        'LAND', 'LAND_AND_BUILDING', 'APARTMENT_UNIT', 'OTHER',
    ]);

    expect($response->json('data.right_type_examples'))
        ->toContain('HAK_MILIK')
        ->toContain('STRATA_TITLE');
});

/*
|--------------------------------------------------------------------------
| What the payload does and does not carry
|--------------------------------------------------------------------------
*/

it('carries no status key, because the column has no vocabulary', function (): void {
    // `properties.status` is named by the ERD and given no values (D-121 section 12).
    // A permanently-null key would invite an interface to render a lifecycle the
    // product does not have. Archived-ness is `is_archived`, from `deleted_at`.
    [$actor, $office] = propertyApiActor(['properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    expect($response->json('data'))
        ->not->toHaveKey('status')
        ->toHaveKey('is_archived');

    expect($response->json('data.is_archived'))->toBeFalse();
});

it('carries no document count, because property_documents does not exist', function (): void {
    // `DocumentRelationType` carries `party`, `project` and `matter` only and names
    // `property_documents` as blocked. A count of zero would be a lie about a junction
    // that has no rows because it has no table (O-046).
    [$actor, $office] = propertyApiActor(['properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    expect($response->json('data'))
        ->not->toHaveKey('document_count')
        ->not->toHaveKey('documents');
});

it('withholds ownership from a caller who holds only properties.view', function (): void {
    // Reading a parcel is not reading its chain of title: the catalogue splits the
    // two, and so does the payload.
    [$actor, $office] = propertyApiActor(['properties.view']);

    $property = Property::factory()->inOffice($office)->create();
    PropertyOwner::factory()->forProperty($property)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    expect($response->json('data.current_owners'))->toBeNull();
    expect($response->json('data.current_ownership_total'))->toBeNull();
});

it('shows every current owner, not one', function (): void {
    // **The M7 lock section 7.2 by name.** Co-ownership is ordinary, so a singular
    // `current_owner` would show one of two holders and silently drop the other.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    PropertyOwner::factory()->forProperty($property)->share('50.00')->create();
    PropertyOwner::factory()->forProperty($property)->share('50.00')->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    expect($response->json('data.current_owners'))->toHaveCount(2);
    expect($response->json('data.current_ownership_total'))->toEqual(100.0);
});

it('reports a total over 100 without judging it', function (): void {
    // Whether shares must total 100 is a rule about Indonesian co-ownership that no
    // canonical document states (`CLAUDE.md` section 62). The number is shown; nothing
    // refuses it.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    PropertyOwner::factory()->forProperty($property)->share('80.00')->create();
    PropertyOwner::factory()->forProperty($property)->share('80.00')->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    expect($response->json('data.current_ownership_total'))->toEqual(160.0);
});

it('never carries party identity', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    PropertyOwner::factory()->forProperty($property)->create();

    $body = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk()->getContent();

    expect($body)->not->toContain('nik')->not->toContain('npwp');
});

/*
|--------------------------------------------------------------------------
| Creation
|--------------------------------------------------------------------------
*/

it('records a property in the actor own office', function (): void {
    [$actor, $office] = propertyApiActor(['properties.create', 'properties.view']);

    $response = $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000001',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-001',
        'address' => 'Jalan Uji No. 1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.property_number', 'PROP-000001')
        ->assertJsonPath('data.is_archived', false);

    $property = Property::query()->first();

    expect($property->office_id)->toBe($office->getKey());
    expect($property->created_by)->toBe($actor->getKey());
    // No vocabulary, so nothing writes it.
    expect($property->status)->toBeNull();
});

it('refuses a property number another parcel in the same office holds', function (): void {
    [$actor, $office] = propertyApiActor(['properties.create', 'properties.view']);

    Property::factory()->inOffice($office)->numbered('PROP-000001')->create();

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000001',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-002',
        'address' => 'Jalan Uji No. 2',
    ])->assertStatus(422)->assertJsonValidationErrors('property_number');
});

it('permits the same property number in another office', function (): void {
    // An internal reference identifies a record within its Office and says nothing
    // globally (D-103).
    [$actor, $office] = propertyApiActor(['properties.create', 'properties.view']);

    Property::factory()->numbered('PROP-000001')->create();

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000001',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-003',
        'address' => 'Jalan Uji No. 3',
    ])->assertCreated();

    expect(Property::query()->where('office_id', $office->getKey())->count())->toBe(1);
});

it('validates no property number format', function (): void {
    // The ERD gives none, and `CLAUDE.md` section 62 names numbering rules among the
    // things not to invent. The office supplies whatever it uses.
    [$actor] = propertyApiActor(['properties.create', 'properties.view']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'kavling blok C/7',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-004',
        'address' => 'Jalan Uji No. 4',
    ])->assertCreated()->assertJsonPath('data.property_number', 'kavling blok C/7');
});

it('accepts a right type the erd never listed', function (): void {
    // *"Right type **may** use stable machine codes, **for example**"* — so no CHECK,
    // no `Rule::in`, and no assertion about how many kinds of right exist.
    [$actor] = propertyApiActor(['properties.create', 'properties.view']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000009',
        'property_type' => 'LAND',
        'right_type' => 'HAK_ULAYAT',
        'certificate_number' => 'UJI-009',
        'address' => 'Jalan Uji No. 9',
    ])->assertCreated()->assertJsonPath('data.right_type', 'HAK_ULAYAT');
});

it('refuses a property type outside the closed list', function (): void {
    [$actor] = propertyApiActor(['properties.create', 'properties.view']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000010',
        // The M7 brief's spelling. The ERD says APARTMENT_UNIT, and a stable machine
        // code is only stable if copied exactly (M7.1).
        'property_type' => 'APARTMENT',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-010',
        'address' => 'Jalan Uji No. 10',
    ])->assertStatus(422)->assertJsonValidationErrors('property_type');
});

it('permits two parcels to share a certificate number', function (): void {
    // Deliberately not unique: two offices may hold records of the same certificate,
    // and a certificate may be reissued (M7.1).
    [$actor, $office] = propertyApiActor(['properties.create', 'properties.view']);

    Property::factory()->inOffice($office)->create(['certificate_number' => 'UJI-SAMA']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000011',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-SAMA',
        'address' => 'Jalan Uji No. 11',
    ])->assertCreated();
});

it('refuses every system-controlled field on presence', function (string $field, mixed $value): void {
    [$actor] = propertyApiActor(['properties.create', 'properties.view']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000012',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-012',
        'address' => 'Jalan Uji No. 12',
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    // No canonical vocabulary at all — refused rather than silently dropped.
    ['status', 'ACTIVE'],
    ['created_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['deleted_at', '2026-01-01T00:00:00Z'],
]);

it('refuses creation without the capability', function (): void {
    [$actor] = propertyApiActor(['properties.view']);

    $this->actingAs($actor)->postJson('/api/v1/properties', [
        'property_number' => 'PROP-000013',
        'property_type' => 'LAND',
        'right_type' => 'HAK_MILIK',
        'certificate_number' => 'UJI-013',
        'address' => 'Jalan Uji No. 13',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Correction
|--------------------------------------------------------------------------
*/

it('corrects a property own fields', function (): void {
    [$actor, $office] = propertyApiActor(['properties.update', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
        'address' => 'Jalan Uji Baru No. 5',
        'city' => 'Bandung',
    ])->assertOk()
        ->assertJsonPath('data.address', 'Jalan Uji Baru No. 5')
        ->assertJsonPath('data.city', 'Bandung');

    expect($property->fresh()->updated_by)->toBe($actor->getKey());
});

it('refuses to change a property number', function (): void {
    // A reference belongs to the record that received it (D-103). The model throws
    // regardless; the rule turns that into a 422 that names the field.
    [$actor, $office] = propertyApiActor(['properties.update', 'properties.view']);

    $property = Property::factory()->inOffice($office)->numbered('PROP-000001')->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
        'property_number' => 'PROP-000002',
    ])->assertStatus(422)->assertJsonValidationErrors('property_number');

    expect($property->fresh()->property_number)->toBe('PROP-000001');
});

it('refuses to move a property to another office', function (): void {
    [$actor, $office] = propertyApiActor(['properties.update', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
        'office_id' => Office::factory()->create()->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');
});

it('refuses to reach ownership through the property update', function (): void {
    // Correcting an address must never rewrite who owns the land: the catalogue
    // separates the two capabilities, and so does the payload.
    [$actor, $office] = propertyApiActor(['properties.update', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    foreach (['party_id', 'ownership_percentage', 'is_current'] as $field) {
        $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
            $field => $field === 'ownership_percentage' ? 50 : '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ])->assertStatus(422)->assertJsonValidationErrors($field);
    }
});

it('refuses a blank value for a field the record needs', function (): void {
    [$actor, $office] = propertyApiActor(['properties.update', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
        'address' => '',
    ])->assertStatus(422)->assertJsonValidationErrors('address');
});

/*
|--------------------------------------------------------------------------
| Archiving — the retirement path, and the one the catalogue defines
|--------------------------------------------------------------------------
*/

it('archives a property by soft-deleting it, never by writing a status', function (): void {
    // The M7.1 question answered: the ERD gave this table `deleted_at` and the
    // catalogue gave `archive` and withheld `delete`, so read together they are one
    // mechanism. `status` stays null because it has no vocabulary.
    [$actor, $office] = propertyApiActor(['properties.archive', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_archived', true);

    $fresh = Property::withTrashed()->find($property->getKey());

    expect($fresh->deleted_at)->not->toBeNull();
    expect($fresh->status)->toBeNull();
});

it('keeps an archived property findable and readable', function (): void {
    // Retiring a record from the active list is not making it unfindable: an office
    // looking up an old certificate needs it.
    [$actor, $office] = propertyApiActor(['properties.archive', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")->assertOk();

    // Gone from the default list.
    $this->actingAs($actor)->getJson('/api/v1/properties')
        ->assertOk()->assertJsonPath('meta.total', 0);

    // Present when asked for, and still openable.
    $this->actingAs($actor)->getJson('/api/v1/properties?archived=1')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($actor)->getJson('/api/v1/properties?archived=all')
        ->assertOk()->assertJsonPath('meta.total', 1);

    $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk()->assertJsonPath('data.is_archived', true);
});

it('refuses to archive a property a running matter names', function (): void {
    // A product guard, not a legal rule: retiring a parcel that live work depends on
    // would leave that work pointing at a record the office has taken out of use. It
    // clears by itself as the Matter completes.
    [$actor, $office] = propertyApiActor([
        'properties.archive', 'properties.view', 'ppat.matters.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office, MatterStatus::IN_PROGRESS);

    $matter->properties()->attach($property->getKey(), [
        'id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
        'role_code' => 'TRANSACTION_OBJECT',
        'created_at' => now(),
    ]);

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")
        ->assertStatus(422);

    expect(Property::withTrashed()->find($property->getKey())->deleted_at)->toBeNull();
});

it('archives a property whose matters have all finished', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.archive', 'properties.view', 'ppat.matters.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office, MatterStatus::COMPLETED);

    $matter->properties()->attach($property->getKey(), [
        'id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
        'role_code' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")
        ->assertOk()->assertJsonPath('data.is_archived', true);
});

it('destroys nothing when archiving', function (): void {
    // Every link in the chain of title survives — `CLAUDE.md` section 63.
    [$actor, $office] = propertyApiActor(['properties.archive', 'properties.view']);

    $property = Property::factory()->inOffice($office)->create();
    PropertyOwner::factory()->forProperty($property)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")->assertOk();

    expect(PropertyOwner::query()->where('property_id', $property->getKey())->count())->toBe(1);
});

it('refuses to correct an archived property', function (): void {
    // 403, not 422: archived-ness is a property of the record, so the Policy denies
    // before the Action is reached — the shape a finalized deed has.
    [$actor, $office] = propertyApiActor([
        'properties.archive', 'properties.update', 'properties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}/archive")->assertOk();

    $this->actingAs($actor)->patchJson("/api/v1/properties/{$property->getKey()}", [
        'city' => 'Bandung',
    ])->assertForbidden();
});

it('turns the mutation flags off on an archived property', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.archive', 'properties.update', 'properties.view',
        'properties.ownership.view', 'properties.ownership.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    $response = $this->actingAs($actor)
        ->patchJson("/api/v1/properties/{$property->getKey()}/archive")->assertOk();

    expect($response->json('data.can_update'))->toBeFalse();
    expect($response->json('data.can_archive'))->toBeFalse();
    expect($response->json('data.can_update_ownership'))->toBeFalse();
});

it('reports every flag false for a view-only caller', function (): void {
    [$actor, $office] = propertyApiActor(['properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}")
        ->assertOk();

    foreach (['can_update', 'can_archive', 'can_view_ownership', 'can_update_ownership'] as $flag) {
        expect($response->json("data.{$flag}"))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| Ownership — its own capability, its own surface
|--------------------------------------------------------------------------
*/

it('refuses the chain of title to a caller holding only properties.view', function (): void {
    [$actor, $office] = propertyApiActor(['properties.view']);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}/owners")
        ->assertForbidden();
});

it('shows every link, closed ones included', function (): void {
    // That is what makes this a chain rather than a current state somebody edits.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    PropertyOwner::factory()->forProperty($property)->closed()->create();
    PropertyOwner::factory()->forProperty($property)->create();

    $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}/owners")
        ->assertOk()->assertJsonPath('meta.total', 2);
});

it('adds a co-owner without ending anybody else', function (): void {
    // **The brief's constraint inverted, on the M7 lock's authority.** A Property
    // legitimately has several current owners at once.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $existing = PropertyOwner::factory()->forProperty($property)->share('50.00')->create();

    $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
        'party_id' => propertyPartyIn($office)->getKey(),
        'ownership_percentage' => 50,
        'effective_from' => '2026-02-01',
    ])->assertCreated();

    expect($existing->fresh()->is_current)->toBeTrue();
    expect($existing->fresh()->effective_until)->toBeNull();

    $this->actingAs($actor)->getJson("/api/v1/properties/{$property->getKey()}/owners")
        ->assertOk()->assertJsonPath('meta.current_ownership_total', 100);
});

it('closes the current links when the caller says this is a transfer', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $previous = PropertyOwner::factory()->forProperty($property)
        ->create(['effective_from' => '2026-01-01']);

    $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
        'party_id' => propertyPartyIn($office)->getKey(),
        'effective_from' => '2026-03-01',
        'supersedes_current' => true,
    ])->assertCreated();

    $closed = $previous->fresh();

    expect($closed->is_current)->toBeFalse();
    expect($closed->effective_until->toDateString())->toBe('2026-03-01');
    // The old link's party and share are untouched — history is closed, never
    // rewritten (`CLAUDE.md` section 63).
    expect($closed->party_id)->toBe($previous->party_id);
});

it('refuses a party the caller cannot reach', function (): void {
    // `properties.ownership.update` is authority to record a transfer, never authority
    // to discover which Parties exist.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
        'party_id' => propertyPartyIn($office)->getKey(),
        'effective_from' => '2026-02-01',
    ])->assertStatus(422)->assertJsonValidationErrors('party_id');
});

it('gives an unreachable and a nonexistent party the same answer', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    // A Party in another Office, and one that does not exist.
    foreach ([propertyPartyIn(Office::factory()->create())->getKey(), (string) Str::ulid()] as $candidate) {
        $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
            'party_id' => $candidate,
            'effective_from' => '2026-02-01',
        ])->assertStatus(422)->assertJsonValidationErrors('party_id');
    }
});

it('refuses a percentage outside 0 to 100', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    foreach ([-1, 101] as $percentage) {
        $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
            'party_id' => propertyPartyIn($office)->getKey(),
            'ownership_percentage' => $percentage,
            'effective_from' => '2026-02-01',
        ])->assertStatus(422)->assertJsonValidationErrors('ownership_percentage');
    }
});

it('accepts a link with no percentage at all', function (): void {
    // A sole owner needs no share, and an office recording inherited title may have a
    // name and no figure.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
        'party_id' => propertyPartyIn($office)->getKey(),
        'effective_from' => '2026-02-01',
    ])->assertCreated()->assertJsonPath('data.ownership_percentage', null);
});

it('closes a link by stamping an end date', function (): void {
    // The only way to end an ownership: there is no delete route, because
    // `property_owners` has no `deleted_at` and a hard delete would destroy history.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $link = PropertyOwner::factory()->forProperty($property)->create();

    $this->actingAs($actor)->patchJson(
        "/api/v1/properties/{$property->getKey()}/owners/{$link->getKey()}",
        ['effective_until' => '2026-09-30'],
    )->assertOk()
        ->assertJsonPath('data.is_current', false)
        ->assertJsonPath('data.effective_until', '2026-09-30');
});

it('refuses to rewrite who owned what', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $link = PropertyOwner::factory()->forProperty($property)->create();

    foreach (['party_id', 'property_id', 'source_matter_id'] as $field) {
        $this->actingAs($actor)->patchJson(
            "/api/v1/properties/{$property->getKey()}/owners/{$link->getKey()}",
            [$field => (string) Str::ulid()],
        )->assertStatus(422)->assertJsonValidationErrors($field);
    }
});

it('refuses a period that runs backwards', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();

    $this->actingAs($actor)->postJson("/api/v1/properties/{$property->getKey()}/owners", [
        'party_id' => propertyPartyIn($office)->getKey(),
        'effective_from' => '2026-06-01',
        'effective_until' => '2026-01-01',
    ])->assertStatus(422)->assertJsonValidationErrors('effective_until');
});

it('answers 404 for a link belonging to another property', function (): void {
    // Scoped to the parent, so no address reaches a link without naming its chain.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.update', 'parties.view',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $other = Property::factory()->inOffice($office)->create();
    $link = PropertyOwner::factory()->forProperty($other)->create();

    $this->actingAs($actor)->patchJson(
        "/api/v1/properties/{$property->getKey()}/owners/{$link->getKey()}",
        ['ownership_percentage' => 10],
    )->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Which land a Matter concerns
|--------------------------------------------------------------------------
*/

it('attaches a property a matter concerns', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $property->getKey(),
        'role_code' => 'TRANSACTION_OBJECT',
    ])->assertCreated()->assertJsonPath('data.role_code', 'TRANSACTION_OBJECT');

    $this->actingAs($actor)->getJson("/api/v1/ppat/matters/{$matter->getKey()}/properties")
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('accepts a role code the erd never listed', function (): void {
    // *"Example role codes"* — so no CHECK and no `Rule::in` (M7.1).
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $property->getKey(),
        'role_code' => 'OBJEK_TUKAR_MENUKAR',
    ])->assertCreated()->assertJsonPath('data.role_code', 'OBJEK_TUKAR_MENUKAR');
});

it('corrects the role rather than erroring when a property is attached twice', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office);

    $body = ['property_id' => $property->getKey(), 'role_code' => 'TRANSACTION_OBJECT'];

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", $body)
        ->assertCreated();

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $property->getKey(),
        'role_code' => 'COLLATERAL',
    ])->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/matters/{$matter->getKey()}/properties")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.role_code', 'COLLATERAL');
});

it('refuses to attach without the matter update capability', function (): void {
    // The junction row is Matter composition, so it answers to the Matter's own
    // capability — `properties.view` alone is not enough.
    [$actor, $office] = propertyApiActor(['properties.view', 'ppat.matters.view']);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $property->getKey(),
    ])->assertForbidden();
});

it('refuses to attach a property the caller cannot reach', function (): void {
    // Composing a Matter never becomes a way to discover which Properties exist.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $matter = propertyMatterIn($office);
    $elsewhere = Property::factory()->create();

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $elsewhere->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('property_id');
});

it('detaches the junction row and nothing else', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $matter = propertyMatterIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/matters/{$matter->getKey()}/properties", [
        'property_id' => $property->getKey(),
    ])->assertCreated();

    $this->actingAs($actor)->deleteJson(
        "/api/v1/ppat/matters/{$matter->getKey()}/properties/{$property->getKey()}"
    )->assertNoContent();

    // The Property and the Matter both survive; only the assertion is withdrawn.
    expect(Property::query()->find($property->getKey()))->not->toBeNull();
    expect(Matter::query()->find($matter->getKey()))->not->toBeNull();
});

it('exposes no notary counterpart to the matter properties route', function (): void {
    // `CLAUDE.md` section 16 lists Property among the PPAT-specific concepts, and a
    // Notary Matter naming land would be a claim about Notary practice nobody here
    // may make.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'notary.matters.view', 'notary.matters.update',
    ]);

    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}/properties")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The Project and Matter filters
|--------------------------------------------------------------------------
*/

it('filters properties by the project their matters belong to', function (): void {
    // A Property has no `project_id` — it is reference data that predates every
    // Matter naming it — so the filter reaches two junctions deep (O-037's shape).
    [$actor, $office] = propertyApiActor([
        'properties.view', 'ppat.matters.view', 'ppat.matters.update',
    ]);

    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    $wanted = Property::factory()->inOffice($office)->create();
    Property::factory()->inOffice($office)->create();

    $matter->properties()->attach($wanted->getKey(), [
        'id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
        'role_code' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($actor)->getJson("/api/v1/properties?project_id={$project->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->getKey());

    $this->actingAs($actor)->getJson("/api/v1/properties?matter_id={$matter->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('never widens what the actor may see through a filter', function (): void {
    [$actor] = propertyApiActor(['properties.view']);

    $elsewhere = Office::factory()->create();
    $project = Project::factory()->for($elsewhere)->create();
    $matter = Matter::factory()->for($project)->create([
        'office_id' => $elsewhere->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);
    $property = Property::factory()->inOffice($elsewhere)->create();

    $matter->properties()->attach($property->getKey(), [
        'id' => (string) Str::ulid(),
        'office_id' => $elsewhere->getKey(),
        'role_code' => null,
        'created_at' => now(),
    ]);

    $this->actingAs($actor)->getJson("/api/v1/properties?project_id={$project->getKey()}")
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('filters by the party who currently owns the parcel', function (): void {
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.ownership.view', 'parties.view',
    ]);

    $party = propertyPartyIn($office);

    $owned = Property::factory()->inOffice($office)->create();
    PropertyOwner::factory()->forProperty($owned)->party($party)->create();

    $other = Property::factory()->inOffice($office)->create();
    PropertyOwner::factory()->forProperty($other)->create();

    $this->actingAs($actor)->getJson("/api/v1/properties?owner_party_id={$party->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->getKey());
});

/*
|--------------------------------------------------------------------------
| Routes that must not exist
|--------------------------------------------------------------------------
*/

it('exposes no delete route for a property or a link', function (string $method, string $path, int $status): void {
    // `properties.delete` is absent from the 177-code catalogue, and `property_owners`
    // has no `deleted_at`. Neither act has an address.
    [$actor, $office] = propertyApiActor([
        'properties.view', 'properties.update', 'properties.archive',
        'properties.ownership.view', 'properties.ownership.update',
    ]);

    $property = Property::factory()->inOffice($office)->create();
    $link = PropertyOwner::factory()->forProperty($property)->create();

    $url = str_replace(
        ['{property}', '{owner}'],
        [$property->getKey(), $link->getKey()],
        $path,
    );

    $this->actingAs($actor)->json($method, $url)->assertStatus($status);
})->with([
    ['DELETE', '/api/v1/properties/{property}', 405],
    ['DELETE', '/api/v1/properties/{property}/owners/{owner}', 405],
    ['PATCH', '/api/v1/properties/{property}/restore', 404],
]);

it('exposes no nested project properties route', function (): void {
    // D-118 refused that shape; `?project_id=` answers the question.
    [$actor, $office] = propertyApiActor(['properties.view']);

    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}/properties")
        ->assertNotFound();
});

it('names no property route the catalogue could not authorize', function (): void {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'api.v1.properties.'))
        ->sort()
        ->values()
        ->all();

    // Every name here is checked against a canonical capability: `properties.view`,
    // `create`, `update`, `archive` and the `ownership` pair are the six the catalogue
    // defines for this family, and `options` is `view`.
    //
    // **`properties.delete` and `properties.restore` are absent from the catalogue**,
    // so no route names either.
    expect($names)->toBe([
        'api.v1.properties.archive',
        'api.v1.properties.index',
        'api.v1.properties.options',
        'api.v1.properties.owners.index',
        'api.v1.properties.owners.store',
        'api.v1.properties.owners.update',
        'api.v1.properties.show',
        'api.v1.properties.store',
        'api.v1.properties.update',
    ]);
});
