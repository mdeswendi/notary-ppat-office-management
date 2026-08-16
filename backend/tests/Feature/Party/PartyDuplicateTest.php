<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Actions\UpdateCompanyIdentity;
use App\Domains\Party\Actions\UpdateIndividualIdentity;
use App\Models\Individual;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const DUP_NIK = '3174019988770001';
const DUP_NPWP = '098877665000000';

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function duplicateActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * An Individual whose identifiers carry real fingerprints.
 *
 * Written through the identity Action so the fingerprint is produced exactly as
 * the product produces it, rather than by a test helper that could drift.
 *
 * @param  array<string, mixed>  $identity
 */
function makeFingerprintedIndividual(Office $office, array $attributes, array $identity = []): Individual
{
    $individual = makeIndividualIn($office, $attributes);

    if ($identity !== []) {
        $actor = User::factory()->for($office)->create();
        app(UpdateIndividualIdentity::class)->handle($actor, $individual, $identity);
    }

    return $individual->fresh(['party']);
}

/*
|--------------------------------------------------------------------------
| Individual signals
|--------------------------------------------------------------------------
*/

it('finds a same-Office NIK match', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);
    $existing = makeFingerprintedIndividual($office, ['full_name' => 'Budi Lama'], ['nik' => DUP_NIK]);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($existing->party_id)
        ->and($response->json('data.0.signals'))->toBe(['NIK_EXACT'])
        ->and($response->json('meta.advisory'))->toBeTrue();
});

it('finds a same-Office NPWP match', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.npwp.view_full',
    ]);
    makeFingerprintedIndividual($office, ['full_name' => 'Budi'], ['npwp' => DUP_NPWP]);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'npwp' => DUP_NPWP,
    ])->assertOk();

    expect($response->json('data.0.signals'))->toBe(['NPWP_EXACT']);
});

it('finds an email match case-insensitively', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    $existing = makeIndividualIn($office);
    $existing->party->forceFill(['primary_email' => 'Budi@Example.Test'])->save();

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'primary_email' => '  budi@example.test ',
    ])->assertOk();

    expect($response->json('data.0.signals'))->toBe(['EMAIL_EXACT']);
});

it('finds a phone match on an exact trimmed value', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    $existing = makeIndividualIn($office);
    $existing->party->forceFill(['primary_phone' => '0812-3456-7890'])->save();

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'primary_phone' => ' 0812-3456-7890 ',
    ])->assertOk();

    expect($response->json('data.0.signals'))->toBe(['PHONE_EXACT']);

    // No telephone normalization is attempted: a differently formatted number is
    // a different string, and guessing the format is how a match becomes wrong.
    $reformatted = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'primary_phone' => '081234567890',
    ])->assertOk();

    expect($reformatted->json('data'))->toBe([]);
});

it('finds a name plus birth date match', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    makeIndividualIn($office, ['full_name' => 'Budi  Santoso', 'birth_date' => '1990-05-17']);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'full_name' => '  budi santoso ',
        'birth_date' => '1990-05-17',
    ])->assertOk();

    expect($response->json('data.0.signals'))->toBe(['NAME_BIRTH_DATE_EXACT']);
});

it('does not match a name without a birth date', function (): void {
    // Either alone is far too common to be a useful hint, and a name alone would
    // turn the check into a directory search.
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    makeIndividualIn($office, ['full_name' => 'Budi Santoso', 'birth_date' => '1990-05-17']);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi Santoso',
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never matches a merely similar name', function (): void {
    // No fuzzy matching, no Levenshtein, no trigram, no score.
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    makeIndividualIn($office, ['full_name' => 'Budi Santoso', 'birth_date' => '1990-05-17']);

    foreach (['Budi Santosa', 'Budhi Santoso', 'Budi Santos'] as $similar) {
        $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
            'office_id' => $office->getKey(),
            'full_name' => $similar,
            'birth_date' => '1990-05-17',
        ])->assertOk();

        expect($response->json('data'))->toBe([]);
    }
});

