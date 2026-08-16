<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\Company;
use App\Models\CompanyPerson;
use App\Models\Individual;
use App\Models\Office;
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
 * An actor holding a set of permissions at one scope, plus their Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function relationshipActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * An Individual whose Party `display_name` matches its name.
 *
 * The shared `makeIndividualIn` helper builds the two independently, because the
 * tests that use it do not care. These do: the relationship resources report the
 * Party's `display_name` — the directory name, which is what D-079 makes
 * canonical — so a fixture whose two names disagree would be testing the
 * factory rather than the product.
 *
 * @param  array<string, mixed>  $attributes
 */
function makePersonIn(Office $office, string $name, array $attributes = []): Individual
{
    $individual = makeIndividualIn($office, array_merge(['full_name' => $name], $attributes));
    $individual->party->forceFill(['display_name' => $name])->save();

    return $individual->fresh(['party']);
}

/**
 * A relationship row built directly, bypassing the API.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeRelationship(Company $company, Individual $individual, array $attributes = []): CompanyPerson
{
    $relationship = new CompanyPerson;
    $relationship->company_party_id = $company->party_id;
    $relationship->individual_party_id = $individual->party_id;
    $relationship->office_id = $company->party->office_id;
    $relationship->fill(array_merge(['relationship_type' => CompanyRelationshipType::DIRECTOR], $attributes));
    $relationship->save();

    return $relationship;
}

/*
|--------------------------------------------------------------------------
| Management — view
|--------------------------------------------------------------------------
*/

it('rejects an unauthenticated management read', function (): void {
    $office = Office::factory()->create();
    $company = makeCompanyIn($office);

    $this->getJson("/api/v1/companies/{$company->party_id}/management")->assertUnauthorized();
});

it('lists management relationships with companies.management.view', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view']);
    $company = makeCompanyIn($office);
    $individual = makePersonIn($office, 'Budi Direktur');
    makeRelationship($company, $individual, ['position_name' => 'Direktur Utama']);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.relationship_type', 'DIRECTOR')
        ->assertJsonPath('data.0.position_name', 'Direktur Utama')
        ->assertJsonPath('data.0.individual.display_name', 'Budi Direktur')
        ->assertJsonPath('data.0.is_current', true);
});

it('denies the management list to companies.view alone', function (): void {
    // Ordinary Company details and the people who run the organization are
    // separate capabilities (D-083).
    [$actor, $office] = relationshipActor(['companies.view']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertForbidden();
});

it('denies the management list to a shareholder-only holder', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.view', 'companies.shareholders.view', 'companies.shareholders.update',
    ]);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertForbidden();
});

it('enforces the Office boundary on the management list', function (): void {
    [$actor] = relationshipActor(['companies.management.view']);
    $company = makeCompanyIn(Office::factory()->create());

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertForbidden();
});

it('lists management across Offices at ALL scope', function (): void {
    [$actor] = relationshipActor(['companies.management.view'], DataScope::ALL);
    $office = Office::factory()->create();
    $company = makeCompanyIn($office);
    makeRelationship($company, makeIndividualIn($office));

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('grants no relationship access at scopes that reach nothing', function (string $scope): void {
    [$actor, $office] = relationshipActor([
        'companies.view', 'companies.management.view', 'companies.management.update',
        'companies.shareholders.view', 'companies.shareholders.update',
    ], DataScope::from($scope));
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);
    $relationship = makeRelationship($company, $individual);

    $id = $company->party_id;

    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/management")->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/shareholders")->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/management/options")->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/management/{$relationship->id}/end", [
        'effective_until' => '2026-01-01',
    ])->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('fails closed when a relationship grant carries no scope metadata', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    $role = Role::create(['name' => 'NO_SCOPE_REL', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate([
        'name' => 'companies.management.view', 'guard_name' => 'web',
    ]));
    $actor->assignRole($role);

    $company = makeCompanyIn($office);

    $this->actingAs($actor->fresh())->getJson("/api/v1/companies/{$company->party_id}/management")
        ->assertForbidden();
});

it('shows only management types on the management list', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::DIRECTOR]);
    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::SHAREHOLDER]);
    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::BENEFICIAL_OWNER]);

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.relationship_type'))->toBe('DIRECTOR');
});

