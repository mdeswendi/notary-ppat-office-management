<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Actions\CreateCompany;
use App\Domains\Party\Enums\CompanyEntityType;
use App\Domains\Party\Enums\PartyType;
use App\Models\Company;
use App\Models\Office;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding one Company permission at one scope, plus their Office.
 *
 * @return array{0: User, 1: Office}
 */
function companyActor(string $permission, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();
    grantPermissionScope($actor, $permission, $scope);

    return [$actor->fresh(), $office];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeCompanyIn(Office $office, array $attributes = []): Company
{
    $company = Company::factory()
        ->for(Party::factory()->company()->for($office), 'party')
        ->create($attributes);

    // The factory does not run the Action, so the derived display name is set
    // here to match what the product would have written.
    $company->party->forceFill(['display_name' => $company->preferredDisplayName()])->save();

    return $company->fresh(['party']);
}

/**
 * The minimum a valid create payload needs.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(Office $office, array $overrides = []): array
{
    return array_merge([
        'office_id' => $office->getKey(),
        'legal_name' => 'PT Sinar Abadi Nusantara',
        'entity_type' => 'PT',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated create', function (): void {
    $this->postJson('/api/v1/companies', ['legal_name' => 'X'])->assertUnauthorized();
});

it('denies creation without companies.create', function (): void {
    [$actor, $office] = companyActor('companies.view');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertForbidden();

    expect(Party::query()->count())->toBe(0);
});

it('does not require parties.create as well', function (): void {
    // Creating a Company writes a Party row, but that is persistence
    // composition, not an authorization fact (D-078). Requiring both would leak
    // the schema into the permission model.
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertCreated();
});

it('creates a Party and Company atomically', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $response = $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'primary_email' => 'kontak@sinarabadi.example',
        'registration_number' => 'AHU-0001234',
    ]))->assertCreated();

    expect(Party::query()->count())->toBe(1)
        ->and(Company::query()->count())->toBe(1);

    $party = Party::query()->first();

    expect($party->party_type)->toBe(PartyType::COMPANY)
        ->and($party->office_id)->toBe($office->getKey())
        ->and($party->created_by)->toBe($actor->getKey())
        ->and($response->json('data.id'))->toBe($party->getKey())
        ->and($response->json('data.entity_type'))->toBe('PT');
});

it('leaves no orphan Party when subtype creation fails', function (): void {
    // The one M2.0 invariant no constraint can carry: a parent row cannot be
    // made to require a child (D-078). It rests entirely on the transaction, so
    // the transaction is what gets tested — by breaking the subtype insert.
    [$actor, $office] = companyActor('companies.create');

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'insert into "companies"')) {
            throw new RuntimeException('simulated subtype failure');
        }
    });

    expect(fn () => app(CreateCompany::class)->handle(
        $actor,
        $office->getKey(),
        [],
        ['legal_name' => 'PT Gagal', 'entity_type' => CompanyEntityType::PT],
    ))->toThrow(RuntimeException::class);

    expect(Party::withTrashed()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

it('always creates a COMPANY, whatever the caller sends', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'party_type' => 'INDIVIDUAL',
    ]))->assertStatus(422)->assertJsonValidationErrors('party_type');

    expect(Party::query()->count())->toBe(0);
});

it('requires a legal name', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $payload = companyPayload($office);
    unset($payload['legal_name']);

    $this->actingAs($actor)->postJson('/api/v1/companies', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('legal_name');
});

it('requires an entity type', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $payload = companyPayload($office);
    unset($payload['entity_type']);

    $this->actingAs($actor)->postJson('/api/v1/companies', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('entity_type');
});

it('rejects an entity type outside the canonical seven', function (string $value): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'entity_type' => $value,
    ]))->assertStatus(422)->assertJsonValidationErrors('entity_type');
})->with(['LLC', 'GMBH', 'PT.', 'pt', 'TBK', '']);

it('accepts every canonical entity type', function (string $value): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'entity_type' => $value,
    ]))->assertCreated()->assertJsonPath('data.entity_type', $value);
})->with(CompanyEntityType::values());

it('refuses a tax identifier at create', function (): void {
    // `companies.create` must not become a way to write sensitive identity
    // (D-082) — and a create response is where a raw value would first escape.
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'tax_id' => '091234567890123',
    ]))->assertStatus(422)->assertJsonValidationErrors('tax_id');

    expect(Party::query()->count())->toBe(0);
});

it('returns no raw tax identifier in the create response', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $response = $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertCreated();

    expect($response->json('data'))->not->toHaveKey('tax_id')
        ->and($response->json('data'))->toHaveKey('tax_id_masked')
        ->and($response->json('data.tax_id_masked'))->toBeNull()
        ->and($response->json('data.has_tax_id'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| display_name derivation — D-079
|--------------------------------------------------------------------------
*/

it('derives display_name from legal_name when no short name is given', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertCreated()
        ->assertJsonPath('data.display_name', 'PT Sinar Abadi Nusantara');

    expect(Party::query()->first()->display_name)->toBe('PT Sinar Abadi Nusantara');
});