it('reports every signal that matched', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);
    $existing = makeFingerprintedIndividual(
        $office,
        ['full_name' => 'Budi Santoso', 'birth_date' => '1990-05-17'],
        ['nik' => DUP_NIK],
    );
    $existing->party->forceFill(['primary_email' => 'budi@example.test'])->save();

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
        'full_name' => 'Budi Santoso',
        'birth_date' => '1990-05-17',
        'primary_email' => 'budi@example.test',
    ])->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.signals'))
        ->toContain('NIK_EXACT', 'EMAIL_EXACT', 'NAME_BIRTH_DATE_EXACT');
});

it('excludes the record being edited', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.update', 'parties.identity.nik.view_full',
    ]);
    $subject = makeFingerprintedIndividual($office, ['full_name' => 'Budi'], ['nik' => DUP_NIK]);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$subject->party_id}/duplicate-candidates", ['nik' => DUP_NIK])
        ->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('refuses a client-supplied exclusion', function (): void {
    // Accepting one would let a caller suppress an inconvenient candidate from
    // somebody else's review.
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);
    $other = makeIndividualIn($office);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'exclude_party_id' => $other->party_id,
    ])->assertStatus(422)->assertJsonValidationErrors('exclude_party_id');
});

it('refuses an office chosen while editing an existing record', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.update']);
    $subject = makeIndividualIn($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$subject->party_id}/duplicate-candidates", [
            'office_id' => Office::factory()->create()->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('office_id');
});

/*
|--------------------------------------------------------------------------
| The cross-Office oracle, closed
|--------------------------------------------------------------------------
*/

it('never discovers a candidate in another Office', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);
    makeFingerprintedIndividual(Office::factory()->create(), ['full_name' => 'Elsewhere'], ['nik' => DUP_NIK]);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertOk();

    // Identical to the answer when nothing exists anywhere: no count, no hint,
    // no "match exists elsewhere".
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta'))->toBe(['advisory' => true])
        ->and($response->getContent())->not->toContain('Elsewhere');
});

it('confines an ALL-scoped check to the target Office', function (): void {
    // ALL permits working in another Office. It does not turn duplicate
    // detection into a deployment-wide identity registry.
    [$actor] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ], DataScope::ALL);

    $target = Office::factory()->create();
    $elsewhere = Office::factory()->create();
    makeFingerprintedIndividual($elsewhere, ['full_name' => 'Elsewhere'], ['nik' => DUP_NIK]);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $target->getKey(),
        'nik' => DUP_NIK,
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('omits an archived candidate', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);
    $archived = makeFingerprintedIndividual($office, ['full_name' => 'Retired'], ['nik' => DUP_NIK]);
    $archived->party->delete();

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('requires the ability to create in the target Office', function (): void {
    [$actor, $office] = duplicateActor(['parties.view']);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
    ])->assertForbidden();
});

it('discloses nothing without the ability to view candidates', function (): void {
    // Being able to create does not entitle somebody to see who is already
    // there. Duplicate assistance is simply unavailable to them.
    [$actor, $office] = duplicateActor(['parties.create']);
    makeIndividualIn($office, ['full_name' => 'Hidden']);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'full_name' => 'Hidden',
        'birth_date' => '1990-01-01',
    ])->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->getContent())->not->toContain('Hidden');
});

it('refuses a NIK check without the NIK full-view permission', function (): void {
    // 403, not a quietly narrowed result: silently dropping the signal would let
    // a caller infer the answer from its absence.
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertForbidden();
});

it('refuses an NPWP check without the NPWP full-view permission', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);

    // Holding the NIK permission says nothing about NPWP (D-082).
    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'npwp' => DUP_NPWP,
    ])->assertForbidden();
});

it('does not accept identity.update as sensitive-match authority', function (): void {
    // Writing a value is not licence to learn that somebody else already has it.
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.view', 'parties.identity.update',
    ]);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertForbidden();
});

