<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\MaskedIdentifier;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Every string key appearing anywhere in a decoded JSON structure, at any
 * depth — what a security assertion should scan, in place of the raw
 * serialized body a bare substring check used to scan instead.
 *
 * @param  array<array-key, mixed>  $data
 * @return list<string>
 */
function collectJsonKeys(array $data): array
{
    $keys = [];

    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($value)) {
            $keys = [...$keys, ...collectJsonKeys($value)];
        }
    }

    return $keys;
}

/**
 * Every scalar leaf value appearing anywhere in a decoded JSON structure, at
 * any depth.
 *
 * @param  array<array-key, mixed>  $data
 * @return list<mixed>
 */
function collectJsonValues(array $data): array
{
    $values = [];

    foreach ($data as $value) {
        if (is_array($value)) {
            $values = [...$values, ...collectJsonValues($value)];

            continue;
        }

        $values[] = $value;
    }

    return $values;
}

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding each permission at its **own** scope.
 *
 * The point of the directory tests: `parties.view` and `companies.view` are
 * independent grants and may differ, so the helper takes them separately rather
 * than one scope for both.
 *
 * @param  array<string, DataScope>  $grants
 * @return array{0: User, 1: Office}
 */
function directoryActor(array $grants): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($grants as $permission => $scope) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

it('rejects an unauthenticated directory read', function (): void {
    $this->getJson('/api/v1/parties')->assertUnauthorized();
});

it('refuses the directory to an actor holding neither subtype capability', function (): void {
    [$actor] = directoryActor(['users.view' => DataScope::ALL]);

    $this->actingAs($actor)->getJson('/api/v1/parties')->assertForbidden();
});

it('shows only Individuals to a parties.view holder', function (): void {
    [$actor, $office] = directoryActor(['parties.view' => DataScope::OFFICE]);
    $individual = makeIndividualIn($office);
    makeCompanyIn($office);

    $response = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($individual->party_id)
        ->and($response->json('data.0.party_type'))->toBe('INDIVIDUAL');
});

it('shows only Companies to a companies.view holder', function (): void {
    [$actor, $office] = directoryActor(['companies.view' => DataScope::OFFICE]);
    makeIndividualIn($office);
    $company = makeCompanyIn($office);

    $response = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($company->party_id)
        ->and($response->json('data.0.party_type'))->toBe('COMPANY');
});

it('combines both subtypes when both capabilities are held', function (): void {
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);
    makeIndividualIn($office);
    makeCompanyIn($office);

    expect($this->actingAs($actor)->getJson('/api/v1/parties')->assertOk()->json('meta.total'))->toBe(2);
});

it('keeps the two scopes independent rather than collapsing them', function (): void {
    // The case this endpoint exists to get right. `parties.view` at OFFICE and
    // `companies.view` at ALL is not "ALL for parties" and not "OFFICE for
    // companies" — it is each capability at its own reach (D-028).
    [$actor, $home] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::ALL,
    ]);
    $elsewhere = Office::factory()->create();

    $mine = makeIndividualIn($home);
    makeIndividualIn($elsewhere);          // another Office's person: not visible
    $homeCompany = makeCompanyIn($home);
    $farCompany = makeCompanyIn($elsewhere); // another Office's company: visible

    $response = $this->actingAs($actor)->getJson('/api/v1/parties?per_page=100')->assertOk();
    $ids = array_column($response->json('data'), 'id');

    expect($response->json('meta.total'))->toBe(3)
        ->and($ids)->toContain($mine->party_id, $homeCompany->party_id, $farCompany->party_id);
});

it('keeps the two scopes independent in the other direction', function (): void {
    [$actor, $home] = directoryActor([
        'parties.view' => DataScope::ALL,
        'companies.view' => DataScope::OFFICE,
    ]);
    $elsewhere = Office::factory()->create();

    makeIndividualIn($home);
    makeIndividualIn($elsewhere);
    makeCompanyIn($home);
    $farCompany = makeCompanyIn($elsewhere);

    $response = $this->actingAs($actor)->getJson('/api/v1/parties?per_page=100')->assertOk();
    $ids = array_column($response->json('data'), 'id');

    expect($response->json('meta.total'))->toBe(3)
        ->and($ids)->not->toContain($farCompany->party_id);
});

it('grants no directory visibility at scopes that reach nothing', function (string $scope): void {
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::from($scope),
        'companies.view' => DataScope::from($scope),
    ]);
    makeIndividualIn($office);
    makeCompanyIn($office);

    $this->actingAs($actor)->getJson('/api/v1/parties')->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

