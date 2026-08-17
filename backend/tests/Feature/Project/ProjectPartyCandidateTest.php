<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Company;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function candidateIndividual(Office $office, string $name = 'Budi Santoso'): Individual
{
    return Individual::factory()
        ->for(Party::factory()->individual()->for($office)->state(['display_name' => $name]), 'party')
        ->create(['full_name' => $name]);
}

function candidateCompany(Office $office, string $name = 'PT Sejahtera'): Company
{
    return Company::factory()
        ->for(Party::factory()->company()->for($office)->state(['display_name' => $name]), 'party')
        ->create(['legal_name' => $name]);
}

/**
 * @param  array<string, DataScope>  $grants
 * @return array{0: User, 1: Office}
 */
function candidateActor(array $grants): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($grants as $permission => $scope) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/*
|--------------------------------------------------------------------------
| Manage alone is not Party discovery
|--------------------------------------------------------------------------
*/

it('offers no candidate to an actor holding only participation manage', function (): void {
    // The whole point of the candidate boundary. `projects.parties.manage` is
    // authority over this Project's participation, never authority to discover
    // people (D-098).
    [$actor, $office] = candidateActor(['projects.parties.manage' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();

    candidateIndividual($office);
    candidateCompany($office);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    expect($response->json('data.parties'))->toBe([]);
});

it('refuses to link a Party the actor cannot see, even with manage', function (): void {
    [$actor, $office] = candidateActor(['projects.parties.manage' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    $hidden = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $hidden->party_id])
        ->assertStatus(422);

    expect(ProjectParty::query()->count())->toBe(0);
});

it('cannot be bypassed by a guessed Party id', function (): void {
    // The id is real and correct; the actor simply may not see that Party. The
    // Action re-resolves it through the authorized candidate query rather than
    // trusting what arrived.
    [$actor, $office] = candidateActor(['projects.parties.manage' => DataScope::OFFICE]);
    $project = Project::factory()->for($office)->create();
    $known = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $known->party_id,
            'role_code' => 'CLIENT',
        ])
        ->assertStatus(422)
        ->assertJsonMissingPath('data');

    expect(ProjectParty::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The two subtype branches stay independent
|--------------------------------------------------------------------------
*/

it('offers Individuals to parties.view and Companies to companies.view', function (
    string $partyPermission,
    string $expected,
): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        $partyPermission => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    candidateIndividual($office, 'Budi Santoso');
    candidateCompany($office, 'PT Sejahtera');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    expect($response->json('data.parties.*.display_name'))->toBe([$expected]);
})->with([
    ['parties.view', 'Budi Santoso'],
    ['companies.view', 'PT Sejahtera'],
]);

it('will not link a Company to an actor holding only parties.view', function (): void {
    // The branches are evaluated separately, so holding one never widens the
    // other (D-028, D-098).
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();
    $company = candidateCompany($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $company->party_id])
        ->assertStatus(422);
});

it('offers both subtypes to an actor holding both branches', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
        'companies.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    candidateIndividual($office, 'Budi Santoso');
    candidateCompany($office, 'PT Sejahtera');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    expect($response->json('data.parties.*.display_name'))->toBe(['Budi Santoso', 'PT Sejahtera'])
        ->and($response->json('data.parties.*.party_type'))->toBe(['INDIVIDUAL', 'COMPANY']);
});

/*
|--------------------------------------------------------------------------
| Same Office, even for ALL
|--------------------------------------------------------------------------
*/

it('offers no cross-office candidate even to an ALL-scoped actor', function (): void {
    // `ALL` on the Party side lets an actor see another Office's Parties. It
    // still cannot bridge two Offices in one participation, because the
    // candidate Office is fixed to the Project's (D-098).
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::ALL,
        'parties.view' => DataScope::ALL,
        'companies.view' => DataScope::ALL,
    ]);
    $project = Project::factory()->for($office)->create();

    $elsewhere = Office::factory()->create();
    candidateIndividual($elsewhere, 'Orang Kantor Lain');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    expect($response->json('data.parties'))->toBe([]);
});

it('refuses a cross-office link even to an ALL-scoped actor', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::ALL,
        'parties.view' => DataScope::ALL,
        'companies.view' => DataScope::ALL,
    ]);
    $project = Project::factory()->for($office)->create();

    $elsewhere = Office::factory()->create();
    $foreign = candidateIndividual($elsewhere);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $foreign->party_id])
        ->assertStatus(422);

    expect(ProjectParty::query()->count())->toBe(0);
});

it('lets an ALL-scoped actor link within another Office consistently', function (): void {
    // What ALL genuinely buys: reaching a Project that is not in the actor's own
    // Office, and linking a Party from *that* Project's Office. Still one Office
    // on both endpoints.
    [$actor] = candidateActor([
        'projects.parties.manage' => DataScope::ALL,
        'parties.view' => DataScope::ALL,
    ]);

    $elsewhere = Office::factory()->create();
    $project = Project::factory()->for($elsewhere)->create();
    $local = candidateIndividual($elsewhere, 'Warga Kantor Itu');

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $local->party_id])
        ->assertCreated();

    expect(ProjectParty::query()->value('office_id'))->toBe($elsewhere->getKey());
});

/*
|--------------------------------------------------------------------------
| Archived Parties are not candidates
|--------------------------------------------------------------------------
*/

it('offers no archived Party as a candidate', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    $retired = candidateIndividual($office, 'Sudah Diarsipkan');
    $retired->party->delete();

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    expect($response->json('data.parties'))->toBe([]);
});

it('refuses to link an archived Party', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    $retired = candidateIndividual($office);
    $retired->party->delete();

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $retired->party_id])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| The candidate list publishes nothing sensitive
|--------------------------------------------------------------------------
*/

it('publishes only an identifier, a name, and a subtype', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();
    candidateIndividual($office);

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options")
        ->assertOk();

    $candidate = $response->json('data.parties.0');

    expect(array_keys($candidate))->toBe(['id', 'display_name', 'party_type']);

    $body = $response->getContent();

    foreach (['nik', 'npwp', 'tax_id', 'primary_phone', 'primary_email', 'birth'] as $leak) {
        expect(str_contains(strtolower($body), $leak))->toBeFalse();
    }
});

it('searches candidates by display name', function (): void {
    [$actor, $office] = candidateActor([
        'projects.parties.manage' => DataScope::OFFICE,
        'parties.view' => DataScope::OFFICE,
    ]);
    $project = Project::factory()->for($office)->create();

    candidateIndividual($office, 'Budi Santoso');
    candidateIndividual($office, 'Siti Aminah');

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/party-options?search=Siti")
        ->assertOk();

    expect($response->json('data.parties.*.display_name'))->toBe(['Siti Aminah']);
});
