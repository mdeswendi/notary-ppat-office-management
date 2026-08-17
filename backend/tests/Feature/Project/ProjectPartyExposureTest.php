<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor who may read participation, with whichever Party-domain grants the
 * case under test needs.
 *
 * @param  array<string, DataScope>  $partyGrants
 * @return array{0: User, 1: Office}
 */
function participationReader(array $partyGrants = []): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'projects.parties.view', DataScope::OFFICE);

    foreach ($partyGrants as $permission => $scope) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function linkIndividual(Project $project, Office $office, string $name = 'Budi Santoso'): ProjectParty
{
    return ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateIndividual($office, $name)->party_id,
        'office_id' => $office->getKey(),
    ]);
}

/*
|--------------------------------------------------------------------------
| The Party stub is minimal
|--------------------------------------------------------------------------
*/

it('exposes only the safe Party fields', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    linkIndividual($project, $office);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk();

    expect(array_keys($response->json('data.0.party')))->toBe([
        'id', 'display_name', 'party_type', 'is_archived', 'can_view_party',
    ]);
});

it('carries no sensitive identity and no mask', function (): void {
    // `projects.parties.view` says who is involved. It says nothing about their
    // identity documents, and a mask is still a statement about one (D-082).
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    linkIndividual($project, $office);

    $body = strtolower($this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->getContent());

    foreach ([
        'nik', 'npwp', 'tax_id', 'fingerprint', 'masked',
        'birth', 'primary_phone', 'primary_email', 'address', 'postal',
    ] as $leak) {
        expect(str_contains($body, $leak))->toBeFalse();
    }
});

it('exposes the relationship fields the surface owns', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();

    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateIndividual($office)->party_id,
        'office_id' => $office->getKey(),
        'role_code' => 'CLIENT',
        'is_primary' => true,
        'notes' => 'Catatan.',
    ]);

    $row = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->json('data.0');

    expect($row['role_code'])->toBe('CLIENT')
        ->and($row['is_primary'])->toBeTrue()
        ->and($row['notes'])->toBe('Catatan.')
        ->and($row)->toHaveKey('created_at')
        // No constraint carrier and no internal keys on the wire.
        ->and($row)->not->toHaveKey('office_id')
        ->and($row)->not->toHaveKey('project_id')
        ->and($row)->not->toHaveKey('created_by');
});

/*
|--------------------------------------------------------------------------
| can_view_party
|--------------------------------------------------------------------------
*/

it('reports can_view_party true when the Party surfaces would open the record', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    $participation = linkIndividual($project, $office);

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('data.0.party.can_view_party', true);

    // The flag is not a guess: the Party endpoint agrees with it.
    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$participation->party_id}")
        ->assertOk();
});

it('reports can_view_party false without the Party-domain grant', function (): void {
    [$actor, $office] = participationReader();
    $project = Project::factory()->for($office)->create();
    $participation = linkIndividual($project, $office);

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('data.0.party.can_view_party', false);

    // Again, not a guess in the other direction either.
    $this->actingAs($actor)
        ->getJson("/api/v1/individuals/{$participation->party_id}")
        ->assertForbidden();
});

it('computes can_view_party per subtype rather than once for all Parties', function (): void {
    // The branches are independent: holding `parties.view` and not
    // `companies.view` means Individuals link and Companies do not.
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();

    linkIndividual($project, $office, 'Budi Santoso');

    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateCompany($office, 'PT Sejahtera')->party_id,
        'office_id' => $office->getKey(),
    ]);

    $rows = collect($this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->json('data'))
        ->keyBy(fn (array $row): string => $row['party']['display_name']);

    expect($rows['Budi Santoso']['party']['can_view_party'])->toBeTrue()
        ->and($rows['PT Sejahtera']['party']['can_view_party'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A linked Party is never withdrawn from the list
|--------------------------------------------------------------------------
*/

it('keeps listing a Party the actor cannot open', function (): void {
    // Hiding the row would misreport the Project's composition to somebody
    // authorized to read it — worse than declining to link onward (D-098).
    [$actor, $office] = participationReader();
    $project = Project::factory()->for($office)->create();
    linkIndividual($project, $office, 'Budi Santoso');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.party.display_name'))->toBe('Budi Santoso')
        ->and($response->json('data.0.party.can_view_party'))->toBeFalse();
});

it('keeps listing an archived Party as a minimal stub', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    $participation = linkIndividual($project, $office, 'Sudah Diarsipkan');

    $participation->party->delete();

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.party.display_name'))->toBe('Sudah Diarsipkan')
        ->and($response->json('data.0.party.is_archived'))->toBeTrue()
        // Not offered as a link: the Party surfaces would refuse it too.
        ->and($response->json('data.0.party.can_view_party'))->toBeFalse();
});

it('does not unlink a Party when it is archived', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    $participation = linkIndividual($project, $office);

    $participation->party->delete();

    expect(ProjectParty::query()->whereKey($participation->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ordering, capability flag, and query cost
|--------------------------------------------------------------------------
*/

it('leads with the primary participants', function (): void {
    [$actor, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();

    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateIndividual($office, 'Biasa')->party_id,
        'office_id' => $office->getKey(),
        'is_primary' => false,
    ]);
    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateIndividual($office, 'Utama')->party_id,
        'office_id' => $office->getKey(),
        'is_primary' => true,
    ]);

    $names = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->json('data.*.party.display_name');

    expect($names)->toBe(['Utama', 'Biasa']);
});

it('advertises can_manage from the real Policy', function (): void {
    [$reader, $office] = participationReader(['parties.view' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    linkIndividual($project, $office);

    $this->actingAs($reader)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('meta.can_manage', false)
        ->assertJsonPath('data.0.can_manage', false);

    grantPermissionScope($reader, 'projects.parties.manage', DataScope::OFFICE);

    $this->actingAs($reader->fresh())
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('meta.can_manage', true)
        ->assertJsonPath('data.0.can_manage', true);
});

it('costs a constant number of queries however many participants there are', function (): void {
    // `EffectiveAccessResolver` is deliberately uncached, so a per-row
    // visibility check would be the N+1 M2.6 measured on the Party reverse view.
    [$actor, $office] = participationReader([
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    $count = function () use ($actor, $project): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}/parties")->assertOk();

        return $queries;
    };

    linkIndividual($project, $office, 'Satu');
    $withOne = $count();

    foreach (['Dua', 'Tiga', 'Empat', 'Lima'] as $name) {
        linkIndividual($project, $office, $name);
    }

    expect($count())->toBe($withOne);
});
