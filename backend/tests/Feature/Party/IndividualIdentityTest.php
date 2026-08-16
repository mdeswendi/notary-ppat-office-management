<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\MaskedIdentifier;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const SMOKE_NIK = '3174012345678901';
const SMOKE_NPWP = '091234567890123';

/**
 * An actor holding several Party permissions at one scope.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function identityActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
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
| Masking
|--------------------------------------------------------------------------
*/

it('masks all but the last four characters', function (): void {
    expect(MaskedIdentifier::mask(SMOKE_NIK))->toBe('************8901');
});

it('keeps a null identifier null', function (): void {
    // An absent identifier must not become a row of asterisks implying a value.
    expect(MaskedIdentifier::mask(null))->toBeNull()
        ->and(MaskedIdentifier::mask(''))->toBeNull()
        ->and(MaskedIdentifier::mask('   '))->toBeNull();
});

it('masks a short value completely', function (): void {
    // Revealing more of a value precisely when it looks malformed is backwards.
    expect(MaskedIdentifier::mask('12'))->toBe('**')
        ->and(MaskedIdentifier::mask('1234'))->toBe('****')
        ->and(MaskedIdentifier::mask('12345'))->toBe('*2345');
});

it('never validates or normalizes while masking', function (): void {
    // Format rules stay deferred (D-082); this must not become where one appears.
    expect(MaskedIdentifier::mask('not-a-number-at-all'))->toBe('***************-all');
});

/*
|--------------------------------------------------------------------------
| Identity surface — tier 1
|--------------------------------------------------------------------------
*/

it('denies the identity surface to parties.view alone', function (): void {
    // Reading a directory entry is not reading somebody's identity documents.
    [$actor, $office] = identityActor(['parties.view']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/identity")
        ->assertForbidden();
});

it('opens the identity surface with values still masked', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/identity")
        ->assertOk();

    expect($response->json('data.nik_masked'))->toBe('************8901')
        ->and($response->json('data.npwp_masked'))->toBe('***********0123')
        ->and($response->getContent())->not->toContain(SMOKE_NIK)
        ->and($response->getContent())->not->toContain(SMOKE_NPWP)
        ->and($response->json('data.can_reveal_nik'))->toBeFalse()
        ->and($response->json('data.can_reveal_npwp'))->toBeFalse();
});

it('reports reveal capability per field on the surface', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}/identity")
        ->assertOk();

    expect($response->json('data.can_reveal_nik'))->toBeTrue()
        ->and($response->json('data.can_reveal_npwp'))->toBeFalse();
});

it('applies the office boundary to the identity surface', function (): void {
    [$actor] = identityActor(['parties.identity.view'], DataScope::OFFICE);
    $elsewhere = makeIndividualIn(Office::factory()->create());

    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$elsewhere->party_id}/identity")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Identity update — tier 1
|--------------------------------------------------------------------------
*/

it('requires parties.identity.update to mutate identity', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['nik' => SMOKE_NIK])
        ->assertForbidden();

    expect($individual->fresh()->nik)->toBeNull();
});

it('updates identity and stores it encrypted', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", [
            'nik' => SMOKE_NIK,
            'npwp' => SMOKE_NPWP,
        ])->assertOk();

    expect($individual->fresh()->nik)->toBe(SMOKE_NIK);

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->value('nik');

    expect($stored)->not->toContain(SMOKE_NIK);
});

it('never echoes the submitted identifier back', function (): void {
    // Writing a value is not licence to read one. Echoing the payload would hand
    // a raw identifier back to an actor with no reveal permission.
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    $response = $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['nik' => SMOKE_NIK])
        ->assertOk();

    expect($response->getContent())->not->toContain(SMOKE_NIK)
        ->and($response->json('data.nik_masked'))->toBe('************8901');
});

it('refuses ordinary profile fields on the identity surface', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office, ['full_name' => 'Original']);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['full_name' => 'Changed'])
        ->assertStatus(422)->assertJsonValidationErrors('full_name');

    expect($individual->fresh()->full_name)->toBe('Original');
});