it('allows an ordinary check without any identity permission', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'primary_email' => 'someone@example.test',
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Response shape
|--------------------------------------------------------------------------
*/

it('returns a minimal candidate carrying no identifier or fingerprint', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.nik.view_full',
    ]);
    makeFingerprintedIndividual($office, ['full_name' => 'Budi'], ['nik' => DUP_NIK, 'npwp' => DUP_NPWP]);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertOk();

    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'party_type', 'display_name', 'office', 'signals']);

    $body = $response->getContent();

    foreach ([DUP_NIK, DUP_NPWP, 'fingerprint', 'masked', 'nik_', 'npwp_', '*****'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('answers no-store for a duplicate check', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);

    $response = $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
    ])->assertOk();

    expect(strtolower((string) $response->headers->get('Cache-Control')))->toContain('no-store');
});

it('exposes no GET variant for a duplicate check', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);

    $this->actingAs($actor)->getJson('/api/v1/individuals/duplicate-candidates')->assertStatus(405);
});

/*
|--------------------------------------------------------------------------
| Company signals
|--------------------------------------------------------------------------
*/

it('finds a same-Office Company tax identifier match', function (): void {
    [$actor, $office] = duplicateActor([
        'companies.view', 'companies.create', 'parties.identity.npwp.view_full',
    ]);
    $existing = makeCompanyIn($office);
    app(UpdateCompanyIdentity::class)
        ->handle($actor, $existing, ['tax_id' => DUP_NPWP]);

    $response = $this->actingAs($actor)->postJson('/api/v1/companies/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'tax_id' => DUP_NPWP,
    ])->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.signals'))->toBe(['TAX_ID_EXACT'])
        ->and($response->json('data.0.party_type'))->toBe('COMPANY');
});

it('reuses the NPWP permission for a Company tax check', function (): void {
    // The Company tax identifier is the NPWP. No `companies.identity.*` family
    // exists, and none is invented here (D-082).
    [$actor, $office] = duplicateActor(['companies.view', 'companies.create']);

    $this->actingAs($actor)->postJson('/api/v1/companies/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'tax_id' => DUP_NPWP,
    ])->assertForbidden();
});

it('finds registration number, legal name, email, and phone matches', function (): void {
    [$actor, $office] = duplicateActor(['companies.view', 'companies.create']);
    $existing = makeCompanyIn($office, [
        'legal_name' => 'PT  Cahaya Timur', 'registration_number' => 'AHU-0001',
    ]);
    $existing->party->forceFill([
        'primary_email' => 'Info@Cahaya.Test', 'primary_phone' => '021-555-0000',
    ])->save();

    $response = $this->actingAs($actor)->postJson('/api/v1/companies/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'legal_name' => ' pt cahaya timur ',
        'registration_number' => ' ahu-0001 ',
        'primary_email' => 'info@cahaya.test',
        'primary_phone' => '021-555-0000',
    ])->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.signals'))
        ->toContain('LEGAL_NAME_EXACT', 'REGISTRATION_NUMBER_EXACT', 'EMAIL_EXACT', 'PHONE_EXACT');
});

it('never matches a merely similar legal name', function (): void {
    [$actor, $office] = duplicateActor(['companies.view', 'companies.create']);
    makeCompanyIn($office, ['legal_name' => 'PT Cahaya Timur']);

    foreach (['PT Cahaya Timor', 'PT Cahaya', 'Cahaya Timur'] as $similar) {
        $response = $this->actingAs($actor)->postJson('/api/v1/companies/duplicate-candidates', [
            'office_id' => $office->getKey(),
            'legal_name' => $similar,
        ])->assertOk();

        expect($response->json('data'))->toBe([]);
    }
});

