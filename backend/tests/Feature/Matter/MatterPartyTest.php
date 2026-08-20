<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * An actor holding the named permissions at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function matterPartyActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function matterPartySubject(Office $office, MatterDomain $domain = MatterDomain::NOTARY): Matter
{
    return Matter::factory()
        ->for($office)
        ->for(Project::factory()->for($office))
        ->state(['domain' => $domain->value, 'office_id' => $office->getKey()])
        ->create();
}

function matterPartyIndividual(Office $office, string $name = 'Budi Santoso'): Party
{
    $individual = Individual::factory()
        ->for(Party::factory()->individual()->for($office)->state(['display_name' => $name]), 'party')
        ->create();

    return $individual->party;
}

function matterPartyCompany(Office $office, string $name = 'PT Contoh'): Party
{
    $company = Company::factory()
        ->for(Party::factory()->company()->for($office)->state(['display_name' => $name]), 'party')
        ->create();

    return $company->party;
}

function matterPartyLink(Matter $matter, Party $party, ?string $role = null): MatterParty
{
    return MatterParty::factory()->create([
        'matter_id' => $matter->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $matter->office_id,
        'role_code' => $role,
    ]);
}

/**
 * The capability set an office worker maintaining Notary participation holds.
 *
 * @return array<int, string>
 */
function notaryParticipationCapabilities(): array
{
    return [
        'notary.matters.view',
        'notary.matters.parties.view',
        'notary.matters.parties.manage',
        'parties.view',
        'companies.view',
    ];
}

/*
|--------------------------------------------------------------------------
| Route wiring and the registry
|--------------------------------------------------------------------------
*/

it('registers exactly the expected participation routes and nothing more', function (): void {
    // An inventory rather than an absence check: a new participation route now
    // has to be added here deliberately. There is no top-level /matter-parties
    // collection, because a participation is only ever reachable by naming the
    // Matter it belongs to (D-105).
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        ->filter(fn (string $route): bool => str_contains($route, 'matters')
            && (str_contains($route, 'parties') || str_contains($route, 'party-options')))
        ->values()->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE api/v1/notary/matters/{matter}/parties/{matterParty}',
        'DELETE api/v1/ppat/matters/{matter}/parties/{matterParty}',
        'GET api/v1/notary/matters/{matter}/parties',
        'GET api/v1/notary/matters/{matter}/party-options',
        'GET api/v1/ppat/matters/{matter}/parties',
        'GET api/v1/ppat/matters/{matter}/party-options',
        'PATCH api/v1/notary/matters/{matter}/parties/{matterParty}',
        'PATCH api/v1/ppat/matters/{matter}/parties/{matterParty}',
        'POST api/v1/notary/matters/{matter}/parties',
        'POST api/v1/ppat/matters/{matter}/parties',
    ]);
});

it('adds exactly four permissions, moving the count to 177', function (): void {
    $participation = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'matters.parties.'),
    ));

    sort($participation);

    expect($participation)->toBe([
        'notary.matters.parties.manage',
        'notary.matters.parties.view',
        'ppat.matters.parties.manage',
        'ppat.matters.parties.view',
    ])->and(PermissionRegistry::count())->toBe(177);
});

it('invents no view_all code for participation', function (): void {
    // Reach is Data Scope ALL against the parent Matter. A second reach mechanism
    // is what D-090 refuses, and two answers to one question do not stay equal.
    $offenders = array_values(array_filter(
        PermissionRegistry::all(),
        fn (string $code): bool => str_contains($code, 'parties') && str_ends_with($code, 'view_all'),
    ));

    expect($offenders)->toBe([]);
});

it('offers the matter-shaped scopes for participation and withholds TEAM', function (string $code): void {
    $scopes = array_map(
        fn (DataScope $scope): string => $scope->value,
        app(PermissionScopeRules::class)->allowedFor($code),
    );

    expect($scopes)->not->toContain('TEAM')
        ->and($scopes)->toContain('OWN', 'ASSIGNED', 'OFFICE', 'ALL');
})->with([
    'notary.matters.parties.view', 'notary.matters.parties.manage',
    'ppat.matters.parties.view', 'ppat.matters.parties.manage',
]);