it('does not let identity.update imply any reveal', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Reveal — tier 2, per field
|--------------------------------------------------------------------------
*/

it('reveals the NIK with the correct permission', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    expect($response->json('data.value'))->toBe(SMOKE_NIK)
        ->and($response->json('data.field'))->toBe('nik');
});

it('reveals the NPWP with the correct permission', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.npwp.view_full']);
    $individual = makeIndividualIn($office, ['npwp' => SMOKE_NPWP]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertOk();

    expect($response->json('data.value'))->toBe(SMOKE_NPWP);
});

it('denies reveal to identity.view alone', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertForbidden();

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertForbidden();
});

it('does not let NIK permission reveal the NPWP', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertForbidden();
});

it('does not let NPWP permission reveal the NIK', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.npwp.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK, 'npwp' => SMOKE_NPWP]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/npwp/reveal")
        ->assertOk();

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertForbidden();
});

it('requires the identity surface as well as the field permission', function (): void {
    [$actor, $office] = identityActor(['parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertForbidden();
});

it('applies the office boundary to reveal', function (): void {
    [$actor] = identityActor(
        ['parties.identity.view', 'parties.identity.nik.view_full'],
        DataScope::OFFICE,
    );
    $elsewhere = makeIndividualIn(Office::factory()->create(), ['nik' => SMOKE_NIK]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$elsewhere->party_id}/identity/nik/reveal")
        ->assertForbidden();
});

it('lets an ALL actor reveal across offices', function (): void {
    [$actor] = identityActor(
        ['parties.identity.view', 'parties.identity.nik.view_full'],
        DataScope::ALL,
    );
    $elsewhere = makeIndividualIn(Office::factory()->create(), ['nik' => SMOKE_NIK]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$elsewhere->party_id}/identity/nik/reveal")
        ->assertOk();
});

it('sends no-store on a reveal response', function (): void {
    // The point of an explicit reveal is that the value exists in one response
    // and nowhere else — not in a shared cache, disk cache, or bfcache.
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('returns null rather than fabricating an absent identifier', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    expect($response->json('data.value'))->toBeNull();
});

it('rejects an unauthenticated reveal', function (): void {
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $this->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertUnauthorized();
});

it('keeps raw identity out of ordinary detail even for a reveal-capable actor', function (): void {
    // Holding the reveal permission does not change what the ordinary resource
    // serializes. Reveal is a separate deliberate act.
    [$actor, $office] = identityActor([
        'parties.view', 'parties.identity.view', 'parties.identity.nik.view_full',
    ]);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$individual->party_id}")
        ->assertOk();

    expect($response->getContent())->not->toContain(SMOKE_NIK);
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

it('throttles repeated reveals with its own named limiter', function (): void {
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($actor)
            ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
            ->assertOk();
    }

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertStatus(429);
});

it('does not spend the account-security budget on reveals', function (): void {
    // The M1.9 defect, guarded against: exhausting one bucket must not silently
    // disable an unrelated security route.
    [$actor, $office] = identityActor(['parties.identity.view', 'parties.identity.nik.view_full']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);
    $actor->forceFill(['password' => 'current-password-here'])->save();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($actor)
            ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
            ->assertOk();
    }

    // The password route still answers on its own budget.
    $this->actingAs($actor)->putJson('/api/v1/security/password', [
        'current_password' => 'current-password-here',
        'password' => 'replacement-password-x',
        'password_confirmation' => 'replacement-password-x',
    ])->assertNoContent();
});

it('does not let the rate limit become an authorization substitute', function (): void {
    // An unauthorized caller is refused whether or not budget remains.
    [$actor, $office] = identityActor(['parties.identity.view']);
    $individual = makeIndividualIn($office, ['nik' => SMOKE_NIK]);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($actor)
            ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
            ->assertForbidden();
    }
});
