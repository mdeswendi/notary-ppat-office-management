<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Party\Actions\UpdateIndividualIdentity;
use App\Domains\Party\IdentityFingerprint;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

const FP_NIK = '3174012345678901';
const FP_NPWP = '091234567890123';

function fingerprints(): IdentityFingerprint
{
    return app(IdentityFingerprint::class);
}

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function fingerprintActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
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
| The primitive
|--------------------------------------------------------------------------
*/

it('produces the same fingerprint for the same input', function (): void {
    expect(fingerprints()->of(FP_NIK))->toBe(fingerprints()->of(FP_NIK));
});

it('produces a different fingerprint for different input', function (): void {
    expect(fingerprints()->of(FP_NIK))->not->toBe(fingerprints()->of('3174012345678902'));
});

it('is deterministic across service instances', function (): void {
    // Two instances derive the same subkey from the same application key.
    $first = new IdentityFingerprint(app('encrypter'));
    $second = new IdentityFingerprint(app('encrypter'));

    expect($first->of(FP_NIK))->toBe($second->of(FP_NIK));
});

it('emits a 64-character lowercase hex digest', function (): void {
    expect(fingerprints()->of(FP_NIK))->toMatch('/^[0-9a-f]{64}$/');
});

it('never returns the raw value', function (): void {
    $fingerprint = fingerprints()->of(FP_NIK);

    expect($fingerprint)->not->toBe(FP_NIK)
        ->and($fingerprint)->not->toContain(FP_NIK)
        // Not even a fragment: a shared suffix would leak the last digits the
        // masking rules deliberately expose only under permission.
        ->and($fingerprint)->not->toContain(substr(FP_NIK, -4));
});

it('trims surrounding whitespace only', function (): void {
    expect(fingerprints()->of('  '.FP_NIK.'  '))->toBe(fingerprints()->of(FP_NIK));
});

it('preserves leading zeros', function (): void {
    // "0123" and "123" are different identifiers, not the same one formatted
    // differently.
    expect(fingerprints()->of('0123456789012345'))
        ->not->toBe(fingerprints()->of('123456789012345'));
});

it('preserves internal punctuation', function (): void {
    // The accepted false negative, asserted so nobody "fixes" it by inventing
    // NPWP normalization M2 has no authority to define (D-086).
    expect(fingerprints()->of('09.123.456.7-890.123'))->not->toBe(fingerprints()->of(FP_NPWP));
});

it('keeps a null or blank identifier unfingerprinted', function (): void {
    // A shared "fingerprint of nothing" would make every record without a NIK a
    // duplicate of every other.
    expect(fingerprints()->of(null))->toBeNull()
        ->and(fingerprints()->of(''))->toBeNull()
        ->and(fingerprints()->of('   '))->toBeNull();
});

it('uses a key derived from the application key, not the key itself', function (): void {
    $raw = app('encrypter')->getKey();

    expect(fingerprints()->of(FP_NIK))->not->toBe(hash_hmac('sha256', FP_NIK, $raw))
        ->and(fingerprints()->of(FP_NIK))->toBe(hash_hmac(
            'sha256',
            FP_NIK,
            hash_hkdf('sha256', $raw, 32, 'notary-ppat/party-identity-fingerprint/v1'),
        ));
});

it('is keyed, so an unkeyed hash of the same value does not match', function (): void {
    expect(fingerprints()->of(FP_NIK))->not->toBe(hash('sha256', FP_NIK));
});

/*
|--------------------------------------------------------------------------
| Storage and synchronization
|--------------------------------------------------------------------------
*/

it('writes the fingerprint when a NIK is recorded', function (): void {
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['nik' => FP_NIK])
        ->assertOk();

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->first();

    expect(trim((string) $stored->nik_fingerprint))->toBe(fingerprints()->of(FP_NIK))
        // The raw column is still ciphertext — the fingerprint is beside it, not
        // instead of it.
        ->and($stored->nik)->not->toContain(FP_NIK);
});

it('writes the fingerprint when an NPWP is recorded', function (): void {
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['npwp' => FP_NPWP])
        ->assertOk();

    expect(trim((string) DB::table('individuals')->where('party_id', $individual->party_id)->value('npwp_fingerprint')))
        ->toBe(fingerprints()->of(FP_NPWP));
});