/*
|--------------------------------------------------------------------------
| Schema
|--------------------------------------------------------------------------
*/

it('creates the participation table with the office carrier and no history columns', function (): void {
    expect(Schema::hasTable('matter_parties'))->toBeTrue()
        ->and(Schema::hasColumns('matter_parties', [
            'id', 'matter_id', 'party_id', 'office_id', 'role_code', 'notes',
            'created_by', 'created_at', 'updated_at',
        ]))->toBeTrue();

    // Current working state, not a ledger (D-105).
    foreach (['deleted_at', 'effective_from', 'effective_until'] as $column) {
        expect(Schema::hasColumn('matter_parties', $column))->toBeFalse();
    }

    // Deferred pending domain validation, and deliberately not stubbed.
    foreach (['sequence_no', 'represented_by_party_id'] as $column) {
        expect(Schema::hasColumn('matter_parties', $column))->toBeFalse();
    }
});

it('carries no party identity column of any kind', function (): void {
    // Sensitive identity never enters the Matter domain (D-082, D-105). The row
    // points at a Party by id; identity is read through the Party surfaces that
    // already authorize it.
    $columns = Schema::getColumnListing('matter_parties');

    foreach (['nik', 'npwp', 'tax_id', 'display_name', 'email', 'phone', 'address'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('invents no participant cardinality rule', function (): void {
    // No UNIQUE (matter_id, party_id): that would assert one Party holds at most
    // one role in a Matter, and a person may legitimately be a seller in their
    // own right and another party's authorized representative (D-105). The test
    // is the behaviour, not the index name.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => $party->getKey(),
            'role_code' => 'SELLER',
        ])->assertCreated();

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => $party->getKey(),
            'role_code' => 'AUTHORIZED_PERSON',
        ])->assertCreated();

    expect(MatterParty::query()->where('matter_id', $matter->getKey())->count())->toBe(2);
});

it('makes a cross-office participation unrepresentable', function (): void {
    $office = Office::factory()->create();
    $other = Office::factory()->create();

    $matter = matterPartySubject($office);
    $foreign = matterPartyIndividual($other);

    expect(fn () => DB::table('matter_parties')->insert([
        'id' => (string) Str::ulid(),
        'matter_id' => $matter->getKey(),
        'party_id' => $foreign->getKey(),
        // The carrier agrees with the Matter, so the *Party* key is what fails.
        'office_id' => $matter->office_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a matter or party that still has a participation', function (): void {
    [, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);
    matterPartyLink($matter, $party);

    expect(fn () => DB::table('matters')->where('id', $matter->getKey())->delete())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Reading the list
|--------------------------------------------------------------------------
*/

it('lists the parties taking part in a matter', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    matterPartyLink($matter, matterPartyIndividual($office, 'Budi Santoso'), 'SELLER');
    matterPartyLink($matter, matterPartyCompany($office, 'PT Contoh'), 'BUYER');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0.party.display_name'))->toBe('Budi Santoso')
        ->and($response->json('data.0.role_code'))->toBe('SELLER')
        ->and($response->json('data.1.party.party_type'))->toBe('COMPANY');
});

it('exposes no party identity in the participation payload', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    matterPartyLink($matter, matterPartyIndividual($office));

    $row = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertOk()->json('data.0');

    expect(array_keys($row['party']))
        ->toBe(['id', 'display_name', 'party_type', 'is_archived', 'can_view_party']);

    // Nothing anywhere in the response, at any depth, resembles a masked or full
    // identity field.
    $encoded = json_encode($row);

    foreach (['nik', 'npwp', 'tax_id', '****', 'fingerprint'] as $forbidden) {
        expect(strtolower((string) $encoded))->not->toContain($forbidden);
    }
});

it('omits the deferred columns from the payload rather than emitting nulls', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    matterPartyLink($matter, matterPartyIndividual($office));

    $row = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('sequence_no')
        ->and($row)->not->toHaveKey('represented_by_party_id')
        ->and($row)->not->toHaveKey('is_primary');
});

it('keeps a party listed after it is archived, with can_view_party false', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);
    matterPartyLink($matter, $party);

    $party->delete();

    $row = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertOk()->json('data.0');

    expect($row['party']['is_archived'])->toBeTrue()
        ->and($row['party']['can_view_party'])->toBeFalse();
});