it('contributes nothing from a capability held only at an unusable scope', function (): void {
    // OWN reaches no Party, so the directory shows Companies alone rather than
    // treating the useless grant as if it widened anything.
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OWN,
        'companies.view' => DataScope::OFFICE,
    ]);
    makeIndividualIn($office);
    $company = makeCompanyIn($office);

    $response = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($company->party_id);
});

it('excludes archived Parties from the directory', function (): void {
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office);
    $individual->party->delete();
    $company->party->delete();

    expect($this->actingAs($actor)->getJson('/api/v1/parties')->assertOk()->json('meta.total'))->toBe(0);
});

it('filters by party type without bypassing subtype permission', function (): void {
    // Asking for COMPANY without `companies.view` narrows what was requested; it
    // does not widen what may be seen, and offers no existence metadata either.
    [$actor, $office] = directoryActor(['parties.view' => DataScope::OFFICE]);
    makeIndividualIn($office);
    makeCompanyIn($office);

    $individuals = $this->actingAs($actor)->getJson('/api/v1/parties?party_type=INDIVIDUAL')->assertOk();
    $companies = $this->actingAs($actor)->getJson('/api/v1/parties?party_type=COMPANY')->assertOk();

    expect($individuals->json('meta.total'))->toBe(1)
        ->and($companies->json('meta.total'))->toBe(0)
        ->and($companies->json('data'))->toBe([]);
});

it('filters by office within what each capability already permits', function (): void {
    [$actor, $home] = directoryActor([
        'parties.view' => DataScope::ALL,
        'companies.view' => DataScope::ALL,
    ]);
    $elsewhere = Office::factory()->create();

    makeIndividualIn($home);
    makeIndividualIn($elsewhere);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/parties?office_id={$home->getKey()}")->assertOk();

    expect($response->json('meta.total'))->toBe(1);
});

it('searches ordinary fields across both subtypes', function (): void {
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);

    makeIndividualIn($office, ['full_name' => 'Budi Cahaya']);
    makeCompanyIn($office, ['legal_name' => 'PT Cahaya Timur']);
    makeIndividualIn($office, ['full_name' => 'Siti Bumi']);

    expect($this->actingAs($actor)->getJson('/api/v1/parties?search=Cahaya')->assertOk()->json('meta.total'))
        ->toBe(2);
});

it('cannot search the directory by a sensitive identifier', function (): void {
    // The directory must not become the existence oracle the Office-scoped
    // duplicate rules exist to prevent (D-084).
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);

    makeIndividualIn($office, ['nik' => '3174012345678901', 'npwp' => '091234567890123']);
    makeCompanyIn($office, ['tax_id' => '091234567890123']);

    foreach (['3174012345678901', '091234567890123'] as $identifier) {
        expect($this->actingAs($actor)->getJson("/api/v1/parties?search={$identifier}")->assertOk()->json('meta.total'))
            ->toBe(0);
    }
});

it('paginates the directory', function (): void {
    [$actor, $office] = directoryActor(['parties.view' => DataScope::OFFICE]);

    foreach (range(1, 5) as $index) {
        makeIndividualIn($office, ['full_name' => "Orang {$index}"]);
    }

    $response = $this->actingAs($actor)->getJson('/api/v1/parties?per_page=2')->assertOk();

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('data'))->toHaveCount(2);
});

it("synchronizes the fixture individual's display name with its full name", function (): void {
    // The root cause behind the flakiness below, isolated: `makeIndividualIn()`
    // used to build the Party and the Individual from two independent Faker
    // calls, so a pinned `full_name` never reached `Party.display_name` — the
    // field `PartyDirectoryResource` actually serializes. `CreateIndividual`
    // never allows that gap in production (D-079); the fixture now matches it.
    [$actor, $office] = directoryActor(['parties.view' => DataScope::OFFICE]);

    $individual = makeIndividualIn($office, ['full_name' => 'Individu Uji']);

    expect($individual->full_name)->toBe('Individu Uji')
        ->and($individual->party->display_name)->toBe('Individu Uji');

    $response = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk();

    expect($response->json('data.0.display_name'))->toBe('Individu Uji');
});

it("keeps the fixture individual's two names in sync even without an explicit full_name", function (): void {
    // The same guarantee must hold when the caller does not pin a name either
    // — `display_name` always follows whatever `full_name` the factory drew,
    // never a second, independent Faker call on the Party side.
    [, $office] = directoryActor(['parties.view' => DataScope::OFFICE]);

    $individual = makeIndividualIn($office);

    expect($individual->party->display_name)->toBe($individual->full_name);
});