it('prefers short_name for display_name when one is present', function (): void {
    // A short name exists precisely because somebody wanted the organization
    // displayed that way, so honouring it is honouring intent.
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'short_name' => 'Sinar Abadi',
    ]))->assertCreated()->assertJsonPath('data.display_name', 'Sinar Abadi');

    expect(Party::query()->first()->display_name)->toBe('Sinar Abadi');
});

it('treats a blank short name as absent', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office, [
        'short_name' => '   ',
    ]))->assertCreated()->assertJsonPath('data.display_name', 'PT Sinar Abadi Nusantara');
});

it('adopts a short name added later', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Lama Sejahtera', 'short_name' => null]);

    expect($company->party->display_name)->toBe('PT Lama Sejahtera');

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'short_name' => 'Lama',
    ])->assertOk()->assertJsonPath('data.display_name', 'Lama');

    expect($company->party->fresh()->display_name)->toBe('Lama');
});

it('falls back to legal_name when a short name is removed', function (): void {
    // Removing a short name changes the display name without touching the legal
    // name — the case a conditional "only sync when the name changed" would miss.
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Tetap Jaya', 'short_name' => 'Tetap']);

    expect($company->party->display_name)->toBe('Tetap');

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'short_name' => null,
    ])->assertOk()->assertJsonPath('data.display_name', 'PT Tetap Jaya');

    expect($company->party->fresh()->display_name)->toBe('PT Tetap Jaya');
});

it('syncs display_name on a legal_name change while no short name exists', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Awal', 'short_name' => null]);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => 'PT Akhir Bersama',
    ])->assertOk()->assertJsonPath('data.display_name', 'PT Akhir Bersama');

    expect($company->party->fresh()->display_name)->toBe('PT Akhir Bersama');
});

it('keeps short_name precedence when only legal_name changes', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Awal', 'short_name' => 'Singkat']);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => 'PT Akhir Bersama',
    ])->assertOk()->assertJsonPath('data.display_name', 'Singkat');

    expect($company->party->fresh()->display_name)->toBe('Singkat');
});

/*
|--------------------------------------------------------------------------
| Target Office authorization
|--------------------------------------------------------------------------
*/

it('lets an OFFICE-scoped actor create in their own Office', function (): void {
    [$actor, $office] = companyActor('companies.create');

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertCreated();
});

it('refuses an OFFICE-scoped actor creating in another Office', function (): void {
    [$actor] = companyActor('companies.create');
    $other = Office::factory()->create();

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($other))
        ->assertForbidden();

    expect(Party::query()->count())->toBe(0);
});

it('lets an ALL-scoped actor create in another Office', function (): void {
    [$actor] = companyActor('companies.create', DataScope::ALL);
    $other = Office::factory()->create();

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($other))
        ->assertCreated();

    expect(Party::query()->first()->office_id)->toBe($other->getKey());
});