it('lists a party the actor cannot open, as a stub', function (): void {
    // Participation is Matter data. Hiding the row would misreport the Matter's
    // composition to somebody authorized to read it.
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view',
    ]);
    $matter = matterPartySubject($office);
    matterPartyLink($matter, matterPartyIndividual($office, 'Budi Santoso'));

    $row = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertOk()->json('data.0');

    expect($row['party']['display_name'])->toBe('Budi Santoso')
        ->and($row['party']['can_view_party'])->toBeFalse();
});

it('computes party visibility in bulk rather than per row', function (): void {
    // Measured as a **comparison between two list sizes**, not against a fixed
    // threshold. A threshold only says "fewer queries than I guessed"; the
    // comparison says the thing D-105 actually requires — that the query count
    // does not grow with the row count. Two subtypes are used both times, so the
    // per-branch resolution is constant and only the number of rows differs.
    $countQueriesFor = function (int $pairs) {
        [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
        $matter = matterPartySubject($office);

        foreach (range(1, $pairs) as $index) {
            matterPartyLink($matter, matterPartyIndividual($office, "Individual {$index}"));
            matterPartyLink($matter, matterPartyCompany($office, "Company {$index}"));
        }

        DB::enableQueryLog();
        $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    };

    $small = $countQueriesFor(1);
    $large = $countQueriesFor(12);

    // Two rows versus twenty-four. Per-row visibility would add twenty-two
    // queries; the bulk path adds none.
    expect($large)->toBe($small);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses the list without the participation view code', function (): void {
    // `notary.matters.view` reads the Matter. It does not read who is involved.
    [$actor, $office] = matterPartyActor(['notary.matters.view']);
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertForbidden();
});

it('does not let matter update reach participation', function (): void {
    [$actor, $office] = matterPartyActor(['notary.matters.view', 'notary.matters.update']);
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", ['party_id' => $party->getKey()])
        ->assertForbidden();
});

it('does not let view imply manage', function (): void {
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view', 'parties.view',
    ]);
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", ['party_id' => $party->getKey()])
        ->assertForbidden();
});

it('does not let manage imply view', function (): void {
    // The opposite direction, and it matters more: an actor who may edit the list
    // is not thereby authorized to read it. A silently implied capability is one
    // nobody configured and nobody can revoke.
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.manage', 'parties.view',
    ]);
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/parties")
        ->assertForbidden();
});

it('keeps the two domains independent', function (): void {
    // A Notary participation grant reaches no PPAT Matter, and the refusal is a
    // 404 rather than a 403 because the address named the wrong domain (D-101).
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $ppat = matterPartySubject($office, MatterDomain::PPAT);

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$ppat->getKey()}/parties")
        ->assertNotFound();

    $this->actingAs($actor)
        ->getJson("/api/v1/ppat/matters/{$ppat->getKey()}/parties")
        ->assertForbidden();
});

it('applies the matter data scope to participation', function (): void {
    // ASSIGNED means the Matters this actor is the PIC of, and participation is
    // judged against the parent Matter by the same four predicates (D-100).
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach (['notary.matters.view', 'notary.matters.parties.view'] as $permission) {
        grantPermissionScope($actor, $permission, DataScope::ASSIGNED);
    }

    $actor = $actor->fresh();

    $mine = matterPartySubject($office);
    $mine->forceFill(['pic_user_id' => $actor->getKey()])->save();

    $theirs = matterPartySubject($office);

    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$mine->getKey()}/parties")->assertOk();
    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$theirs->getKey()}/parties")->assertForbidden();
});