/*
|--------------------------------------------------------------------------
| Management — create
|--------------------------------------------------------------------------
*/

it('records a management relationship with companies.management.update', function (string $type): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id,
        'relationship_type' => $type,
        'effective_from' => '2026-01-15',
    ])->assertCreated()
        ->assertJsonPath('data.relationship_type', $type)
        ->assertJsonPath('data.effective_from', '2026-01-15')
        ->assertJsonPath('data.is_current', true);

    expect(CompanyPerson::query()->count())->toBe(1);
})->with(['DIRECTOR', 'COMMISSIONER', 'AUTHORIZED_PERSON']);

it('refuses an ownership type on the management surface', function (string $type): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id,
        'relationship_type' => $type,
    ])->assertStatus(422)->assertJsonValidationErrors('relationship_type');

    expect(CompanyPerson::query()->count())->toBe(0);
})->with(['SHAREHOLDER', 'BENEFICIAL_OWNER']);

it('cannot create management with the view permission alone', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertForbidden();

    expect(CompanyPerson::query()->count())->toBe(0);
});

it('requires neither companies.update nor parties.update to record a relationship', function (): void {
    // The relationship capability is the authority over `company_people`.
    // Requiring the lifecycle permission would mean anyone maintaining directors
    // could also rename the company; requiring `parties.update` would demand
    // write access to a record the operation only reads.
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertCreated();
});

it('refuses a cross-Office Individual without disclosing that it exists', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $stranger = makePersonIn(Office::factory()->create(), 'Orang Kantor Lain');

    $response = $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $stranger->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertStatus(422);

    expect($response->getContent())->not->toContain('Orang Kantor Lain')
        ->and(CompanyPerson::query()->count())->toBe(0);
});

it('refuses a cross-Office Individual even for an ALL-scoped actor', function (): void {
    // ALL grants visibility and administrative reach. It does not redefine
    // domain ownership, and a relationship may not bridge Offices (D-080).
    [$actor] = relationshipActor(['companies.management.update'], DataScope::ALL);
    $office = Office::factory()->create();
    $company = makeCompanyIn($office);
    $stranger = makeIndividualIn(Office::factory()->create());

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $stranger->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertStatus(422);

    expect(CompanyPerson::query()->count())->toBe(0);
});

it('refuses an archived Individual as a new relationship candidate', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);
    $individual->party->delete();

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertStatus(422);

    expect(CompanyPerson::query()->count())->toBe(0);
});

it('refuses an unknown Individual id', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => (string) Str::ulid(), 'relationship_type' => 'DIRECTOR',
    ])->assertStatus(422);
});

it('refuses ownership_percentage on the management surface', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id,
        'relationship_type' => 'DIRECTOR',
        'ownership_percentage' => '51',
    ])->assertStatus(422)->assertJsonValidationErrors('ownership_percentage');
});

it('refuses an end date supplied at creation', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id,
        'relationship_type' => 'DIRECTOR',
        'effective_until' => '2026-06-01',
    ])->assertStatus(422)->assertJsonValidationErrors('effective_until');
});

it('stamps the office carrier from the Company, never from input', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id,
        'relationship_type' => 'DIRECTOR',
        'office_id' => Office::factory()->create()->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('office_id');
});

it('records the actor on a new relationship', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertCreated();

    $row = CompanyPerson::query()->first();

    expect($row->created_by)->toBe($actor->getKey())
        ->and($row->office_id)->toBe($office->getKey());
});

/*
|--------------------------------------------------------------------------
| No invented corporate law
|--------------------------------------------------------------------------
*/

it('invents no cardinality rule for management', function (): void {
    // Nothing caps directors, requires a commissioner, or forbids one person
    // holding two roles. Those are legal rules M2 has no authority to invent.
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $first = makeIndividualIn($office);
    $second = makeIndividualIn($office);

    foreach ([[$first, 'DIRECTOR'], [$second, 'DIRECTOR'], [$first, 'COMMISSIONER'],
        [$first, 'AUTHORIZED_PERSON'], [$first, 'DIRECTOR']] as [$person, $type]) {
        $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
            'individual_id' => $person->party_id, 'relationship_type' => $type,
        ])->assertCreated();
    }

    expect(CompanyPerson::query()->count())->toBe(5);
});

