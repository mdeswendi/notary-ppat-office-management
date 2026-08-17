<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor who may both read and maintain participation, plus see both Party
 * subtypes — the ordinary configuration for somebody doing this work.
 *
 * @return array{0: User, 1: Office}
 */
function participantManager(DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ([
        'projects.parties.view',
        'projects.parties.manage',
        'parties.view',
        'companies.view',
    ] as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/*
|--------------------------------------------------------------------------
| Add
|--------------------------------------------------------------------------
*/

it('links a Party to a Project', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office, 'Budi Santoso');

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            'role_code' => 'CLIENT',
            'is_primary' => true,
            'notes' => 'Penjual utama.',
        ])
        ->assertCreated();

    expect($response->json('data.role_code'))->toBe('CLIENT')
        ->and($response->json('data.is_primary'))->toBeTrue()
        ->and($response->json('data.notes'))->toBe('Penjual utama.')
        ->and($response->json('data.party.display_name'))->toBe('Budi Santoso')
        ->and($response->json('data.party.party_type'))->toBe('INDIVIDUAL');

    $participation = ProjectParty::query()->sole();

    expect($participation->office_id)->toBe($office->getKey())
        ->and($participation->created_by)->toBe($actor->getKey())
        ->and($participation->created_at)->not->toBeNull();
});

it('takes office_id from the Project rather than the request', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            'office_id' => Office::factory()->create()->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['office_id']);
});

it('refuses every system-controlled field rather than ignoring it', function (string $field, mixed $value): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            $field => $value,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    ['project_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['created_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['effective_from', '2026-01-01'],
    ['effective_until', '2026-01-01'],
    ['deleted_at', '2026-01-01 00:00:00'],
]);

it('refuses a system-controlled field sent as an explicit null', function (string $field): void {
    // D-097: `prohibited` reads as "missing or empty", so a null once satisfied
    // it and the request answered 201 with the key discarded.
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            $field => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with(['project_id', 'office_id', 'created_by', 'effective_until']);

it('requires a party_id', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['party_id']);
});

/*
|--------------------------------------------------------------------------
| Role and primary semantics
|--------------------------------------------------------------------------
*/

it('accepts a participation with no role at all', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $response = $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
        ])
        ->assertCreated();

    expect($response->json('data.role_code'))->toBeNull()
        ->and($response->json('data.is_primary'))->toBeFalse();
});

it('accepts an arbitrary opaque role code', function (string $code): void {
    // No catalogue exists, so a code the ERD never mentions is as valid as one
    // it does. Anything else would make the six examples a vocabulary (D-092).
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            'role_code' => $code,
        ])
        ->assertCreated()
        ->assertJsonPath('data.role_code', $code);
})->with(['CLIENT', 'SELLER', 'PIHAK_KETIGA', 'ANYTHING_AT_ALL']);

it('refuses a role code longer than the column', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
            'party_id' => $individual->party_id,
            'role_code' => str_repeat('X', 31),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['role_code']);
});

it('allows several primary participants without complaint', function (): void {
    // No cardinality rule: not exactly-one, not at-least-one, not one per role.
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();

    foreach (['Satu', 'Dua', 'Tiga'] as $name) {
        $this->actingAs($actor)
            ->postJson("/api/v1/projects/{$project->getKey()}/parties", [
                'party_id' => candidateIndividual($office, $name)->party_id,
                'is_primary' => true,
            ])
            ->assertCreated();
    }

    expect(ProjectParty::query()->where('is_primary', true)->count())->toBe(3);
});

it('requires no participant at all', function (): void {
    // A Project with an empty participation list is an ordinary, valid Project.
    [$actor, $office] = participantManager();
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}/parties")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    $this->actingAs($actor)
        ->getJson("/api/v1/projects/{$project->getKey()}")
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('corrects the role, primary designation, and notes', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
        'role_code' => 'AWAL',
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}", [
            'role_code' => 'DIPERBAIKI',
            'is_primary' => true,
            'notes' => 'Catatan baru.',
        ])
        ->assertOk()
        ->assertJsonPath('data.role_code', 'DIPERBAIKI')
        ->assertJsonPath('data.is_primary', true)
        ->assertJsonPath('data.notes', 'Catatan baru.');
});

it('refuses to re-point a participation at another Party', function (): void {
    // Not a correction — a different relationship. Remove and add instead.
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();
    $other = candidateIndividual($office, 'Orang Lain');

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}", [
            'party_id' => $other->party_id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['party_id']);

    expect($participation->fresh()->party_id)->toBe($party->getKey());
});