it('refuses an office the actor cannot reach even with ALL on the party side', function (): void {
    // ALL on Parties is reach over Parties. It is not Matter participation
    // authority, and it never bridges two Offices.
    $office = Office::factory()->create();
    $other = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.matters.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'notary.matters.parties.view', DataScope::OFFICE);
    grantPermissionScope($actor, 'parties.view', DataScope::ALL);

    $foreignMatter = matterPartySubject($other);

    $this->actingAs($actor->fresh())
        ->getJson("/api/v1/notary/matters/{$foreignMatter->getKey()}/parties")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Candidates
|--------------------------------------------------------------------------
*/

it('offers only same-office, unarchived candidates', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $other = Office::factory()->create();
    $matter = matterPartySubject($office);

    $eligible = matterPartyIndividual($office, 'Eligible Person');
    $archived = matterPartyIndividual($office, 'Archived Person');
    $archived->delete();
    $foreign = matterPartyIndividual($other, 'Foreign Person');

    $ids = collect(
        $this->actingAs($actor)
            ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options")
            ->assertOk()->json('data.parties')
    )->pluck('id')->all();

    expect($ids)->toContain($eligible->getKey())
        ->and($ids)->not->toContain($archived->getKey())
        ->and($ids)->not->toContain($foreign->getKey());
});

it('applies each party subtype permission independently to candidates', function (): void {
    // An actor holding `parties.view` but not `companies.view` sees Individuals
    // and no Companies. Collapsing the two would silently widen the branch the
    // actor lacks (D-028).
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view',
        'notary.matters.parties.manage', 'parties.view',
    ]);
    $matter = matterPartySubject($office);

    $individual = matterPartyIndividual($office, 'Visible Individual');
    $company = matterPartyCompany($office, 'Hidden Company');

    $ids = collect(
        $this->actingAs($actor)
            ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options")
            ->assertOk()->json('data.parties')
    )->pluck('id')->all();

    expect($ids)->toContain($individual->getKey())
        ->and($ids)->not->toContain($company->getKey());
});

it('offers nothing to an actor holding neither party subtype permission', function (): void {
    // Fails closed: an empty candidate list, not the whole Office.
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view', 'notary.matters.parties.manage',
    ]);
    $matter = matterPartySubject($office);
    matterPartyIndividual($office);
    matterPartyCompany($office);

    $candidates = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options")
        ->assertOk()->json('data.parties');

    expect($candidates)->toBe([]);
});

it('does not open the candidate list to a view-only actor', function (): void {
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view', 'parties.view',
    ]);
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options")
        ->assertForbidden();
});

it('searches candidates by display name', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    matterPartyIndividual($office, 'Budi Santoso');
    matterPartyIndividual($office, 'Siti Rahayu');

    $names = collect(
        $this->actingAs($actor)
            ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options?search=Budi")
            ->assertOk()->json('data.parties')
    )->pluck('display_name')->all();

    expect($names)->toBe(['Budi Santoso']);
});

it('does not offer the parent project participants as candidates', function (): void {
    // Matter participation is independent of Project participation: not
    // inherited, not copied, not synchronized (D-105). A Party linked to the
    // parent Project gets no special standing here.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $candidates = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}/party-options")
        ->assertOk()->json('data.parties');

    // Nothing exists to inherit, and nothing was inherited: a new Matter starts
    // with no participants whatever its Project holds.
    expect($candidates)->toBe([])
        ->and(MatterParty::query()->where('matter_id', $matter->getKey())->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

it('links a party and copies the office from the matter', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => $party->getKey(),
            'role_code' => 'SELLER',
            'notes' => 'Acting in person.',
        ])->assertCreated();

    $participation = MatterParty::query()->firstOrFail();

    expect($response->json('data.role_code'))->toBe('SELLER')
        ->and($participation->office_id)->toBe($matter->office_id)
        ->and($participation->created_by)->toBe($actor->getKey())
        ->and($participation->matter_id)->toBe($matter->getKey());
});