it('invents no ownership total rule', function (): void {
    // No 100% total, no per-row cap at 100, no majority inference.
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $first = makeIndividualIn($office);
    $second = makeIndividualIn($office);

    foreach ([[$first, '80'], [$second, '75.5']] as [$person, $percentage]) {
        $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
            'individual_id' => $person->party_id,
            'relationship_type' => 'SHAREHOLDER',
            'ownership_percentage' => $percentage,
        ])->assertCreated();
    }

    expect(CompanyPerson::query()->count())->toBe(2);
});

it('never infers beneficial ownership from a shareholding', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update', 'companies.shareholders.view']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id,
        'relationship_type' => 'SHAREHOLDER',
        'ownership_percentage' => '99.9',
    ])->assertCreated();

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.relationship_type'))->toBe('SHAREHOLDER')
        ->and(CompanyPerson::query()->where('relationship_type', 'BENEFICIAL_OWNER')->count())->toBe(0);
});

it('accepts a percentage within the column bounds and rejects beyond them', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $post = fn (string $value) => $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
            'individual_id' => $individual->party_id,
            'relationship_type' => 'SHAREHOLDER',
            'ownership_percentage' => $value,
        ]);

    // decimal(7,4): four decimal places, magnitude under 1000. Not a 100 cap.
    $post('0')->assertCreated();
    $post('100')->assertCreated();
    $post('999.9999')->assertCreated();
    $post('1000')->assertStatus(422);
    $post('12.123456')->assertStatus(422);
    $post('-1')->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Ending a relationship
|--------------------------------------------------------------------------
*/

it('ends a relationship by recording a date, never by deleting', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);
    $relationship = makeRelationship($company, $individual);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertOk()
        ->assertJsonPath('data.effective_until', '2026-03-31')
        ->assertJsonPath('data.is_current', false);

    $row = CompanyPerson::query()->find($relationship->id);

    expect($row)->not->toBeNull()
        ->and($row->effective_until->toDateString())->toBe('2026-03-31')
        ->and($row->relationship_type)->toBe(CompanyRelationshipType::DIRECTOR)
        ->and($row->individual_party_id)->toBe($individual->party_id)
        ->and($row->updated_by)->toBe($actor->getKey());
});

it('requires an explicit end date', function (): void {
    // Defaulting to today would be the application inventing a legal fact about
    // when an appointment ceased.
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end", [])
        ->assertStatus(422)->assertJsonValidationErrors('effective_until');
});

it('refuses to end a relationship twice', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $url = "/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end";

    $this->actingAs($actor)->postJson($url, ['effective_until' => '2026-03-31'])->assertOk();
    $this->actingAs($actor)->postJson($url, ['effective_until' => '2026-05-31'])->assertStatus(409);

    expect(CompanyPerson::query()->find($relationship->id)->effective_until->toDateString())
        ->toBe('2026-03-31');
});

it('cannot end a relationship with the view permission alone', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertForbidden();

    expect(CompanyPerson::query()->find($relationship->id)->effective_until)->toBeNull();
});

it('refuses to change what a relationship was while ending it', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
            'relationship_type' => 'COMMISSIONER',
        ])->assertStatus(422)->assertJsonValidationErrors('relationship_type');
});

/*
|--------------------------------------------------------------------------
| Route binding — a relationship never escapes its parent or its category
|--------------------------------------------------------------------------
*/

it('answers 404 for a relationship belonging to another Company', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view', 'companies.management.update']);
    $mine = makeCompanyIn($office);
    $other = makeCompanyIn($office);
    $relationship = makeRelationship($other, makeIndividualIn($office));

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$mine->party_id}/management/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertNotFound();
});

it('answers 404 for a shareholder relationship on the management surface', function (): void {
    // 404 rather than 403: a 403 would confirm the record is real and say which
    // category it belongs to, which is what the permission split withholds.
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office), [
        'relationship_type' => CompanyRelationshipType::SHAREHOLDER,
    ]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertNotFound();

    expect(CompanyPerson::query()->find($relationship->id)->effective_until)->toBeNull();
});

