<?php

use App\Domains\Party\Enums\CompanyRelationshipCategory;
use App\Domains\Party\Enums\CompanyRelationshipType;
use App\Models\Company;
use App\Models\CompanyPerson;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A Company and an Individual in the same Office, ready to be related.
 *
 * @return array{0: Company, 1: Individual, 2: Office}
 */
function relatablePair(?Office $office = null): array
{
    $office = $office ?? Office::factory()->create();

    $company = Company::factory()->for(Party::factory()->company()->for($office), 'party')->create();
    $individual = Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();

    return [$company, $individual, $office];
}

/**
 * Insert a relationship row directly, so the database rules are what is tested
 * rather than an application guard.
 */
function relate(Company $company, Individual $individual, string $officeId, array $overrides = []): void
{
    DB::table('company_people')->insert(array_merge([
        'id' => (string) Str::ulid(),
        'company_party_id' => $company->party_id,
        'individual_party_id' => $individual->party_id,
        'office_id' => $officeId,
        'relationship_type' => 'DIRECTOR',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/*
|--------------------------------------------------------------------------
| Endpoints must be the right subtypes
|--------------------------------------------------------------------------
*/

it('accepts a company-to-individual relationship in one office', function (): void {
    [$company, $individual, $office] = relatablePair();

    relate($company, $individual, $office->getKey());

    expect(DB::table('company_people')->count())->toBe(1);
});

it('rejects a relationship whose company endpoint is not a Company', function (): void {
    [$company, $individual, $office] = relatablePair();
    $otherIndividual = Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();

    // Pointing the company endpoint at an Individual: the FK targets
    // companies.party_id, so there is nothing to resolve.
    expect(fn () => DB::table('company_people')->insert([
        'id' => (string) Str::ulid(),
        'company_party_id' => $otherIndividual->party_id,
        'individual_party_id' => $individual->party_id,
        'office_id' => $office->getKey(),
        'relationship_type' => 'DIRECTOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a relationship whose individual endpoint is not an Individual', function (): void {
    [$company, $individual, $office] = relatablePair();
    $otherCompany = Company::factory()->for(Party::factory()->company()->for($office), 'party')->create();

    expect(fn () => DB::table('company_people')->insert([
        'id' => (string) Str::ulid(),
        'company_party_id' => $company->party_id,
        'individual_party_id' => $otherCompany->party_id,
        'office_id' => $office->getKey(),
        'relationship_type' => 'DIRECTOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a relationship pointing at a party that does not exist', function (): void {
    [$company, , $office] = relatablePair();

    expect(fn () => DB::table('company_people')->insert([
        'id' => (string) Str::ulid(),
        'company_party_id' => $company->party_id,
        'individual_party_id' => (string) Str::ulid(),
        'office_id' => $office->getKey(),
        'relationship_type' => 'DIRECTOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Same-office invariant — DATABASE enforced (D-080)
|--------------------------------------------------------------------------
*/

it('rejects a relationship bridging two offices', function (): void {
    // Both composite foreign keys reference parties (id, office_id) through the
    // SAME office_id column, so the two endpoints must agree with it and
    // therefore with each other. A cross-office relationship is unrepresentable.
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();

    $company = Company::factory()->for(Party::factory()->company()->for($officeA), 'party')->create();
    $individual = Individual::factory()->for(Party::factory()->individual()->for($officeB), 'party')->create();

    expect(fn () => relate($company, $individual, $officeA->getKey()))
        ->toThrow(QueryException::class);

    expect(fn () => relate($company, $individual, $officeB->getKey()))
        ->toThrow(QueryException::class);

    expect(DB::table('company_people')->count())->toBe(0);
});

it('rejects a relationship whose office_id matches neither endpoint', function (): void {
    [$company, $individual] = relatablePair();
    $elsewhere = Office::factory()->create();

    expect(fn () => relate($company, $individual, $elsewhere->getKey()))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| History
|--------------------------------------------------------------------------
*/

it('keeps a superseded relationship alongside its successor', function (): void {
    // A director change ends the old row and inserts a new one. "Who was the
    // director in March" must stay answerable (D-083).
    [$company, $formerDirector, $office] = relatablePair();
    $newDirector = Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();

    relate($company, $formerDirector, $office->getKey(), [
        'effective_from' => '2026-01-01',
        'effective_until' => '2026-06-30',
    ]);

    relate($company, $newDirector, $office->getKey(), [
        'effective_from' => '2026-07-01',
        'effective_until' => null,
    ]);

    expect(CompanyPerson::query()->count())->toBe(2)
        ->and(CompanyPerson::query()->current()->count())->toBe(1)
        ->and(CompanyPerson::query()->current()->first()->individual_party_id)
        ->toBe($newDirector->party_id);
});

it('permits the same person to hold the same role again later', function (): void {
    // History, not duplication — which is why there is no unique constraint.
    [$company, $individual, $office] = relatablePair();

    relate($company, $individual, $office->getKey(), [
        'effective_from' => '2024-01-01', 'effective_until' => '2024-12-31',
    ]);
    relate($company, $individual, $office->getKey(), [
        'effective_from' => '2026-01-01', 'effective_until' => null,
    ]);

    expect(CompanyPerson::query()->count())->toBe(2);
});

it('derives current-ness from effective_until rather than a stored flag', function (): void {
    expect(Schema::hasColumn('company_people', 'is_current'))->toBeFalse()
        ->and(Schema::hasColumn('company_people', 'effective_until'))->toBeTrue();
});

it('stores no duplicated name on the relationship', function (): void {
    foreach (['company_name', 'individual_name', 'full_name', 'legal_name', 'nik', 'npwp'] as $column) {
        expect(Schema::hasColumn('company_people', $column))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| No invented corporate law — D-083
|--------------------------------------------------------------------------
*/

it('invents no director cardinality rule', function (): void {
    [$company, $first, $office] = relatablePair();
    $second = Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();

    relate($company, $first, $office->getKey());
    relate($company, $second, $office->getKey());

    // Two concurrent directors are accepted. Whether that is legally correct is
    // a domain question this milestone has no authority to answer.
    expect(CompanyPerson::query()->current()->count())->toBe(2);
});

it('invents no ownership total rule', function (): void {
    [$company, $first, $office] = relatablePair();
    $second = Individual::factory()->for(Party::factory()->individual()->for($office), 'party')->create();

    relate($company, $first, $office->getKey(), [
        'relationship_type' => 'SHAREHOLDER', 'ownership_percentage' => 80,
    ]);
    relate($company, $second, $office->getKey(), [
        'relationship_type' => 'SHAREHOLDER', 'ownership_percentage' => 80,
    ]);

    // 160% is accepted by the schema. Nothing here asserts shareholdings sum to
    // 100, because no canonical document says so.
    expect(CompanyPerson::query()->count())->toBe(2);
});

it('maps relationship types to their authorization category', function (): void {
    expect(CompanyRelationshipType::DIRECTOR->category())->toBe(CompanyRelationshipCategory::MANAGEMENT)
        ->and(CompanyRelationshipType::COMMISSIONER->category())->toBe(CompanyRelationshipCategory::MANAGEMENT)
        ->and(CompanyRelationshipType::AUTHORIZED_PERSON->category())->toBe(CompanyRelationshipCategory::MANAGEMENT)
        ->and(CompanyRelationshipType::SHAREHOLDER->category())->toBe(CompanyRelationshipCategory::OWNERSHIP)
        ->and(CompanyRelationshipType::BENEFICIAL_OWNER->category())->toBe(CompanyRelationshipCategory::OWNERSHIP);

    expect(CompanyRelationshipCategory::MANAGEMENT->viewPermission())->toBe('companies.management.view')
        ->and(CompanyRelationshipCategory::OWNERSHIP->viewPermission())->toBe('companies.shareholders.view');
});
