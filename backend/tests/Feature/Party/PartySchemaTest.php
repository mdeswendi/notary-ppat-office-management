<?php

use App\Domains\Party\Enums\CompanyEntityType;
use App\Domains\Party\Enums\PartyType;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Aggregate shape
|--------------------------------------------------------------------------
*/

it('gives a party a generated ULID primary key', function (): void {
    $party = Party::factory()->create();

    expect($party->getKeyType())->toBe('string')
        ->and($party->getIncrementing())->toBeFalse()
        ->and(strlen($party->id))->toBe(26)
        ->and(Str::isUlid($party->id))->toBeTrue();
});

it('requires a party to belong to an office', function (): void {
    expect(fn () => Party::factory()->create(['office_id' => null]))
        ->toThrow(QueryException::class);
});

it('rejects a party pointing at a nonexistent office', function (): void {
    expect(fn () => Party::factory()->create(['office_id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
});

it('refuses to delete an office that still has parties', function (): void {
    // RESTRICT, matching users.office_id: removing an Office must not silently
    // take its directory with it.
    $office = Office::factory()->create();
    Party::factory()->for($office)->create();

    expect(fn () => $office->delete())->toThrow(QueryException::class);
});

it('stores party_type as a stable code, never a translated label', function (): void {
    $party = Party::factory()->individual()->create();

    expect(DB::table('parties')->where('id', $party->id)->value('party_type'))
        ->toBe('INDIVIDUAL')
        ->and($party->fresh()->party_type)->toBe(PartyType::INDIVIDUAL);
});

it('rejects an invalid party_type through the model cast', function (): void {
    // A translated label is exactly the wrong thing to store (CLAUDE.md section
    // 12), and the enum cast refuses it before it can reach SQL.
    expect(fn () => Party::factory()->create(['party_type' => 'PERORANGAN']))
        ->toThrow(ValueError::class);
});

/*
|--------------------------------------------------------------------------
| Subtype invariants — what the DATABASE enforces
|--------------------------------------------------------------------------
*/

it('creates a valid Individual aggregate', function (): void {
    $individual = Individual::factory()->create();

    expect($individual->party)->not->toBeNull()
        ->and($individual->party->party_type)->toBe(PartyType::INDIVIDUAL)
        ->and($individual->getKey())->toBe($individual->party->getKey());
});

it('creates a valid Company aggregate', function (): void {
    $company = Company::factory()->create();

    expect($company->party->party_type)->toBe(PartyType::COMPANY)
        ->and($company->entity_type)->toBe(CompanyEntityType::PT);
});

it('rejects a subtype row whose parent party does not exist', function (): void {
    expect(fn () => DB::table('individuals')->insert([
        'party_id' => (string) Str::ulid(),
        'party_type' => 'INDIVIDUAL',
        'full_name' => 'Orphan',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects an Individual row attached to a COMPANY party', function (): void {
    // The composite foreign key (party_id, party_type) -> parties (id, party_type)
    // makes the subtype and its Party's type agree, in the database.
    $party = Party::factory()->company()->create();

    expect(fn () => DB::table('individuals')->insert([
        'party_id' => $party->id,
        'party_type' => 'INDIVIDUAL',
        'full_name' => 'Wrong subtype',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a Company row attached to an INDIVIDUAL party', function (): void {
    $party = Party::factory()->individual()->create();

    expect(fn () => DB::table('companies')->insert([
        'party_id' => $party->id,
        'party_type' => 'COMPANY',
        'legal_name' => 'Wrong subtype',
        'entity_type' => 'PT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('makes one party holding both subtypes impossible', function (): void {
    // This is the invariant PK/FK alone does NOT give. It holds because each
    // subtype pins party_type, and a Party has exactly one.
    $individual = Individual::factory()->create();

    expect(fn () => DB::table('companies')->insert([
        'party_id' => $individual->party_id,
        'party_type' => 'COMPANY',
        'legal_name' => 'Second subtype',
        'entity_type' => 'PT',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('makes a duplicate subtype row for one party impossible', function (): void {
    $individual = Individual::factory()->create();

    expect(fn () => DB::table('individuals')->insert([
        'party_id' => $individual->party_id,
        'party_type' => 'INDIVIDUAL',
        'full_name' => 'Duplicate',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| party_type immutability — enforced twice
|--------------------------------------------------------------------------
*/

it('refuses a party_type change at the model', function (): void {
    $party = Party::factory()->individual()->create();

    $party->party_type = PartyType::COMPANY;

    expect(fn () => $party->save())->toThrow(RuntimeException::class);
});

it('refuses a party_type change at the database while a subtype exists', function (): void {
    // Belt and braces: even a raw UPDATE that bypasses the model is rejected,
    // because the subtype's composite foreign key would no longer resolve.
    $individual = Individual::factory()->create();

    expect(fn () => DB::table('parties')
        ->where('id', $individual->party_id)
        ->update(['party_type' => 'COMPANY']))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Archive authority
|--------------------------------------------------------------------------
*/

it('archives at the aggregate root', function (): void {
    $individual = Individual::factory()->create();
    $party = $individual->party;

    $party->delete();

    expect(Party::query()->whereKey($party->getKey())->exists())->toBeFalse()
        ->and(Party::withTrashed()->whereKey($party->getKey())->exists())->toBeTrue()
        ->and($party->fresh()->deleted_at)->not->toBeNull();
});

it('gives the subtype tables no independent archive state', function (): void {
    // One lifecycle authority (D-081). A subtype cannot be archived while its
    // Party stays active, because it has no deleted_at to set.
    expect(Schema::hasColumn('individuals', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('parties', 'deleted_at'))->toBeTrue();
});

it('carries no status column competing with deleted_at', function (): void {
    expect(Schema::hasColumn('parties', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'status'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| What must not exist
|--------------------------------------------------------------------------
*/

it('introduces no Client persistence', function (): void {
    expect(Schema::hasTable('clients'))->toBeFalse()
        ->and(Schema::hasColumn('parties', 'client_id'))->toBeFalse()
        // A string, not `Client::class`: the latter gets rewritten into an
        // import of something that must never exist (D-078).
        ->and(class_exists('App\\Models\\Client'))->toBeFalse()
        ->and(file_exists(app_path('Models/Client.php')))->toBeFalse();
});

it('adds no tenancy or office pivot to Party', function (): void {
    expect(Schema::hasColumn('parties', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('parties', 'organization_id'))->toBeFalse()
        ->and(Schema::hasTable('party_offices'))->toBeFalse();
});

it('introduces no M3 relation', function (): void {
    foreach (['projects', 'matters', 'documents', 'party_documents', 'properties'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    foreach (['project_id', 'matter_id', 'document_id'] as $column) {
        expect(Schema::hasColumn('parties', $column))->toBeFalse();
    }
});

it('duplicates no contact field onto the company subtype', function (): void {
    expect(Schema::hasColumn('companies', 'phone'))->toBeFalse()
        ->and(Schema::hasColumn('companies', 'email'))->toBeFalse()
        ->and(Schema::hasColumn('parties', 'primary_phone'))->toBeTrue()
        ->and(Schema::hasColumn('parties', 'primary_email'))->toBeTrue();
});

it('adds no duplicate-detection fingerprint column', function (): void {
    // Duplicate detection is M2.5. Locking a fingerprint design now would fix
    // cryptographic architecture before it has been reviewed (D-084).
    foreach (['nik_hash', 'npwp_hash', 'search_hash', 'nik_fingerprint'] as $column) {
        expect(Schema::hasColumn('individuals', $column))->toBeFalse();
    }

    expect(Schema::hasColumn('companies', 'tax_id_hash'))->toBeFalse();
});