it('carries no sensitive identity key or value in the directory', function (): void {
    // Three rounds of flakiness (Individual.full_name, then Party.display_name,
    // then Office.name — each an ordinary Faker `en_US` name/city draw that
    // coincidentally contains "nik") proved a bare substring check against the
    // whole serialized body cannot tell a real leak from an unrelated word. The
    // actual security contract is narrower and is checked directly: the
    // forbidden *keys* below must never be serialized at any depth, and the
    // forbidden *values* (the fixture's own sensitive identifiers, plus what
    // `MaskedIdentifier` would produce for them) must never appear as a leaf
    // value anywhere in the response tree. Office name is deliberately left to
    // the factory default (unpinned) here — proof the assertion no longer
    // depends on avoiding any particular word.
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);

    $individualNik = '3174012345678901';
    $individualNpwp = '091234567890123';
    $companyTaxId = '091234567890123';

    makeIndividualIn($office, [
        'full_name' => 'Individu Uji',
        'nik' => $individualNik,
        'npwp' => $individualNpwp,
    ]);
    makeCompanyIn($office, ['legal_name' => 'Perusahaan Uji', 'tax_id' => $companyTaxId]);

    $decoded = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk()->json();

    // The exact key family the identity surfaces use (D-082/D-086):
    // IndividualIdentityResource/CompanyIdentityResource for the masked pair,
    // the `Individual`/`Company` model columns for the raw value and the
    // blind fingerprint. `PartyDirectoryResource` must expose none of them.
    $forbiddenKeys = [
        'nik', 'npwp', 'tax_id',
        'nik_masked', 'npwp_masked', 'tax_id_masked',
        'nik_fingerprint', 'npwp_fingerprint', 'tax_id_fingerprint',
    ];
    $keys = collectJsonKeys($decoded);

    foreach ($forbiddenKeys as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }

    // The exact values a leak would actually carry — the raw identifiers this
    // test just fixtured, and their masked form (`MaskedIdentifier::mask()`,
    // e.g. "3174012345678901" -> "************8901"), never a free-standing
    // run of asterisks that could coincide with unrelated formatting.
    $forbiddenValues = [
        $individualNik,
        $individualNpwp,
        $companyTaxId,
        MaskedIdentifier::mask($individualNik),
        MaskedIdentifier::mask($individualNpwp),
        MaskedIdentifier::mask($companyTaxId),
    ];
    $values = collectJsonValues($decoded);

    foreach ($forbiddenValues as $forbidden) {
        expect($values)->not->toContain($forbidden);
    }
});

it('does not fail merely because a legitimate display value happens to contain "nik"', function (): void {
    // The false positive this pins down directly: Faker's `en_US` name and
    // city pools can draw "Monika", "Nikita", "Annika" or a "Nikolaus"-rooted
    // word for a full name, a legal name, or an office name, with nothing
    // sensitive involved. These are set deliberately (and are obviously
    // synthetic, not a real person's name) to prove the security assertion
    // above no longer confuses that letter run with a leak — the request
    // succeeds, the legitimate text is untouched, and no sensitive key or
    // value rides along with it.
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);

    $office->forceFill(['name' => 'Kantor Nikolaus Uji'])->save();

    makeIndividualIn($office, ['full_name' => 'Nikita Uji']);
    makeCompanyIn($office, ['legal_name' => 'PT Annika Uji']);

    $response = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('Nikita Uji')
        ->and($body)->toContain('PT Annika Uji')
        ->and($body)->toContain('Kantor Nikolaus Uji');

    $keys = collectJsonKeys($response->json());

    foreach (['nik', 'npwp', 'tax_id', 'nik_masked', 'npwp_masked', 'tax_id_masked',
        'nik_fingerprint', 'npwp_fingerprint', 'tax_id_fingerprint'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('exposes no generic Party mutation route', function (): void {
    // Individual and Company own their lifecycles. A generic Party write would
    // be a second way to change the same records with none of their rules
    // (D-078).
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::ALL,
        'companies.view' => DataScope::ALL,
    ]);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)->postJson('/api/v1/parties', ['display_name' => 'X'])->assertStatus(405);
    $this->actingAs($actor)->patchJson("/api/v1/parties/{$individual->party_id}", ['display_name' => 'X'])
        ->assertNotFound();
    $this->actingAs($actor)->deleteJson("/api/v1/parties/{$individual->party_id}")->assertNotFound();
    $this->actingAs($actor)->postJson("/api/v1/parties/{$individual->party_id}/archive")->assertNotFound();

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/parties'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->methods())->toContain('GET')
        ->and($routes->first()->methods())->not->toContain('POST', 'PATCH', 'PUT', 'DELETE');
});