it('grants nothing to unsupported creation scopes', function (string $scope): void {
    [$actor, $office] = companyActor('companies.create', DataScope::from($scope));

    $this->actingAs($actor)->postJson('/api/v1/companies', companyPayload($office))
        ->assertForbidden();

    expect(Party::query()->count())->toBe(0);
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('fails closed when the grant carries no scope metadata', function (): void {
    // A role grant with no Data Scope row grants nothing (D-029).
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = Role::create(['name' => 'NO_SCOPE', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate([
        'name' => 'companies.create', 'guard_name' => 'web',
    ]));
    $actor->assignRole($role);

    $this->actingAs($actor->fresh())->postJson('/api/v1/companies', companyPayload($office))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

it('lists only Companies in the actor Office at OFFICE scope', function (): void {
    [$actor, $office] = companyActor('companies.view');
    $mine = makeCompanyIn($office, ['legal_name' => 'PT Milik Sendiri']);
    makeCompanyIn(Office::factory()->create(), ['legal_name' => 'PT Kantor Lain']);

    $response = $this->actingAs($actor)->getJson('/api/v1/companies')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($mine->party_id);
});

it('lists Companies across Offices at ALL scope', function (): void {
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    makeCompanyIn($office);
    makeCompanyIn(Office::factory()->create());

    expect($this->actingAs($actor)->getJson('/api/v1/companies')->assertOk()->json('meta.total'))
        ->toBe(2);
});

it('never lists Individuals through the Company endpoint', function (): void {
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    makeIndividualIn($office);

    expect($this->actingAs($actor)->getJson('/api/v1/companies')->assertOk()->json('meta.total'))
        ->toBe(0);
});

it('refuses the list outright at scopes that reach nothing', function (string $scope): void {
    // Refused rather than served as a reliably empty page: the grant reaches no
    // Company at all, and saying so is more honest than an empty result.
    [$actor, $office] = companyActor('companies.view', DataScope::from($scope));
    makeCompanyIn($office);

    $this->actingAs($actor)->getJson('/api/v1/companies')->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('excludes archived Companies from the list', function (): void {
    [$actor, $office] = companyActor('companies.view');
    $company = makeCompanyIn($office);
    $company->party->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/companies')->assertOk()->json('meta.total'))
        ->toBe(0);
});

it('carries no raw tax identifier in the list', function (): void {
    [$actor, $office] = companyActor('companies.view');
    makeCompanyIn($office, ['tax_id' => '091234567890123']);

    $response = $this->actingAs($actor)->getJson('/api/v1/companies')->assertOk();

    expect($response->getContent())->not->toContain('091234567890123')
        ->and($response->json('data.0'))->not->toHaveKey('tax_id')
        ->and($response->json('data.0.tax_id_masked'))->toBe('***********0123')
        ->and($response->json('data.0.has_tax_id'))->toBeTrue();
});

it('paginates the Company list', function (): void {
    [$actor, $office] = companyActor('companies.view');

    foreach (range(1, 5) as $index) {
        makeCompanyIn($office, ['legal_name' => "PT Nomor {$index}"]);
    }

    $response = $this->actingAs($actor)->getJson('/api/v1/companies?per_page=2')->assertOk();

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('meta.per_page'))->toBe(2)
        ->and($response->json('data'))->toHaveCount(2);
});

it('searches ordinary Company fields only', function (): void {
    [$actor, $office] = companyActor('companies.view');
    makeCompanyIn($office, ['legal_name' => 'PT Cahaya Timur', 'short_name' => 'Catim']);
    makeCompanyIn($office, ['legal_name' => 'PT Bumi Selatan']);

    expect($this->actingAs($actor)->getJson('/api/v1/companies?search=Cahaya')->json('meta.total'))->toBe(1)
        ->and($this->actingAs($actor)->getJson('/api/v1/companies?search=Catim')->json('meta.total'))->toBe(1)
        ->and($this->actingAs($actor)->getJson('/api/v1/companies?search=Bumi')->json('meta.total'))->toBe(1);
});

it('cannot find a Company by its tax identifier', function (): void {
    // Permanent, not pending: M2.5 shipped and D-084 settled the rule strictly.
    // Identifier lookup here would make the directory an existence oracle for
    // sensitive data. Note that M2.5's keyed fingerprint made `tax_id` matching
    // technically possible (D-086) without making it permissible — the search
    // stays closed by decision, and `registration_number`, which was never
    // encrypted, is refused for the same reason rather than a technical one.
    [$actor, $office] = companyActor('companies.view');
    makeCompanyIn($office, ['tax_id' => '091234567890123', 'registration_number' => 'AHU-777']);

    expect($this->actingAs($actor)->getJson('/api/v1/companies?search=091234567890123')->json('meta.total'))->toBe(0)
        ->and($this->actingAs($actor)->getJson('/api/v1/companies?search=AHU-777')->json('meta.total'))->toBe(0);
});

it('keeps search inside the visibility constraint', function (): void {
    [$actor, $office] = companyActor('companies.view');
    makeCompanyIn($office, ['legal_name' => 'PT Sama Nama']);
    makeCompanyIn(Office::factory()->create(), ['legal_name' => 'PT Sama Nama']);

    expect($this->actingAs($actor)->getJson('/api/v1/companies?search=Sama')->json('meta.total'))
        ->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Detail
|--------------------------------------------------------------------------
*/

it('shows a Company in the actor Office', function (): void {
    [$actor, $office] = companyActor('companies.view');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Terlihat']);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $company->party_id)
        ->assertJsonPath('data.legal_name', 'PT Terlihat');
});

it('refuses a Company in another Office at OFFICE scope', function (): void {
    [$actor] = companyActor('companies.view');
    $company = makeCompanyIn(Office::factory()->create());

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertForbidden();
});

it('shows a Company in another Office at ALL scope', function (): void {
    [$actor] = companyActor('companies.view', DataScope::ALL);
    $company = makeCompanyIn(Office::factory()->create());

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertOk();
});

it('answers 404 for an Individual Party id on a Company route', function (): void {
    // 404 rather than 403: "wrong type" would confirm a record exists in a
    // namespace the caller was not asking about, possibly in another Office.
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$individual->party_id}")->assertNotFound();
});

it('answers 404 for an unknown id', function (): void {
    [$actor] = companyActor('companies.view', DataScope::ALL);

    $this->actingAs($actor)->getJson('/api/v1/companies/'.Str::ulid())->assertNotFound();
});

it('carries no raw tax identifier in the detail response', function (): void {
    [$actor, $office] = companyActor('companies.view');
    $company = makeCompanyIn($office, ['tax_id' => '091234567890123']);

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertOk();

    expect($response->getContent())->not->toContain('091234567890123')
        ->and($response->json('data'))->not->toHaveKey('tax_id');
});

it('serializes no relationship collection', function (): void {
    // `Company::people()` exists on the model. Serializing it because it exists
    // would ship half of M2.4 through the back door (D-083).
    [$actor, $office] = companyActor('companies.view');
    $company = makeCompanyIn($office);

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertOk();

    expect($response->json('data'))->not->toHaveKey('people')
        ->and($response->json('data'))->not->toHaveKey('management')
        ->and($response->json('data'))->not->toHaveKey('shareholders')
        ->and($response->json('data'))->not->toHaveKey('company_people');
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('updates ordinary Company fields', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => 'PT Baru Sentosa',
        'entity_type' => 'CV',
        'registration_number' => 'AHU-9999',
        'city' => 'Bandung',
        'primary_phone' => '021-555-0000',
    ])->assertOk()
        ->assertJsonPath('data.legal_name', 'PT Baru Sentosa')
        ->assertJsonPath('data.entity_type', 'CV')
        ->assertJsonPath('data.registration_number', 'AHU-9999')
        ->assertJsonPath('data.city', 'Bandung')
        ->assertJsonPath('data.primary_phone', '021-555-0000');
});

it('denies an update without companies.update', function (): void {
    [$actor, $office] = companyActor('companies.view');
    $company = makeCompanyIn($office, ['legal_name' => 'PT Asli']);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => 'PT Diubah',
    ])->assertForbidden();

    expect($company->fresh()->legal_name)->toBe('PT Asli');
});

it('refuses an update to a Company in another Office at OFFICE scope', function (): void {
    [$actor] = companyActor('companies.update');
    $company = makeCompanyIn(Office::factory()->create(), ['legal_name' => 'PT Kantor Lain']);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => 'PT Diambil Alih',
    ])->assertForbidden();

    expect($company->fresh()->legal_name)->toBe('PT Kantor Lain');
});

it('refuses a party_type change through update', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'party_type' => 'INDIVIDUAL',
    ])->assertStatus(422)->assertJsonValidationErrors('party_type');

    expect($company->party->fresh()->party_type)->toBe(PartyType::COMPANY);
});