it('answers 404 for a management relationship on the shareholder surface', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office), [
        'relationship_type' => CompanyRelationshipType::DIRECTOR,
    ]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/shareholders/{$relationship->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertNotFound();
});

it('answers 404 for an unknown relationship id', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/".Str::ulid().'/end', [
            'effective_until' => '2026-03-31',
        ])->assertNotFound();
});

it('makes relationship routes unavailable for an archived Company', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.management.view', 'companies.management.update',
        'companies.shareholders.view', 'companies.shareholders.update',
    ]);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);
    $relationship = makeRelationship($company, $individual);
    $company->party->delete();

    $id = $company->party_id;

    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/management")->assertNotFound();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/shareholders")->assertNotFound();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/management/options")->assertNotFound();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertNotFound();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/management/{$relationship->id}/end", [
        'effective_until' => '2026-03-31',
    ])->assertNotFound();
});

it('preserves relationship rows when the Company is archived', function (): void {
    [$actor, $office] = relationshipActor(['companies.archive']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/archive")->assertNoContent();

    expect(DB::table('company_people')->where('id', $relationship->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| History
|--------------------------------------------------------------------------
*/

it('keeps ended relationships in the history list', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view', 'companies.management.update']);
    $company = makeCompanyIn($office);
    $outgoing = makePersonIn($office, 'Direktur Lama');
    $incoming = makePersonIn($office, 'Direktur Baru');

    $ended = makeRelationship($company, $outgoing);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/management/{$ended->id}/end", [
            'effective_until' => '2026-03-31',
        ])->assertOk();

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $incoming->party_id,
        'relationship_type' => 'DIRECTOR',
        'effective_from' => '2026-04-01',
    ])->assertCreated();

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")->assertOk();

    // Both rows survive, the current one leads, and the ended one is unchanged.
    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.individual.display_name'))->toBe('Direktur Baru')
        ->and($response->json('data.0.is_current'))->toBeTrue()
        ->and($response->json('data.1.individual.display_name'))->toBe('Direktur Lama')
        ->and($response->json('data.1.effective_until'))->toBe('2026-03-31');
});

it('leaves an archived Individual historical relationship intact and readable', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.management.view', 'parties.archive',
    ]);
    $company = makeCompanyIn($office);
    $individual = makePersonIn($office, 'Orang Diarsipkan');
    $relationship = makeRelationship($company, $individual);

    $this->actingAs($actor)->postJson("/api/v1/individuals/{$individual->party_id}/archive")
        ->assertNoContent();

    $row = CompanyPerson::query()->find($relationship->id);

    // Archiving a person is not a statement about their past appointments.
    expect($row)->not->toBeNull()
        ->and($row->effective_until)->toBeNull()
        ->and($row->relationship_type)->toBe(CompanyRelationshipType::DIRECTOR);

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.individual.display_name'))->toBe('Orang Diarsipkan')
        ->and($response->json('data.0.individual.is_archived'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Shareholders
|--------------------------------------------------------------------------
*/

it('lists ownership relationships with companies.shareholders.view', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.view']);
    $company = makeCompanyIn($office);
    $individual = makePersonIn($office, 'Pemegang Saham');
    makeRelationship($company, $individual, [
        'relationship_type' => CompanyRelationshipType::SHAREHOLDER,
        'ownership_percentage' => '51.25',
    ]);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.relationship_type', 'SHAREHOLDER')
        ->assertJsonPath('data.0.ownership_percentage', '51.2500')
        ->assertJsonPath('data.0.individual.display_name', 'Pemegang Saham');
});

it('denies the shareholder list to companies.view alone', function (): void {
    [$actor, $office] = relationshipActor(['companies.view']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders")
        ->assertForbidden();
});

it('denies the shareholder list to a management-only holder', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.view', 'companies.management.view', 'companies.management.update',
    ]);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders")
        ->assertForbidden();
});

it('cannot mutate shareholders with a management update permission', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'SHAREHOLDER',
    ])->assertForbidden();

    expect(CompanyPerson::query()->count())->toBe(0);
});

