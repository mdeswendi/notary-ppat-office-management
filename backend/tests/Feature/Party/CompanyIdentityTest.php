<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const SMOKE_TAX_ID = '091234567890123';

/**
 * An actor holding several permissions at one scope, plus their Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function companyIdentityActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/*
|--------------------------------------------------------------------------
| Identity surface — tier 1
|--------------------------------------------------------------------------
*/

it('denies the Company identity surface to companies.view alone', function (): void {
    // Reading a directory entry is not reading an organization's tax records.
    [$actor, $office] = companyIdentityActor(['companies.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertForbidden();
});

it('opens the identity surface with parties.identity.view', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertOk()
        ->assertJsonPath('data.tax_id_masked', '***********0123')
        ->assertJsonPath('data.has_tax_id', true);
});

it('reveals nothing merely by opening the identity surface', function (): void {
    [$actor, $office] = companyIdentityActor(['companies.view', 'parties.identity.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertOk();

    expect($response->getContent())->not->toContain(SMOKE_TAX_ID)
        ->and($response->json('data'))->not->toHaveKey('tax_id')
        ->and($response->json('data.can_reveal_tax_id'))->toBeFalse();
});

it('reports reveal capability honestly on the identity surface', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertOk()
        ->assertJsonPath('data.can_reveal_tax_id', true);
});

it('keeps an absent tax identifier absent rather than masked', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view']);
    $company = makeCompanyIn($office, ['tax_id' => null]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertOk()
        ->assertJsonPath('data.tax_id_masked', null)
        ->assertJsonPath('data.has_tax_id', false);
});

it('enforces the Office boundary on the identity surface', function (): void {
    [$actor] = companyIdentityActor(['parties.identity.view']);
    $company = makeCompanyIn(Office::factory()->create(), ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertForbidden();
});

it('grants no identity access at scopes that reach nothing', function (string $scope): void {
    [$actor, $office] = companyIdentityActor([
        'companies.view', 'parties.identity.view', 'parties.identity.update',
        'parties.identity.npwp.view_full',
    ], DataScope::from($scope));
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}/identity")
        ->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
})->with(['OWN', 'ASSIGNED', 'TEAM']);

/*
|--------------------------------------------------------------------------
| Identity update — tier 1
|--------------------------------------------------------------------------
*/

it('updates the tax identifier through the identity surface', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => SMOKE_TAX_ID])
        ->assertOk()
        ->assertJsonPath('data.tax_id_masked', '***********0123');

    expect($company->fresh()->tax_id)->toBe(SMOKE_TAX_ID);
});

it('denies the identity update without parties.identity.update', function (): void {
    [$actor, $office] = companyIdentityActor(['companies.update', 'parties.identity.view']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => SMOKE_TAX_ID])
        ->assertForbidden();

    expect($company->fresh()->tax_id)->toBeNull();
});

it('never echoes the submitted tax identifier back', function (): void {
    // Writing a value is not licence to read one. If the response echoed the
    // payload, `parties.identity.update` would confer a readback it does not
    // authorize (D-082).
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office);

    $response = $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => SMOKE_TAX_ID])
        ->assertOk();

    expect($response->getContent())->not->toContain(SMOKE_TAX_ID)
        ->and($response->json('data'))->not->toHaveKey('tax_id');
});

it('confers no reveal capability through the update permission', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('refuses ordinary profile fields on the identity surface', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office, ['legal_name' => 'PT Tetap']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['legal_name' => 'PT Diubah'])
        ->assertStatus(422)->assertJsonValidationErrors('legal_name');

    expect($company->fresh()->legal_name)->toBe('PT Tetap');
});

it('clears the tax identifier when null is submitted', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => null])
        ->assertOk()
        ->assertJsonPath('data.has_tax_id', false);

    expect($company->fresh()->tax_id)->toBeNull();
});

it('leaves display_name untouched by an identity update', function (): void {
    // A raw identifier must never reach a display field (D-082).
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office, ['legal_name' => 'PT Nama Tetap']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => SMOKE_TAX_ID])
        ->assertOk();

    expect($company->party->fresh()->display_name)->toBe('PT Nama Tetap');
});

it('validates no legal format for the tax identifier', function (string $value): void {
    // Format rules stay deferred pending domain authority (D-082). Encoding a
    // guess here would reject genuine identifiers.
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => $value])
        ->assertOk();
})->with(['12', '09.123.456.7-890.123', '0912345678901234567890']);

/*
|--------------------------------------------------------------------------
| Reveal — tier 2
|--------------------------------------------------------------------------
*/

it('reveals the raw tax identifier with the canonical NPWP permission', function (): void {
    // The Company tax identifier *is* the NPWP, so it answers to the same
    // canonical code an Individual's does. No `companies.identity.*` exists.
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertOk()
        ->assertJsonPath('data.field', 'tax_id')
        ->assertJsonPath('data.value', SMOKE_TAX_ID);
});

