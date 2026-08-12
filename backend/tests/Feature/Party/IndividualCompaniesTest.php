<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function reverseActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

it('rejects an unauthenticated reverse read', function (): void {
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office);

    $this->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertUnauthorized();
});

it('lists the companies a person manages', function (): void {
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office, ['legal_name' => 'PT Tempat Kerja']);
    makeRelationship($company, $individual, ['position_name' => 'Direktur Utama']);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.relationship_type', 'DIRECTOR')
        ->assertJsonPath('data.0.position_name', 'Direktur Utama')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.company.display_name', 'PT Tempat Kerja')
        ->assertJsonPath('data.0.company.can_view_company', false);
});

it('requires the ability to view the person', function (): void {
    // Otherwise the endpoint becomes a way to confirm an Individual exists in an
    // Office the caller cannot reach.
    [$actor] = reverseActor(['parties.view', 'companies.management.view']);
    $elsewhere = Office::factory()->create();
    $individual = makeIndividualIn($elsewhere);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertForbidden();
});

it('requires the management permission for the management list', function (): void {
    [$actor, $office] = reverseActor(['parties.view']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertForbidden();
});

it('requires the shareholder permission for the ownership list', function (): void {
    [$actor, $office] = reverseActor(['parties.view']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/shareholders")
        ->assertForbidden();
});

it('keeps the two categories separate when reversed', function (): void {
    // The permission split survives the reversal: neither category's permission
    // reaches the other's data (D-083).
    [$management, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);

    $this->actingAs($management)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/shareholders")
        ->assertForbidden();

    [$ownership, $otherOffice] = reverseActor(['parties.view', 'companies.shareholders.view']);
    $otherIndividual = makeIndividualIn($otherOffice);

    $this->actingAs($ownership)
        ->getJson("/api/v1/individuals/{$otherIndividual->party_id}/companies/management")
        ->assertForbidden();
});

it('shows only this category of relationship', function (): void {
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.management.view', 'companies.shareholders.view',
    ]);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office);

    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::DIRECTOR]);
    makeRelationship($company, $individual, ['relationship_type' => CompanyRelationshipType::SHAREHOLDER]);

    $management = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();
    $ownership = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/shareholders")->assertOk();

    expect($management->json('data'))->toHaveCount(1)
        ->and($management->json('data.0.relationship_type'))->toBe('DIRECTOR')
        ->and($ownership->json('data'))->toHaveCount(1)
        ->and($ownership->json('data.0.relationship_type'))->toBe('SHAREHOLDER');
});

it('carries only the category-appropriate field', function (): void {
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.management.view', 'companies.shareholders.view',
    ]);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office);

    makeRelationship($company, $individual, ['position_name' => 'Direktur']);
    makeRelationship($company, $individual, [
        'relationship_type' => CompanyRelationshipType::SHAREHOLDER,
        'ownership_percentage' => '25',
    ]);

    $management = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();
    $ownership = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/shareholders")->assertOk();

    expect($management->json('data.0'))->not->toHaveKey('ownership_percentage')
        ->and($ownership->json('data.0'))->not->toHaveKey('position_name')
        ->and($ownership->json('data.0.ownership_percentage'))->toBe('25.0000');
});

it('returns ended relationships alongside current ones', function (): void {
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office);

    makeRelationship($company, $individual);
    $ended = makeRelationship($company, $individual, ['effective_until' => '2026-03-31']);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.is_current'))->toBeTrue()
        ->and(collect($response->json('data'))->firstWhere('id', $ended->id)['effective_until'])
        ->toBe('2026-03-31');
});

it('keeps history when the Company is archived', function (): void {
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office, ['legal_name' => 'PT Sudah Diarsipkan']);
    makeRelationship($company, $individual);
    $company->party->delete();

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.company.display_name'))->toBe('PT Sudah Diarsipkan')
        ->and($response->json('data.0.company.is_archived'))->toBeTrue()
        // Named but never linkable: the record is retired.
        ->and($response->json('data.0.company.can_view_company'))->toBeFalse();
});

it('computes can_view_company from the real policy, not a permission code', function (): void {
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.view', 'companies.management.view',
    ]);
    $individual = makeIndividualIn($office);

    $mine = makeCompanyIn($office);
    makeRelationship($mine, $individual);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();

    expect($response->json('data.0.company.can_view_company'))->toBeTrue();
});

it('names a Company the actor may not open without offering a link', function (): void {
    // The person's history is about that company, so hiding it would
    // misrepresent the record. What the actor cannot do is navigate.
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.management.view',
    ], DataScope::ALL);

    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office, ['legal_name' => 'PT Tak Terlihat']);
    makeRelationship($company, $individual);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")->assertOk();

    expect($response->json('data.0.company.display_name'))->toBe('PT Tak Terlihat')
        // No `companies.view` at all, so the policy refuses.
        ->and($response->json('data.0.company.can_view_company'))->toBeFalse();
});

it('reads across Offices at ALL scope', function (): void {
    [$actor] = reverseActor(['parties.view', 'companies.management.view'], DataScope::ALL);
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office);
    makeRelationship(makeCompanyIn($office), $individual);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('answers 404 for an archived Individual', function (): void {
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);
    $individual->party->delete();

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertNotFound();
});

it('exposes no sensitive identity in the reverse view', function (): void {
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.view', 'companies.management.view',
    ]);
    $individual = makeIndividualIn($office, ['nik' => '3174012345678901', 'npwp' => '091234567890123']);
    $company = makeCompanyIn($office, ['tax_id' => '091234567890123', 'registration_number' => 'AHU-1']);
    makeRelationship($company, $individual);

    $body = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
        ->assertOk()->getContent();

    foreach (['3174012345678901', '091234567890123', 'nik', 'npwp', 'tax_id',
        'fingerprint', 'masked', 'registration_number'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('exposes no relationship mutation route under an Individual', function (): void {
    // Relationship management stays on the Company, where D-085's add-and-close
    // model lives. The reverse view is read-only.
    [$actor, $office] = reverseActor([
        'parties.view', 'companies.management.view', 'companies.management.update',
    ]);
    $individual = makeIndividualIn($office);
    $company = makeCompanyIn($office);
    $relationship = makeRelationship($company, $individual);

    $base = "/api/v1/individuals/{$individual->party_id}/companies";

    $this->actingAs($actor)->postJson("{$base}/management", [])->assertStatus(405);
    $this->actingAs($actor)->postJson("{$base}/management/{$relationship->id}/end", [
        'effective_until' => '2026-03-31',
    ])->assertNotFound();
    $this->actingAs($actor)->deleteJson("{$base}/management/{$relationship->id}")->assertNotFound();

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'individuals/{individual}/companies'));

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        expect($route->methods())->toContain('GET')
            ->and($route->methods())->not->toContain('POST', 'PATCH', 'PUT', 'DELETE');
    }
});