it('keeps the relationship keys immutable through update', function (string $field, mixed $value): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}", [$field => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    $fresh = $participation->fresh();

    expect($fresh->project_id)->toBe($project->getKey())
        ->and($fresh->party_id)->toBe($party->getKey())
        ->and($fresh->office_id)->toBe($office->getKey());
})->with([
    ['project_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['party_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['created_by', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
]);

it('leaves created_by and created_at alone when somebody else corrects a note', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();
    $originalAuthor = User::factory()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
        'created_by' => $originalAuthor->getKey(),
    ]);

    $createdAt = $participation->created_at;

    $this->actingAs($actor)
        ->patchJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}", ['notes' => 'x'])
        ->assertOk();

    $fresh = $participation->fresh();

    expect($fresh->created_by)->toBe($originalAuthor->getKey())
        ->and($fresh->created_at->toIso8601String())->toBe($createdAt->toIso8601String());
});

/*
|--------------------------------------------------------------------------
| Remove
|--------------------------------------------------------------------------
*/

it('unlinks by deleting the relationship row and nothing else', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}")
        ->assertNoContent();

    expect(ProjectParty::query()->count())->toBe(0)
        // Neither endpoint is touched: not deleted, not archived.
        ->and(Project::query()->whereKey($project->getKey())->exists())->toBeTrue()
        ->and($project->fresh()->deleted_at)->toBeNull()
        ->and(Party::query()->whereKey($party->getKey())->exists())->toBeTrue()
        ->and($party->fresh()->deleted_at)->toBeNull();
});

it('leaves no soft-deleted remnant behind', function (): void {
    // Hard deletion, stated plainly. There is no `deleted_at` to hide in and no
    // claim that participation history is preserved (D-098).
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $participation = ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => $party->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/projects/{$project->getKey()}/parties/{$participation->getKey()}")
        ->assertNoContent();

    expect(DB::table('project_parties')->count())->toBe(0);
});

it('leaves the other participations of the same Project intact', function (): void {
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();

    $rows = collect(['Satu', 'Dua'])->map(fn (string $name) => ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => candidateIndividual($office, $name)->party_id,
        'office_id' => $office->getKey(),
    ]));

    $this->actingAs($actor)
        ->deleteJson("/api/v1/projects/{$project->getKey()}/parties/{$rows[0]->getKey()}")
        ->assertNoContent();

    expect(ProjectParty::query()->pluck('id')->all())->toBe([$rows[1]->getKey()]);
});

it('can re-add a Party that was unlinked', function (): void {
    // Nothing remembers the removal, which is what "current working state"
    // means. A fresh row with a fresh `created_at` is the correct outcome.
    [$actor, $office] = participantManager();
    $project = Project::factory()->for($office)->create();
    $individual = candidateIndividual($office);

    $first = $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $individual->party_id])
        ->assertCreated();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/projects/{$project->getKey()}/parties/{$first->json('data.id')}")
        ->assertNoContent();

    $second = $this->actingAs($actor)
        ->postJson("/api/v1/projects/{$project->getKey()}/parties", ['party_id' => $individual->party_id])
        ->assertCreated();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(ProjectParty::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Project boundary
|--------------------------------------------------------------------------
*/

it('leaves Project creation unchanged', function (): void {
    // M3.3's create surface was not retroactively given a participant field, and
    // no participant is required (D-098). A Project created with no Party is
    // complete, not a draft.
    [$actor, $office] = participantManager();
    grantPermissionScope($actor, 'projects.create', DataScope::OFFICE);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $created = $this->actingAs($actor)
        ->postJson('/api/v1/projects', ['title' => 'Tanpa Pihak'])
        ->assertCreated();

    expect($created->json('data'))->not->toHaveKey('parties')
        ->and($created->json('data'))->not->toHaveKey('participants')
        ->and($created->json('data'))->not->toHaveKey('party_id');

    // A `party_id` in a Project create body is an unknown key, ignored the way
    // every unknown key is — participation is not part of this resource, so
    // nothing is created. Deliberately not a 422: naming `party_id` in the
    // Project request's refusal list would single out one speculative key while
    // every other unknown key stayed silently ignored.
    $withParty = $this->actingAs($actor)
        ->postJson('/api/v1/projects', [
            'title' => 'Dengan Pihak',
            'party_id' => candidateIndividual($office)->party_id,
        ])
        ->assertCreated();

    expect(ProjectParty::query()->count())->toBe(0)
        ->and($this->actingAs($actor)
            ->getJson("/api/v1/projects/{$withParty->json('data.id')}/parties")
            ->json('meta.total'))->toBe(0);
});

it('keeps participation off the Project resource', function (): void {
    // A Project payload that carried its participants would make
    // `projects.view` a way to read them, bypassing the dedicated capability.
    [$actor, $office] = participantManager();
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();
    ProjectParty::factory()->create([
        'project_id' => $project->getKey(),
        'party_id' => Party::factory()->individual()->for($office)->create()->getKey(),
        'office_id' => $office->getKey(),
    ]);

    $response = $this->actingAs($actor)->getJson("/api/v1/projects/{$project->getKey()}")->assertOk();

    expect($response->json('data'))->not->toHaveKey('parties')
        ->and($response->json('data'))->not->toHaveKey('participants');
});