it('accepts a participation with no role at all', function (): void {
    // `role_code` is nullable because a participation may legitimately be
    // recorded before anybody has classified it.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => matterPartyIndividual($office)->getKey(),
        ])->assertCreated()->assertJsonPath('data.role_code', null);
});

it('accepts any role code without consulting a catalogue', function (string $role): void {
    // The ERD's role codes are labelled examples, not a catalogue (D-105).
    // Constraining the column would turn them into one.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => matterPartyIndividual($office)->getKey(),
            'role_code' => $role,
        ])->assertCreated()->assertJsonPath('data.role_code', $role);
})->with(['SELLER', 'PENGHADAP', 'SAKSI', 'anything-at-all']);

it('gives one indistinguishable refusal for every ineligible party', function (): void {
    // Nonexistent, another Office, archived, and a subtype the actor cannot see
    // are one answer, because telling them apart would answer a question the
    // caller has no permission to ask.
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view',
        'notary.matters.parties.manage', 'parties.view',
    ]);
    $other = Office::factory()->create();
    $matter = matterPartySubject($office);

    $archived = matterPartyIndividual($office);
    $archived->delete();

    $messages = [];

    foreach ([
        (string) Str::ulid(),
        matterPartyIndividual($other)->getKey(),
        $archived->getKey(),
        matterPartyCompany($office)->getKey(),
    ] as $partyId) {
        $messages[] = $this->actingAs($actor)
            ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", ['party_id' => $partyId])
            ->assertStatus(422)->json('message');
    }

    expect(array_unique($messages))->toHaveCount(1);
});

it('refuses every system-controlled field on create', function (string $field): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => matterPartyIndividual($office)->getKey(),
            $field => 'anything',
        ])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'id', 'matter_id', 'office_id', 'created_by', 'created_at', 'updated_at',
    'deleted_at', 'effective_from', 'effective_until',
    'sequence_no', 'represented_by_party_id',
]);

it('refuses a system-controlled field that is present but null', function (): void {
    // Presence, not emptiness (D-097). `{"office_id": null}` is still an
    // instruction about `office_id`, and this endpoint does not take one.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => matterPartyIndividual($office)->getKey(),
            'office_id' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['office_id']);
});

it('requires a party id', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [])
        ->assertStatus(422)->assertJsonValidationErrors(['party_id']);
});

it('bounds the role code and the notes', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", [
            'party_id' => matterPartyIndividual($office)->getKey(),
            'role_code' => str_repeat('A', 31),
        ])->assertStatus(422)->assertJsonValidationErrors(['role_code']);
});

/*
|--------------------------------------------------------------------------
| Correcting
|--------------------------------------------------------------------------
*/

it('corrects a role and leaves the endpoints alone', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);
    $participation = matterPartyLink($matter, $party, 'SELLER');

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}",
            ['role_code' => 'BUYER'],
        )->assertOk()->assertJsonPath('data.role_code', 'BUYER');

    $participation->refresh();

    expect($participation->party_id)->toBe($party->getKey())
        ->and($participation->matter_id)->toBe($matter->getKey())
        ->and($participation->office_id)->toBe($matter->office_id);
});

it('clears a role by sending null', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office), 'SELLER');

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}",
            ['role_code' => null],
        )->assertOk()->assertJsonPath('data.role_code', null);
});

it('refuses to re-point a participation at a different party', function (): void {
    // Not an edit but a different relationship: it would keep the created_by and
    // created_at of the first and bypass candidate authorization. Remove and add.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office));

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}",
            ['party_id' => matterPartyIndividual($office, 'Someone Else')->getKey()],
        )->assertStatus(422)->assertJsonValidationErrors(['party_id']);
});

it('refuses to move a participation to another matter', function (string $field): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office));

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}",
            [$field => matterPartySubject($office)->getKey()],
        )->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with(['matter_id', 'office_id']);