it('refuses an Office transfer through update', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);
    $other = Office::factory()->create();

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'office_id' => $other->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');

    expect($company->party->fresh()->office_id)->toBe($office->getKey());
});

it('refuses a tax identifier through the ordinary update', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'tax_id' => '091234567890123',
    ])->assertStatus(422)->assertJsonValidationErrors('tax_id');

    expect($company->fresh()->tax_id)->toBeNull();
});

it('refuses a display_name written directly', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'display_name' => 'Nama Ketiga',
    ])->assertStatus(422)->assertJsonValidationErrors('display_name');
});

it('rejects an emptied legal name', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'legal_name' => '',
    ])->assertStatus(422)->assertJsonValidationErrors('legal_name');
});

it('stamps updated_by on the aggregate root', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/companies/{$company->party_id}", [
        'city' => 'Surabaya',
    ])->assertOk();

    expect($company->party->fresh()->updated_by)->toBe($actor->getKey());
});

/*
|--------------------------------------------------------------------------
| Archive
|--------------------------------------------------------------------------
*/

it('archives the Party root and preserves the subtype', function (): void {
    [$actor, $office] = companyActor('companies.archive');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/archive")
        ->assertNoContent();

    expect(Party::withTrashed()->find($company->party_id)->deleted_at)->not->toBeNull()
        ->and(DB::table('companies')->where('party_id', $company->party_id)->exists())->toBeTrue();
});