it('cannot mutate management with a shareholder update permission', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/management", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'DIRECTOR',
    ])->assertForbidden();

    expect(CompanyPerson::query()->count())->toBe(0);
});

it('records an ownership relationship with companies.shareholders.update', function (string $type): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id,
        'relationship_type' => $type,
    ])->assertCreated()->assertJsonPath('data.relationship_type', $type);
})->with(['SHAREHOLDER', 'BENEFICIAL_OWNER']);

it('refuses a management type on the shareholder surface', function (string $type): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id, 'relationship_type' => $type,
    ])->assertStatus(422)->assertJsonValidationErrors('relationship_type');

    expect(CompanyPerson::query()->count())->toBe(0);
})->with(['DIRECTOR', 'COMMISSIONER', 'AUTHORIZED_PERSON']);

it('refuses position_name on the shareholder surface', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id,
        'relationship_type' => 'SHAREHOLDER',
        'position_name' => 'Direktur',
    ])->assertStatus(422)->assertJsonValidationErrors('position_name');
});

it('keeps an unrecorded percentage null rather than zero', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/shareholders", [
        'individual_id' => $individual->party_id, 'relationship_type' => 'BENEFICIAL_OWNER',
    ])->assertCreated()->assertJsonPath('data.ownership_percentage', null);
});

it('ends an ownership relationship and keeps the row', function (): void {
    [$actor, $office] = relationshipActor(['companies.shareholders.view', 'companies.shareholders.update']);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office), [
        'relationship_type' => CompanyRelationshipType::SHAREHOLDER,
        'ownership_percentage' => '10',
    ]);

    $url = "/api/v1/companies/{$company->party_id}/shareholders/{$relationship->id}/end";

    $this->actingAs($actor)->postJson($url, ['effective_until' => '2026-06-30'])->assertOk();
    $this->actingAs($actor)->postJson($url, ['effective_until' => '2026-07-31'])->assertStatus(409);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.effective_until', '2026-06-30')
        ->assertJsonPath('data.0.is_current', false);
});

/*
|--------------------------------------------------------------------------
| Candidate options
|--------------------------------------------------------------------------
*/

it('offers same-Office active Individuals as candidates', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    $candidate = makePersonIn($office, 'Calon Direktur');
    $stranger = makePersonIn(Office::factory()->create(), 'Orang Kantor Lain');
    $archived = makePersonIn($office, 'Sudah Diarsipkan');
    $archived->party->delete();

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management/options")->assertOk();

    $ids = array_column($response->json('data.individuals'), 'id');

    expect($ids)->toBe([$candidate->party_id])
        ->and($response->getContent())->not->toContain('Orang Kantor Lain')
        ->and($response->getContent())->not->toContain('Sudah Diarsipkan')
        ->and($ids)->not->toContain($stranger->party_id);
});

it('offers candidates no field beyond an id and a display name', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    makePersonIn($office, 'Calon', ['nik' => '3174012345678901', 'npwp' => '091234567890123']);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management/options")->assertOk();

    expect(array_keys($response->json('data.individuals.0')))->toBe(['id', 'display_name'])
        ->and($response->getContent())->not->toContain('3174012345678901')
        ->and($response->getContent())->not->toContain('091234567890123')
        ->and($response->getContent())->not->toContain('nik')
        ->and($response->getContent())->not->toContain('npwp');
});

it('offers only this category type codes in options', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update', 'companies.shareholders.update']);
    $company = makeCompanyIn($office);

    $management = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management/options")->assertOk();
    $ownership = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/shareholders/options")->assertOk();

    expect($management->json('data.relationship_types'))
        ->toBe(['DIRECTOR', 'COMMISSIONER', 'AUTHORIZED_PERSON'])
        ->and($ownership->json('data.relationship_types'))
        ->toBe(['SHAREHOLDER', 'BENEFICIAL_OWNER']);
});

it('requires the category update permission for its options', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.view', 'companies.shareholders.view']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/management/options")
        ->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/shareholders/options")
        ->assertForbidden();
});

