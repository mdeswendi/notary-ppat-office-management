<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

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

it('carries no sensitive identity or fingerprint in the directory', function (): void {
    [$actor, $office] = directoryActor([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);

    makeIndividualIn($office, ['nik' => '3174012345678901', 'npwp' => '091234567890123']);
    makeCompanyIn($office, ['tax_id' => '091234567890123']);

    $body = $this->actingAs($actor)->getJson('/api/v1/parties')->assertOk()->getContent();

    foreach (['3174012345678901', '091234567890123', 'nik', 'npwp', 'tax_id',
        'fingerprint', 'masked', '*****'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
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
