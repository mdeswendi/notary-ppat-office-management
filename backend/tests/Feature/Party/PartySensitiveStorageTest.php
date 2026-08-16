<?php

use App\Models\Company;
use App\Models\Individual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Encrypted at rest — D-082
|--------------------------------------------------------------------------
*/

it('encrypts an individual NIK at rest', function (): void {
    $individual = Individual::factory()->withIdentity('3174012345678901')->create();

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->value('nik');

    // A database dump must yield ciphertext, not identity documents.
    expect($stored)->not->toBe('3174012345678901')
        ->and($stored)->not->toContain('3174012345678901')
        ->and($individual->fresh()->nik)->toBe('3174012345678901');
});

it('encrypts an individual NPWP at rest', function (): void {
    $individual = Individual::factory()->withIdentity(null, '091234567890123')->create();

    $stored = DB::table('individuals')->where('party_id', $individual->party_id)->value('npwp');

    expect($stored)->not->toContain('091234567890123')
        ->and($individual->fresh()->npwp)->toBe('091234567890123');
});

it('encrypts a company tax identifier at rest', function (): void {
    // A corporate tax identifier is no less sensitive for belonging to an
    // organization.
    $company = Company::factory()->withTaxId('012345678901234')->create();

    $stored = DB::table('companies')->where('party_id', $company->party_id)->value('tax_id');

    expect($stored)->not->toContain('012345678901234')
        ->and($company->fresh()->tax_id)->toBe('012345678901234');
});

it('keeps a null sensitive value null', function (): void {
    $individual = Individual::factory()->create();
    $company = Company::factory()->create();

    expect($individual->fresh()->nik)->toBeNull()
        ->and($individual->fresh()->npwp)->toBeNull()
        ->and($company->fresh()->tax_id)->toBeNull()
        ->and(DB::table('individuals')->where('party_id', $individual->party_id)->value('nik'))->toBeNull();
});

it('produces different ciphertext for the same plaintext', function (): void {
    // Randomized encryption, which is why no index or UNIQUE constraint could
    // usefully sit on these columns even if one were wanted (D-084).
    $first = Individual::factory()->withIdentity('3174012345678901')->create();
    $second = Individual::factory()->withIdentity('3174012345678901')->create();

    $a = DB::table('individuals')->where('party_id', $first->party_id)->value('nik');
    $b = DB::table('individuals')->where('party_id', $second->party_id)->value('nik');

    expect($a)->not->toBe($b);
});

/*
|--------------------------------------------------------------------------
| Ordinary serialization never carries a raw identifier
|--------------------------------------------------------------------------
*/

it('hides raw identity from ordinary individual serialization', function (): void {
    // The moment a raw identifier enters toArray(), it is in a log line, a cache
    // entry, a debug dump, and any response that serialized the model without
    // thinking. Frontend masking is not a defence (D-082).
    $individual = Individual::factory()->withIdentity('3174012345678901', '091234567890123')->create();

    $array = $individual->fresh()->toArray();
    $json = $individual->fresh()->toJson();

    expect($array)->not->toHaveKey('nik')
        ->and($array)->not->toHaveKey('npwp')
        ->and($json)->not->toContain('3174012345678901')
        ->and($json)->not->toContain('091234567890123');
});

it('hides raw tax identity from ordinary company serialization', function (): void {
    $company = Company::factory()->withTaxId('012345678901234')->create();

    $array = $company->fresh()->toArray();

    expect($array)->not->toHaveKey('tax_id')
        ->and($company->fresh()->toJson())->not->toContain('012345678901234');
});

it('hides the pinned party_type column from subtype serialization', function (): void {
    // It is a constraint mechanism, not data anybody should read from an API.
    expect(Individual::factory()->create()->fresh()->toArray())->not->toHaveKey('party_type')
        ->and(Company::factory()->create()->fresh()->toArray())->not->toHaveKey('party_type');
});

it('still lets authorized backend code read the raw value deliberately', function (): void {
    // Hiding is about accidental serialization, not about making the value
    // unreachable — a future authorized Resource reads the attribute explicitly.
    $individual = Individual::factory()->withIdentity('3174012345678901')->create();

    expect($individual->fresh()->nik)->toBe('3174012345678901')
        ->and($individual->fresh()->getAttribute('nik'))->toBe('3174012345678901');
});