it('denies reveal to companies.view alone', function (): void {
    [$actor, $office] = companyIdentityActor(['companies.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('denies reveal to parties.identity.view alone', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();

    expect($response->getContent())->not->toContain(SMOKE_TAX_ID);
});

it('requires the identity surface as well as the reveal permission', function (): void {
    // A permission to see one field of a surface the actor may not open would be
    // incoherent, so tier 2 requires tier 1.
    [$actor, $office] = companyIdentityActor(['parties.identity.npwp.view_full']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('does not accept the NIK permission for a Company reveal', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.nik.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('enforces the Office boundary on reveal', function (): void {
    [$actor] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn(Office::factory()->create(), ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('reveals across Offices at ALL scope', function (): void {
    [$actor] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ], DataScope::ALL);
    $company = makeCompanyIn(Office::factory()->create(), ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertOk()
        ->assertJsonPath('data.value', SMOKE_TAX_ID);
});

it('answers no-store on the reveal response', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertOk();

    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private')
        ->and($response->headers->get('Pragma'))->toBe('no-cache');
});

it('returns null rather than a fabricated value when none is recorded', function (): void {
    // A placeholder would make an absent identifier indistinguishable from a
    // present one.
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => null]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertOk()
        ->assertJsonPath('data.field', 'tax_id')
        ->assertJsonPath('data.value', null);
});

it('exposes no reveal through a GET', function (): void {
    // A raw identifier must never be reachable by a method browsers, proxies,
    // and query caches treat as repeatable and cacheable.
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->getJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertStatus(405);
});

it('answers 404 for a reveal against an archived Company', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);
    $company->party->delete();

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertNotFound();

    expect($response->getContent())->not->toContain(SMOKE_TAX_ID);
});

it('answers 404 for an Individual id on the Company identity routes', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.update', 'parties.identity.npwp.view_full',
    ], DataScope::ALL);
    $individual = makeIndividualIn($office, ['npwp' => SMOKE_TAX_ID]);

    $id = $individual->party_id;

    $this->actingAs($actor)->getJson("/api/v1/companies/{$id}/identity")->assertNotFound();
    $this->actingAs($actor)->patchJson("/api/v1/companies/{$id}/identity", ['tax_id' => '1'])->assertNotFound();
    $this->actingAs($actor)->postJson("/api/v1/companies/{$id}/identity/tax-id/reveal")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
*/

it('stores the tax identifier as ciphertext', function (): void {
    [$actor, $office] = companyIdentityActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => SMOKE_TAX_ID])
        ->assertOk();

    $stored = DB::table('companies')->where('party_id', $company->party_id)->value('tax_id');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toBe(SMOKE_TAX_ID)
        ->and($stored)->not->toContain(SMOKE_TAX_ID)
        ->and($company->fresh()->tax_id)->toBe(SMOKE_TAX_ID);
});

it('hides the tax identifier from ordinary model serialization', function (): void {
    // The second of two independent defences: the Resource lists attributes
    // explicitly, and the model hides this one. Both would have to fail.
    [, $office] = companyIdentityActor(['companies.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    expect($company->fresh()->toArray())->not->toHaveKey('tax_id')
        ->and(json_encode($company->fresh()))->not->toContain(SMOKE_TAX_ID);
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('rate limits Company reveal on the Party identity bucket', function (): void {
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $url = "/api/v1/companies/{$company->party_id}/identity/tax-id/reveal";

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($actor)->postJson($url)->assertOk();
    }

    $this->actingAs($actor)->postJson($url)->assertStatus(429);
});

it('shares one reveal budget across Individual and Company', function (): void {
    // The same kind of disclosure against the same kind of record: alternating
    // between subtypes must not buy extra attempts.
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);
    $individual = makeIndividualIn($office, ['npwp' => SMOKE_TAX_ID]);

    foreach (range(1, 20) as $attempt) {
        $this->actingAs($actor)
            ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
            ->assertOk();
    }

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertStatus(429);
});

it('leaves the account-security budget untouched by Company reveal', function (): void {
    // The M1.9 defect, guarded on the new surface: exhausting one bucket must
    // not silently disable an unrelated route (D-071).
    [$actor, $office] = companyIdentityActor([
        'parties.identity.view', 'parties.identity.npwp.view_full',
    ]);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $url = "/api/v1/companies/{$company->party_id}/identity/tax-id/reveal";

    foreach (range(1, 21) as $attempt) {
        $this->actingAs($actor)->postJson($url);
    }

    $this->actingAs($actor)->postJson($url)->assertStatus(429);

    // The password route still answers on its own budget. A wrong current
    // password is a 422, not a 429 — which is the whole point.
    $this->actingAs($actor)->putJson('/api/v1/security/password', [
        'current_password' => 'wrong-password',
        'password' => 'ThisIsALongEnoughPassword1!',
        'password_confirmation' => 'ThisIsALongEnoughPassword1!',
    ])->assertStatus(422);
});

it('still refuses an unauthorized reveal whatever the budget', function (): void {
    // A limit is a brake on bulk disclosure, never a substitute for
    // authorization.
    [$actor, $office] = companyIdentityActor(['companies.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertForbidden();
});

it('rejects an unauthenticated reveal', function (): void {
    [, $office] = companyIdentityActor(['companies.view']);
    $company = makeCompanyIn($office, ['tax_id' => SMOKE_TAX_ID]);

    $this->postJson("/api/v1/companies/{$company->party_id}/identity/tax-id/reveal")
        ->assertUnauthorized();
});