it('excludes the Company being edited', function (): void {
    [$actor, $office] = duplicateActor(['companies.view', 'companies.update']);
    $subject = makeCompanyIn($office, ['legal_name' => 'PT Sama']);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/companies/{$subject->party_id}/duplicate-candidates", [
            'legal_name' => 'PT Sama',
        ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('never discovers a Company candidate in another Office', function (): void {
    [$actor, $office] = duplicateActor(['companies.view', 'companies.create'], DataScope::ALL);
    makeCompanyIn(Office::factory()->create(), ['legal_name' => 'PT Kantor Lain']);

    $response = $this->actingAs($actor)->postJson('/api/v1/companies/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'legal_name' => 'PT Kantor Lain',
    ])->assertOk();

    expect($response->json('data'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Advisory, never blocking
|--------------------------------------------------------------------------
*/

it('never blocks a create because a candidate exists', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.view', 'parties.identity.update',
    ]);
    makeIndividualIn($office, ['full_name' => 'Budi Santoso', 'birth_date' => '1990-05-17']);

    // An identical record is accepted. The check is a separate, optional
    // conversation the caller may ignore entirely (D-084).
    $this->actingAs($actor)->postJson('/api/v1/individuals', [
        'office_id' => $office->getKey(),
        'full_name' => 'Budi Santoso',
        'birth_date' => '1990-05-17',
    ])->assertCreated();
});

it('never blocks a Company create or an identity update because a candidate exists', function (): void {
    [$actor, $office] = duplicateActor([
        'companies.view', 'companies.create', 'companies.update',
        'parties.identity.view', 'parties.identity.update',
    ]);
    $first = makeCompanyIn($office, ['legal_name' => 'PT Kembar']);
    app(UpdateCompanyIdentity::class)
        ->handle($actor, $first, ['tax_id' => DUP_NPWP]);

    $second = $this->actingAs($actor)->postJson('/api/v1/companies', [
        'office_id' => $office->getKey(),
        'legal_name' => 'PT Kembar',
        'entity_type' => 'PT',
    ])->assertCreated()->json('data.id');

    // And the same identifier may be recorded twice: no unique constraint turns
    // advisory detection into blocking enforcement.
    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$second}/identity", ['tax_id' => DUP_NPWP])
        ->assertOk();
});

it('applies its own rate limiter without disturbing the reveal or password buckets', function (): void {
    [$actor, $office] = duplicateActor([
        'parties.view', 'parties.create', 'parties.identity.view',
        'parties.identity.nik.view_full',
    ]);
    $individual = makeFingerprintedIndividual($office, ['full_name' => 'Budi'], ['nik' => DUP_NIK]);

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
            'office_id' => $office->getKey(),
        ])->assertOk();
    }

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
    ])->assertStatus(429);

    // A different operation, so a different budget — the M1.9 defect stated for
    // a third surface (D-071).
    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    $this->actingAs($actor)->putJson('/api/v1/security/password', [
        'current_password' => 'wrong-password',
        'password' => 'ThisIsALongEnoughPassword1!',
        'password_confirmation' => 'ThisIsALongEnoughPassword1!',
    ])->assertStatus(422);
});

it('still refuses an unauthorized sensitive check whatever the budget', function (): void {
    [$actor, $office] = duplicateActor(['parties.view', 'parties.create']);

    $this->actingAs($actor)->postJson('/api/v1/individuals/duplicate-candidates', [
        'office_id' => $office->getKey(),
        'nik' => DUP_NIK,
    ])->assertForbidden();
});

it('rejects an unauthenticated duplicate check', function (): void {
    $office = Office::factory()->create();

    $this->postJson('/api/v1/individuals/duplicate-candidates', ['office_id' => $office->getKey()])
        ->assertUnauthorized();
});

it('exposes no merge, score, or similarity surface', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri());

    foreach (['merge', 'similarity', 'score', 'fuzzy', 'resolve'] as $segment) {
        expect($uris->filter(fn (string $u): bool => str_contains($u, $segment)))->toBeEmpty();
    }
});