it('searches candidates by name only', function (): void {
    [$actor, $office] = relationshipActor(['companies.management.update']);
    $company = makeCompanyIn($office);
    makePersonIn($office, 'Budi Santoso', ['nik' => '3174012345678901']);
    makePersonIn($office, 'Siti Rahayu');

    $byName = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management/options?search=Budi")->assertOk();
    $byNik = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management/options?search=3174012345678901")
        ->assertOk();

    expect($byName->json('data.individuals'))->toHaveCount(1)
        ->and($byNik->json('data.individuals'))->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| Resource security
|--------------------------------------------------------------------------
*/

it('exposes no sensitive identity through relationship responses', function (): void {
    // A relationship view permission is not a sensitive identity permission
    // (D-082). No raw values, and no masks either — a mask is still a statement
    // about a sensitive value.
    [$actor, $office] = relationshipActor([
        'companies.management.view', 'companies.shareholders.view',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => '091234567890123']);
    $individual = makeIndividualIn($office, [
        'nik' => '3174012345678901', 'npwp' => '098765432109876',
    ]);

    makeRelationship($company, $individual);
    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::SHAREHOLDER]);

    foreach (['management', 'shareholders'] as $surface) {
        $response = $this->actingAs($actor)
            ->getJson("/api/v1/companies/{$company->party_id}/{$surface}")->assertOk();

        $body = $response->getContent();

        expect($body)->not->toContain('3174012345678901')
            ->and($body)->not->toContain('098765432109876')
            ->and($body)->not->toContain('091234567890123')
            ->and($body)->not->toContain('nik')
            ->and($body)->not->toContain('npwp')
            ->and($body)->not->toContain('tax_id')
            ->and($body)->not->toContain('*****')
            ->and($response->json('data.0.individual'))
            ->toHaveKeys(['id', 'display_name', 'is_archived']);

        expect(array_keys($response->json('data.0.individual')))->toHaveCount(3);
    }
});

it('keeps the surfaces free of each other fields', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.management.view', 'companies.shareholders.view',
    ]);
    $company = makeCompanyIn($office);
    $individual = makeIndividualIn($office);

    makeRelationship($company, $individual, ['position_name' => 'Direktur Utama']);
    makeRelationship($company, $individual, [
        'relationship_type' => CompanyRelationshipType::SHAREHOLDER,
        'ownership_percentage' => '25',
    ]);

    $management = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/management")->assertOk();
    $ownership = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/shareholders")->assertOk();

    expect($management->json('data.0'))->not->toHaveKey('ownership_percentage')
        ->and($ownership->json('data.0'))->not->toHaveKey('position_name');
});

/*
|--------------------------------------------------------------------------
| Surface boundary
|--------------------------------------------------------------------------
*/

it('exposes no delete or generic update route for a relationship', function (): void {
    [$actor, $office] = relationshipActor([
        'companies.management.update', 'companies.shareholders.update',
    ]);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, makeIndividualIn($office));

    $base = "/api/v1/companies/{$company->party_id}/management/{$relationship->id}";

    $this->actingAs($actor)->deleteJson($base)->assertNotFound();
    $this->actingAs($actor)->patchJson($base, ['relationship_type' => 'COMMISSIONER'])->assertNotFound();
    $this->actingAs($actor)->putJson($base, ['relationship_type' => 'COMMISSIONER'])->assertNotFound();
    $this->actingAs($actor)->deleteJson("/api/v1/company-people/{$relationship->id}")->assertNotFound();

    $row = CompanyPerson::query()->find($relationship->id);

    expect($row)->not->toBeNull()
        ->and($row->relationship_type)->toBe(CompanyRelationshipType::DIRECTOR);
});

it('serializes no relationship collection on the ordinary Company resource', function (): void {
    // Management and ownership answer to their own permissions; putting either
    // in the Company payload would leak across capabilities.
    [$actor, $office] = relationshipActor(['companies.view']);
    $company = makeCompanyIn($office);
    makeRelationship($company, makeIndividualIn($office));

    $response = $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}")->assertOk();

    expect($response->json('data'))->not->toHaveKey('people')
        ->and($response->json('data'))->not->toHaveKey('management')
        ->and($response->json('data'))->not->toHaveKey('shareholders');
});