it('writes the fingerprint when a Company tax identifier is recorded', function (): void {
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $company = makeCompanyIn($office);

    $this->actingAs($actor)
        ->patchJson("/api/v1/companies/{$company->party_id}/identity", ['tax_id' => FP_NPWP])
        ->assertOk();

    expect(trim((string) DB::table('companies')->where('party_id', $company->party_id)->value('tax_id_fingerprint')))
        ->toBe(fingerprints()->of(FP_NPWP));
});

it('clears the fingerprint when the identifier is cleared', function (): void {
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office, ['nik' => FP_NIK]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/individuals/{$individual->party_id}/identity", ['nik' => null])
        ->assertOk();

    expect(DB::table('individuals')->where('party_id', $individual->party_id)->value('nik_fingerprint'))
        ->toBeNull();
});

it('leaves the other fingerprint untouched by a partial update', function (): void {
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    $url = "/api/v1/individuals/{$individual->party_id}/identity";

    $this->actingAs($actor)->patchJson($url, ['nik' => FP_NIK, 'npwp' => FP_NPWP])->assertOk();
    $this->actingAs($actor)->patchJson($url, ['nik' => '3174012345678999'])->assertOk();

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->first();

    expect(trim((string) $stored->nik_fingerprint))->toBe(fingerprints()->of('3174012345678999'))
        ->and(trim((string) $stored->npwp_fingerprint))->toBe(fingerprints()->of(FP_NPWP));
});

it('leaves no stale fingerprint when the identity transaction rolls back', function (): void {
    // The fingerprint is written in the same transaction as the value it derives
    // from. If that guarantee broke, a committed fingerprint could describe a
    // value that was never stored.
    [$actor, $office] = fingerprintActor(['parties.identity.view', 'parties.identity.update']);
    $individual = makeIndividualIn($office);

    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'update "parties"')) {
            throw new RuntimeException('simulated failure after the subtype write');
        }
    });

    expect(fn () => app(UpdateIndividualIdentity::class)
        ->handle($actor, $individual, ['nik' => FP_NIK]))
        ->toThrow(RuntimeException::class);

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->first();

    expect($stored->nik_fingerprint)->toBeNull()
        ->and($stored->nik)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Never serialized
|--------------------------------------------------------------------------
*/

it('hides the fingerprint from every response that touches identity', function (): void {
    [$actor, $office] = fingerprintActor([
        'parties.view', 'parties.identity.view', 'parties.identity.update',
        'parties.identity.nik.view_full', 'parties.identity.npwp.view_full',
        'companies.view',
    ]);

    $individual = makeIndividualIn($office, ['nik' => FP_NIK, 'npwp' => FP_NPWP]);
    $company = makeCompanyIn($office, ['tax_id' => FP_NPWP]);

    $id = $individual->party_id;

    $responses = [
        $this->actingAs($actor)->getJson('/api/v1/individuals'),
        $this->actingAs($actor)->getJson("/api/v1/individuals/{$id}"),
        $this->actingAs($actor)->getJson("/api/v1/individuals/{$id}/identity"),
        // Even the reveal, which legitimately returns the raw identifier: that
        // permission authorizes the value through the reviewed surface, not the
        // cryptographic material derived from it.
        $this->actingAs($actor)->postJson("/api/v1/individuals/{$id}/identity/nik/reveal"),
        $this->actingAs($actor)->getJson("/api/v1/companies/{$company->party_id}"),
        $this->actingAs($actor)->getJson('/api/v1/parties'),
    ];

    foreach ($responses as $response) {
        $body = $response->getContent();

        expect($body)->not->toContain('fingerprint')
            ->and($body)->not->toContain(fingerprints()->of(FP_NIK))
            ->and($body)->not->toContain(fingerprints()->of(FP_NPWP));
    }
});

it('hides the fingerprint from ordinary model serialization', function (): void {
    [, $office] = fingerprintActor(['parties.view']);
    $individual = makeIndividualIn($office, ['nik' => FP_NIK]);

    expect($individual->fresh()->toArray())->not->toHaveKey('nik_fingerprint')
        ->and($individual->fresh()->toArray())->not->toHaveKey('npwp_fingerprint')
        ->and(makeCompanyIn($office, ['tax_id' => FP_NPWP])->fresh()->toArray())
        ->not->toHaveKey('tax_id_fingerprint');
});