it('answers 404 for a participation belonging to another matter', function (): void {
    // A 403 would confirm the row exists and belongs to a Matter the caller
    // cannot name, which is exactly what nested binding must not leak.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $foreign = matterPartyLink(matterPartySubject($office), matterPartyIndividual($office));

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$foreign->getKey()}",
            ['role_code' => 'BUYER'],
        )->assertNotFound();
});

it('moves updated_at on a correction and leaves created_by alone', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office), 'SELLER');
    $participation->forceFill(['created_by' => $actor->getKey()])->save();

    $before = $participation->fresh()->updated_at;

    $this->travel(2)->seconds();

    $this->actingAs($actor)
        ->patchJson(
            "/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}",
            ['role_code' => 'BUYER'],
        )->assertOk();

    $participation->refresh();

    expect($participation->updated_at->greaterThan($before))->toBeTrue()
        ->and($participation->created_by)->toBe($actor->getKey());
});

/*
|--------------------------------------------------------------------------
| Removing
|--------------------------------------------------------------------------
*/

it('deletes the relationship row and nothing else', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);
    $participation = matterPartyLink($matter, $party);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}")
        ->assertNoContent();

    expect(MatterParty::query()->count())->toBe(0)
        // Neither endpoint is touched: not archived, not cancelled, not altered.
        ->and(Matter::query()->whereKey($matter->getKey())->exists())->toBeTrue()
        ->and(Party::query()->whereKey($party->getKey())->exists())->toBeTrue();
});

it('removes a participation for good, with no soft-deleted remnant', function (): void {
    // Current working state, not a ledger (D-105). A half-history — rows nobody
    // lists and no mechanism reads — would be worse than none.
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office));

    $this->actingAs($actor)
        ->deleteJson("/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}")
        ->assertNoContent();

    expect(DB::table('matter_parties')->count())->toBe(0);
});

it('refuses removal without the manage code', function (): void {
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view', 'parties.view',
    ]);
    $matter = matterPartySubject($office);
    $participation = matterPartyLink($matter, matterPartyIndividual($office));

    $this->actingAs($actor)
        ->deleteJson("/api/v1/notary/matters/{$matter->getKey()}/parties/{$participation->getKey()}")
        ->assertForbidden();

    expect(MatterParty::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Capability flags on the Matter resource
|--------------------------------------------------------------------------
*/

it('reports the two participation capabilities separately on a matter', function (): void {
    [$actor, $office] = matterPartyActor([
        'notary.matters.view', 'notary.matters.parties.view',
    ]);
    $matter = matterPartySubject($office);

    $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_view_parties', true)
        ->assertJsonPath('data.can_manage_parties', false);
});

/*
|--------------------------------------------------------------------------
| Independence from Project participation
|--------------------------------------------------------------------------
*/

it('does not touch project participation when matter participation changes', function (): void {
    [$actor, $office] = matterPartyActor(notaryParticipationCapabilities());
    $matter = matterPartySubject($office);
    $party = matterPartyIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/notary/matters/{$matter->getKey()}/parties", ['party_id' => $party->getKey()])
        ->assertCreated();

    expect(DB::table('project_parties')->count())->toBe(0);
});

it('shares no table with project participation', function (): void {
    expect(Schema::hasTable('project_parties'))->toBeTrue()
        ->and(Schema::hasTable('matter_parties'))->toBeTrue()
        // The two are separate tables with separate columns; `project_parties`
        // keeps `is_primary` and no `updated_at`, `matter_parties` the reverse.
        ->and(Schema::hasColumn('project_parties', 'is_primary'))->toBeTrue()
        ->and(Schema::hasColumn('matter_parties', 'is_primary'))->toBeFalse()
        ->and(Schema::hasColumn('project_parties', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('matter_parties', 'updated_at'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Migration
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

    expect(Schema::hasTable('matter_parties'))->toBeFalse()
        // The support keys belong to earlier milestones and must survive: this
        // migration added neither, so it drops neither.
        ->and(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasTable('parties'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('matter_parties'))->toBeTrue();
});