it('denies archive without companies.archive', function (): void {
    [$actor, $office] = companyActor('companies.update');
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/archive")
        ->assertForbidden();

    expect(Party::query()->find($company->party_id))->not->toBeNull();
});

it('keeps relationship history when a Company is archived', function (): void {
    // Deleting `company_people` would destroy the history D-083 exists to keep.
    [$actor, $office] = companyActor('companies.archive');
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    DB::table('company_people')->insert([
        'id' => (string) Str::ulid(),
        'company_party_id' => $company->party_id,
        'individual_party_id' => $individual->party_id,
        'office_id' => $office->getKey(),
        'relationship_type' => 'DIRECTOR',
        'effective_from' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/archive")
        ->assertNoContent();

    expect(DB::table('company_people')->where('company_party_id', $company->party_id)->count())
        ->toBe(1);
});

it('makes an archived Company unreachable through ordinary routes', function (): void {
    [$actor, $office] = companyActor('companies.view');
    foreach (['companies.update', 'companies.archive', 'parties.identity.view'] as $extra) {
        grantPermissionScope($actor, $extra, DataScope::OFFICE);
    }
    $actor = $actor->fresh();

    $company = makeCompanyIn($office);
    $company->party->delete();

    $id = $company->party_id;

    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}")->assertNotFound();
    $this->actingAs($actor)->patchJson("/api/v1/companies/{$id}", ['city' => 'X'])->assertNotFound();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/archive")->assertNotFound();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/identity")->assertNotFound();
});