it('refuses a fingerprint supplied through mass assignment', function (): void {
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office);

    $individual->fill(['nik_fingerprint' => str_repeat('a', 64)]);

    expect($individual->nik_fingerprint)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Backfill command
|--------------------------------------------------------------------------
*/

it('backfills fingerprints for identifiers recorded before the column existed', function (): void {
    $office = Office::factory()->create();

    // Written straight through the model, as M2.2/M2.3 rows were: encrypted
    // value, no fingerprint.
    $individual = makeIndividualIn($office, ['nik' => FP_NIK, 'npwp' => FP_NPWP]);
    $company = makeCompanyIn($office, ['tax_id' => FP_NPWP]);

    DB::table('individuals')->where('party_id', $individual->party_id)
        ->update(['nik_fingerprint' => null, 'npwp_fingerprint' => null]);
    DB::table('companies')->where('party_id', $company->party_id)
        ->update(['tax_id_fingerprint' => null]);

    $this->artisan('parties:rebuild-identity-fingerprints')->assertSuccessful();

    expect(trim((string) DB::table('individuals')->where('party_id', $individual->party_id)->value('nik_fingerprint')))
        ->toBe(fingerprints()->of(FP_NIK))
        ->and(trim((string) DB::table('individuals')->where('party_id', $individual->party_id)->value('npwp_fingerprint')))
        ->toBe(fingerprints()->of(FP_NPWP))
        ->and(trim((string) DB::table('companies')->where('party_id', $company->party_id)->value('tax_id_fingerprint')))
        ->toBe(fingerprints()->of(FP_NPWP));
});

it('reruns the backfill idempotently', function (): void {
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office, ['nik' => FP_NIK]);

    $this->artisan('parties:rebuild-identity-fingerprints')->assertSuccessful();
    $first = DB::table('individuals')->where('party_id', $individual->party_id)->value('nik_fingerprint');

    $this->artisan('parties:rebuild-identity-fingerprints')->assertSuccessful();
    $second = DB::table('individuals')->where('party_id', $individual->party_id)->value('nik_fingerprint');

    expect($second)->toBe($first);
});

it('leaves a null identifier unfingerprinted during backfill', function (): void {
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office, ['nik' => null, 'npwp' => null]);

    $this->artisan('parties:rebuild-identity-fingerprints')->assertSuccessful();

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->first();

    expect($stored->nik_fingerprint)->toBeNull()
        ->and($stored->npwp_fingerprint)->toBeNull();
});

it('prints counts and never an identifier', function (): void {
    // A maintenance command that echoed what it was working on would put the
    // whole directory's identity data into somebody's scrollback and CI output.
    $office = Office::factory()->create();
    makeIndividualIn($office, ['nik' => FP_NIK, 'npwp' => FP_NPWP]);
    makeCompanyIn($office, ['tax_id' => FP_NPWP]);

    // `Artisan::call` rather than the test helper, because the helper's fluent
    // assertions consume the buffer and this test is specifically about what
    // reaches the terminal.
    expect(Artisan::call('parties:rebuild-identity-fingerprints'))->toBe(0);

    $output = Artisan::output();

    expect($output)->not->toContain(FP_NIK)
        ->and($output)->not->toContain(FP_NPWP)
        ->and($output)->not->toContain(fingerprints()->of(FP_NIK))
        ->and($output)->not->toContain('fingerprint_')
        ->and($output)->toContain('Scanned');
});

it('re-encrypts nothing while rebuilding', function (): void {
    // The raw value is read and never rewritten: only the derived column moves.
    $office = Office::factory()->create();
    $individual = makeIndividualIn($office, ['nik' => FP_NIK]);

    $ciphertextBefore = DB::table('individuals')->where('party_id', $individual->party_id)->value('nik');

    $this->artisan('parties:rebuild-identity-fingerprints')->assertSuccessful();

    expect(DB::table('individuals')->where('party_id', $individual->party_id)->value('nik'))
        ->toBe($ciphertextBefore);
});
