<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\Company;
use App\Models\Office;
use App\Models\User;
use App\Policies\CompanyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

/*
|--------------------------------------------------------------------------
| Linkability is batched — M2.6
|--------------------------------------------------------------------------
*/

it('does not issue more queries as the reverse view grows', function (): void {
    // M2.6 found this asking the Company policy once per row. Because
    // EffectiveAccessResolver is deliberately uncached (a stale authorization
    // cache fails in the direction that grants), each row cost a fresh resolve
    // plus an exists() — measured at two extra queries per relationship, while
    // the Company-side view and the Party Directory were both flat.
    //
    // The actor's effective access does not vary by row, so the fix resolves
    // once and asks the scope predicate for all the companies at the same time.
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view']);
    $individual = makeIndividualIn($office);

    makeRelationship(makeCompanyIn($office, ['legal_name' => 'PT Satu']), $individual);

    $count = function () use ($actor, $individual): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($actor)
            ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
            ->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $oneRow = $count();

    for ($i = 2; $i <= 10; $i++) {
        makeRelationship(makeCompanyIn($office, ['legal_name' => "PT Nomor {$i}"]), $individual);
    }

    // Constant, not merely "smaller". A per-row check would land at roughly
    // oneRow + 18 here, so an off-by-a-little regression cannot hide.
    expect($count())->toBe($oneRow);
});

it('agrees with the Company policy on every row', function (): void {
    // The batched flag stands in for CompanyPolicy::view, so it has to give the
    // same answer that policy would.
    //
    // A cross-Office *relationship* cannot be built to test against: M2.1's two
    // composite foreign keys refuse one outright, which an earlier draft of this
    // test discovered by having its insert rejected. So the two ways the answer
    // legitimately varies are exercised instead — an archived company, whose
    // Party row is soft-deleted but still joined for its name, and an actor
    // whose `companies.view` scope does not reach the Office the row lives in.
    [$actor, $office] = reverseActor(['parties.view', 'companies.management.view', 'companies.view']);
    $individual = makeIndividualIn($office);

    $live = makeCompanyIn($office, ['legal_name' => 'PT Masih Aktif']);
    $archived = makeCompanyIn($office, ['legal_name' => 'PT Diarsipkan']);

    makeRelationship($live, $individual);
    makeRelationship($archived, $individual);

    $archived->party->delete();

    // A second actor who may read the person and the category deployment-wide,
    // but whose Company sight is confined to their own, different Office.
    $outsider = User::factory()->for(Office::factory()->create())->create();
    grantPermissionScope($outsider, 'parties.view', DataScope::ALL);
    grantPermissionScope($outsider, 'companies.management.view', DataScope::ALL);
    grantPermissionScope($outsider, 'companies.view', DataScope::OFFICE);

    $policy = app(CompanyPolicy::class);

    $flagsFor = function (User $reader) use ($individual, $policy): array {
        $rows = $this->actingAs($reader->fresh())
            ->getJson("/api/v1/individuals/{$individual->party_id}/companies/management")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        foreach ($rows as $row) {
            $company = Company::query()->whereKey($row['company']['id'])->firstOrFail();

            // The claim that matters: batched answer === policy answer.
            expect($row['company']['can_view_company'])
                ->toBe($policy->view($reader->fresh(), $company), $row['company']['display_name']);
        }

        return collect($rows)->pluck('company.can_view_company')->all();
    };

    // Mixed for the office-mate, so agreement is not agreement on a constant.
    expect($flagsFor($actor))->toContain(true)->toContain(false);

    // Uniformly false for the outsider: named, never linkable.
    expect($flagsFor($outsider))->toBe([false, false]);
});