it('exposes no hard delete or restore route', function (): void {
    [$actor, $office] = companyActor('companies.archive');
    $company = makeCompanyIn($office);

    // 405, not 404: the URI exists for GET and PATCH, and no DELETE handler is
    // registered for it. Either answer proves the same thing — nothing destroys
    // the record — and 405 is the honest one for a verb with no route.
    $this->actingAs($actor)->deleteJson("/api/v1/companies/{$company->party_id}")->assertStatus(405);
    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/restore")->assertNotFound();

    expect(DB::table('companies')->count())->toBe(1)
        ->and(Party::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/

it('offers only the actor Office at OFFICE scope', function (): void {
    [$actor, $office] = companyActor('companies.create');
    Office::factory()->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/companies/options')->assertOk();

    expect($response->json('data.offices'))->toHaveCount(1)
        ->and($response->json('data.offices.0.id'))->toBe($office->getKey());
});

it('offers every active Office at ALL scope', function (): void {
    [$actor] = companyActor('companies.create', DataScope::ALL);
    Office::factory()->create();
    Office::factory()->create(['is_active' => false]);

    $response = $this->actingAs($actor)->getJson('/api/v1/companies/options')->assertOk();

    // The actor's own Office plus the one other active one; the inactive Office
    // is not offered, because create validation would reject it.
    expect($response->json('data.offices'))->toHaveCount(2);
});

it('denies options without companies.create', function (): void {
    [$actor] = companyActor('companies.view');

    $this->actingAs($actor)->getJson('/api/v1/companies/options')->assertForbidden();
});

it('offers the canonical entity type codes and nothing else', function (): void {
    [$actor] = companyActor('companies.create');

    $response = $this->actingAs($actor)->getJson('/api/v1/companies/options')->assertOk();

    expect($response->json('data.entity_types'))->toBe(CompanyEntityType::values())
        ->and($response->json('data.entity_types'))->toHaveCount(7)
        // Codes, never translated display strings (CLAUDE.md section 12).
        ->and($response->json('data.entity_types'))->toContain('PT')
        ->and($response->json('data'))->not->toHaveKey('relationship_types');
});

/*
|--------------------------------------------------------------------------
| Boundary — M2.4 and M2.5 stay out
|--------------------------------------------------------------------------
*/

it('keeps relationship surfaces behind their own permissions', function (): void {
    // At M2.3 this asserted that no relationship route existed at all, which was
    // true then. M2.4 built them, so the assertion narrowed to what M2.3 was
    // really protecting: `companies.view` reaches the Company and nothing about
    // who runs or owns it (D-083). Routes that never existed stay 404.
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    $company = makeCompanyIn($office);

    foreach (['management', 'shareholders'] as $segment) {
        $this->actingAs($actor)
            ->getJson("/api/v1/companies/{$company->party_id}/{$segment}")
            ->assertForbidden();
    }

    foreach (['people', 'directors', 'company-people'] as $segment) {
        $this->actingAs($actor)
            ->getJson("/api/v1/companies/{$company->party_id}/{$segment}")
            ->assertNotFound();
    }
});

it('exposes no duplicate detection surface', function (): void {
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    makeCompanyIn($office);

    $this->actingAs($actor)->getJson('/api/v1/companies/duplicates')->assertNotFound();
    $this->actingAs($actor)->postJson('/api/v1/companies/match')->assertNotFound();
});

it('keeps Individual behaviour unchanged', function (): void {
    // M2.3 touches the shared Party foundation, so the neighbouring subtype is
    // re-checked rather than assumed intact.
    [$actor, $office] = companyActor('parties.view');
    $individual = makeIndividualIn($office, ['full_name' => 'Budi Santoso']);

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$individual->party_id}")
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Budi Santoso');
});

it('answers 404 for a Company Party id on an Individual route', function (): void {
    [$actor, $office] = companyActor('parties.view', DataScope::ALL);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$company->party_id}")->assertNotFound();
});

it('does not let Company permissions reach Individuals', function (): void {
    [$actor, $office] = companyActor('companies.view', DataScope::ALL);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->getJson("/api/v1/individuals/{$individual->party_id}")->assertForbidden();
});

it('does not let Party permissions reach Companies', function (): void {
    [$actor, $office] = companyActor('parties.view', DataScope::ALL);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertForbidden();
    $this->actingAs($actor)->getJson('/api/v1/companies')->assertForbidden();
});
