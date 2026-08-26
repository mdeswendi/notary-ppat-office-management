<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\MasterData\Enums\ServiceTypeDomain;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Domains\Project\Enums\ProjectPriority;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Project;
use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // The reference year comes from the application clock, so the suite freezes
    // it rather than depending on when it runs.
    Date::setTestNow('2026-05-18 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

uses(RefreshDatabase::class);

/**
 * An actor holding the named permissions at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function managementActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * The full Notary capability set an ordinary office worker would hold.
 *
 * @return array<int, string>
 */
function notaryCapabilities(): array
{
    return [
        'projects.view',
        'notary.matters.view', 'notary.matters.create', 'notary.matters.update',
        'notary.matters.assign', 'notary.matters.complete', 'notary.matters.cancel',
    ];
}

function serviceTypeFor(Office $office, MatterDomain $domain, bool $active = true): ServiceType
{
    return ServiceType::factory()
        ->for($office)
        ->domain(ServiceTypeDomain::from($domain->value))
        ->state(['is_active' => $active])
        ->create();
}

/*
|--------------------------------------------------------------------------
| Route wiring and domain context
|--------------------------------------------------------------------------
*/

it('registers exactly the expected matter routes and nothing more', function (): void {
    // An inventory rather than an absence check: a new Matter route now has to be
    // added here deliberately. No DELETE — M4 ships no archive lifecycle (D-102).
    //
    // **Narrowed at M4.5, again at M4.7, and again at M7.3** to the Matter lifecycle
    // surface this file owns. Participation routes are M4.5's, stage routes are
    // M4.7's, and the Property junction is M7.3's — each pinned just as exactly in its
    // own suite. Listing them in two places would mean every future change had to be
    // applied twice, which is how one of the two copies goes stale.
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => strtoupper(implode('|', array_diff($route->methods(), ['HEAD']))).' '.$route->uri())
        // **`reports/` excluded at M8.3.** `api/v1/reports/operational/matters`
        // contains "matters" but is a Report route: it answers to
        // `reports.operational.view`, not to `notary.matters.*` or
        // `ppat.matters.*`, and it belongs to the inventory in the Reports suite.
        // The same substring collision M8.1 hit with `dashboard/tasks`.
        ->filter(fn (string $route): bool => str_contains($route, 'matters')
            && ! str_contains($route, 'reports/')
            && ! str_contains($route, 'parties')
            && ! str_contains($route, 'party-options')
            && ! str_contains($route, 'properties')
            && ! str_contains($route, 'stages'))
        ->values()->sort()->values()->all();

    expect($routes)->toBe([
        'GET api/v1/notary/matters',
        'GET api/v1/notary/matters/service-type-options',
        'GET api/v1/notary/matters/{matter}',
        'GET api/v1/notary/matters/{matter}/assignment/options',
        'GET api/v1/ppat/matters',
        'GET api/v1/ppat/matters/service-type-options',
        'GET api/v1/ppat/matters/{matter}',
        'GET api/v1/ppat/matters/{matter}/assignment/options',
        'PATCH api/v1/notary/matters/{matter}',
        'PATCH api/v1/notary/matters/{matter}/assignment',
        'PATCH api/v1/ppat/matters/{matter}',
        'PATCH api/v1/ppat/matters/{matter}/assignment',
        'POST api/v1/notary/matters',
        'POST api/v1/notary/matters/{matter}/cancel',
        'POST api/v1/notary/matters/{matter}/complete',
        'POST api/v1/ppat/matters',
        'POST api/v1/ppat/matters/{matter}/cancel',
        'POST api/v1/ppat/matters/{matter}/complete',
    ]);
});

it('resolves the service type options path before the matter binding', function (): void {
    // Reversed, the literal path would be read as a Matter id and answer 404.
    [$actor] = managementActor(['notary.matters.view']);

    $this->actingAs($actor)->getJson('/api/v1/notary/matters/service-type-options')->assertOk();
});

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('creates a matter under a project in the actor office', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();

    $response = $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'priority' => ProjectPriority::HIGH->value,
    ])->assertCreated();

    $response->assertJsonPath('data.domain', 'NOTARY')
        ->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.matter_number', 'N-2026-000001')
        ->assertJsonPath('data.pic', null)
        ->assertJsonPath('data.project.id', $project->getKey());

    $matter = Matter::query()->firstOrFail();

    expect($matter->office_id)->toBe($project->office_id)
        ->and($matter->created_by)->toBe($actor->getKey())
        ->and($matter->service_type_id)->toBeNull();
});

it('allocates the ppat prefix on the ppat route', function (): void {
    [$actor, $office] = managementActor(['projects.view', 'ppat.matters.create', 'ppat.matters.view']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/ppat/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertCreated()
        ->assertJsonPath('data.domain', 'PPAT')
        ->assertJsonPath('data.matter_number', 'P-2026-000001');
});

it('keeps notary and ppat sequences independent', function (): void {
    [$actor, $office] = managementActor([
        'projects.view', 'notary.matters.create', 'notary.matters.view',
        'ppat.matters.create', 'ppat.matters.view',
    ]);
    $project = Project::factory()->for($office)->create();

    $payload = ['project_id' => $project->getKey(), 'title' => 'Pekerjaan Uji'];

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', $payload)
        ->assertCreated()->assertJsonPath('data.matter_number', 'N-2026-000001');
    $this->actingAs($actor)->postJson('/api/v1/ppat/matters', $payload)
        ->assertCreated()->assertJsonPath('data.matter_number', 'P-2026-000001');
    $this->actingAs($actor)->postJson('/api/v1/notary/matters', $payload)
        ->assertCreated()->assertJsonPath('data.matter_number', 'N-2026-000002');
});

it('refuses to create without the domain create capability', function (): void {
    [$actor, $office] = managementActor(['projects.view', 'ppat.matters.create']);
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertForbidden();
});

it('refuses to create without project view', function (): void {
    [$actor, $office] = managementActor(['notary.matters.create', 'notary.matters.view']);
    $project = Project::factory()->for($office)->create();

    // The parent is resolved through Project visibility, so an unreachable
    // Project is indistinguishable from a nonexistent one.
    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertStatus(422);
});

it('refuses to create under another office project even at ALL scope', function (): void {
    [$actor] = managementActor(notaryCapabilities(), DataScope::ALL);
    $foreign = Project::factory()->for(Office::factory())->create();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $foreign->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertForbidden();

    expect(Matter::query()->count())->toBe(0);
});

it('refuses to create under an archived project', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $project->delete();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertStatus(422);
});

it('refuses to create when only ASSIGNED is granted', function (): void {
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    grantPermissionScope($actor, 'notary.matters.create', DataScope::ASSIGNED);
    grantPermissionScope($actor, 'projects.view', DataScope::OFFICE);
    $actor = $actor->fresh();

    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
    ])->assertForbidden();
});

it('refuses every system controlled field at creation', function (string $field, mixed $value): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'domain' => ['domain', 'PPAT'],
    'office_id' => ['office_id', '01M44OFFICEAAAAAAAAAAAAAAA'],
    'matter_number' => ['matter_number', 'N-2026-999999'],
    'status' => ['status', 'COMPLETED'],
    'pic_user_id' => ['pic_user_id', '01M44USERAAAAAAAAAAAAAAAAA'],
    'completed_at' => ['completed_at', '2026-08-18 10:00:00'],
]);

it('refuses a system controlled field that is present but null', function (): void {
    // `prohibited` alone reads as "missing or empty", so a null would otherwise
    // answer 201 while the instruction was discarded.
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'status' => null,
    ])->assertStatus(422)->assertJsonValidationErrors(['status']);
});

/*
|--------------------------------------------------------------------------
| Service Type selection
|--------------------------------------------------------------------------
*/

it('accepts an active same office same domain service type', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $serviceType = serviceTypeFor($office, MatterDomain::NOTARY);

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'service_type_id' => $serviceType->getKey(),
    ])->assertCreated()
        ->assertJsonPath('data.service_type.id', $serviceType->getKey());
});

it('refuses a service type of the other domain', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $ppatService = serviceTypeFor($office, MatterDomain::PPAT);

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'service_type_id' => $ppatService->getKey(),
    ])->assertStatus(422);
});

it('refuses a service type from another office', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $foreign = serviceTypeFor(Office::factory()->create(), MatterDomain::NOTARY);

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'service_type_id' => $foreign->getKey(),
    ])->assertStatus(422);
});

it('refuses an inactive service type for new selection', function (): void {
    // Retired means unavailable for new work; existing references stay valid.
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $retired = serviceTypeFor($office, MatterDomain::NOTARY, active: false);

    $this->actingAs($actor)->postJson('/api/v1/notary/matters', [
        'project_id' => $project->getKey(),
        'title' => 'Pekerjaan Uji',
        'service_type_id' => $retired->getKey(),
    ])->assertStatus(422);
});

it('offers only active same office same domain service types', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());

    $wanted = serviceTypeFor($office, MatterDomain::NOTARY);
    $retired = serviceTypeFor($office, MatterDomain::NOTARY, active: false);
    $otherDomain = serviceTypeFor($office, MatterDomain::PPAT);
    $otherOffice = serviceTypeFor(Office::factory()->create(), MatterDomain::NOTARY);

    $ids = collect(
        $this->actingAs($actor)->getJson('/api/v1/notary/matters/service-type-options')
            ->assertOk()->json('data.service_types')
    )->pluck('id')->all();

    expect($ids)->toBe([$wanted->getKey()])
        ->and($ids)->not->toContain($retired->getKey())
        ->and($ids)->not->toContain($otherDomain->getKey())
        ->and($ids)->not->toContain($otherOffice->getKey());
});

/*
|--------------------------------------------------------------------------
| List and detail
|--------------------------------------------------------------------------
*/

it('isolates each domain list', function (): void {
    [$actor, $office] = managementActor([
        'notary.matters.view', 'ppat.matters.view',
    ]);
    $project = Project::factory()->for($office)->create();

    $notary = Matter::factory()->for($project)->domain(MatterDomain::NOTARY)->create();
    $ppat = Matter::factory()->for($project)->domain(MatterDomain::PPAT)->create();

    $notaryIds = collect($this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertOk()->json('data'))->pluck('id');
    $ppatIds = collect($this->actingAs($actor)->getJson('/api/v1/ppat/matters')->assertOk()->json('data'))->pluck('id');

    expect($notaryIds->all())->toBe([$notary->getKey()])
        ->and($ppatIds->all())->toBe([$ppat->getKey()]);
});

it('applies matter data scope to the list', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view'], DataScope::OWN);
    $project = Project::factory()->for($office)->create();

    $mine = Matter::factory()->for($project)->create(['created_by' => $actor->getKey()]);
    Matter::factory()->for($project)->create();

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$mine->getKey()]);
});

it('gives no matter access to an actor who can only reach the parent project', function (): void {
    // D-100: reaching a Project confers nothing over the Matters beneath it.
    [$actor, $office] = managementActor(['projects.view', 'projects.update']);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}")->assertForbidden();
});

it('answers 404 for a matter of the other domain', function (): void {
    // A 403 would confirm the record exists in a domain the caller never named.
    [$actor, $office] = managementActor(['notary.matters.view', 'ppat.matters.view']);
    $project = Project::factory()->for($office)->create();
    $ppat = Matter::factory()->for($project)->domain(MatterDomain::PPAT)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$ppat->getKey()}")->assertNotFound();
});

it('gives an actor holding only view_all no matter reach', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view_all'], DataScope::ALL);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}")->assertForbidden();
});

it('exposes the capability flags computed from the policy', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view', 'notary.matters.update']);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_update', true)
        ->assertJsonPath('data.can_assign', false)
        ->assertJsonPath('data.can_complete', false)
        ->assertJsonPath('data.can_cancel', false);
});

it('does not grow its query cost as the list grows', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();

    Matter::factory()->for($project)->create();

    $count = function () use ($actor): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $one = $count();

    Matter::factory()->for($project)->count(9)->create();

    expect($count())->toBe($one);
});

it('carries no party, workflow, or legal identity in the payload', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->withServiceType()->create();

    $payload = $this->actingAs($actor)
        ->getJson("/api/v1/notary/matters/{$matter->getKey()}")->assertOk()->json('data');

    // **Identifiers are redacted before the search** *(fixed at M5.2)*. This
    // scanned the raw JSON, which includes four lowercased ULIDs — and a ULID is
    // 26 characters of Crockford base32, so it can legitimately *contain* the
    // letters `deed` or `npwp`. Measured: roughly one ULID in 200,000 does, which
    // made this test fail about once in fifty thousand runs for a reason that had
    // nothing to do with what it was checking. It failed exactly that way during
    // the M5.2 run.
    //
    // (`nik`, `tax_id` and `warkah` were never at risk: Crockford base32 excludes
    // `i`, `l`, `o` and `u`, and there is no underscore. Only two of the five
    // needles could ever collide, which is why this went unnoticed for so long.)
    //
    // The claim is unchanged and is the one worth keeping: **no Party identity,
    // deed, or Warkah field or value appears in a Matter payload.** An opaque
    // identifier was never evidence for or against it.
    $raw = strtolower((string) preg_replace('/"[0-9a-z]{26}"/', '"[ulid]"', (string) json_encode($payload)));

    foreach (['nik', 'npwp', 'tax_id', 'deed', 'warkah'] as $absent) {
        expect(str_contains($raw, $absent))->toBeFalse($absent);
    }

    // **Narrowed at M4.5**, which added `can_view_parties` and
    // `can_manage_parties`, and again at M4.7, which added `can_change_stage` —
    // capability flags, not Party or workflow data. A raw substring search for
    // "parties" or "stage" now matches those and would fail for the wrong
    // reason.
    //
    // The claim worth keeping, in both cases, is that the Matter payload
    // **embeds neither the participant list nor the workflow**. Each is its own
    // endpoint: participation has its own capability entirely (D-105), and the
    // workflow is a separate read that would otherwise make every Matter fetch
    // carry a stage list nobody asked for.
    foreach ([
        'parties', 'participants', 'matter_parties',
        'workflow', 'stages', 'current_stage', 'stage_history',
    ] as $embedded) {
        expect($payload)->not->toHaveKey($embedded);
    }
});

/*
|--------------------------------------------------------------------------
| Generic update
|--------------------------------------------------------------------------
*/

it('updates ordinary attributes', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}", [
        'title' => 'Pekerjaan Uji Diperbarui',
        'notes' => 'Catatan',
        'priority' => ProjectPriority::URGENT->value,
    ])->assertOk()->assertJsonPath('data.title', 'Pekerjaan Uji Diperbarui');

    expect($matter->fresh()->updated_by)->toBe($actor->getKey());
});

it('refuses every governed field on update', function (string $field, mixed $value): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}", [$field => $value])
        ->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'project_id' => ['project_id', '01M44PROJECTAAAAAAAAAAAAAA'],
    'office_id' => ['office_id', '01M44OFFICEAAAAAAAAAAAAAAA'],
    'domain' => ['domain', 'PPAT'],
    'matter_number' => ['matter_number', 'N-2026-999999'],
    'status' => ['status', 'COMPLETED'],
    'pic_user_id' => ['pic_user_id', '01M44USERAAAAAAAAAAAAAAAAA'],
    'completed_at' => ['completed_at', '2026-08-18 10:00:00'],
]);

it('lets update change the service type classification', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();
    $serviceType = serviceTypeFor($office, MatterDomain::NOTARY);

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}", [
        'service_type_id' => $serviceType->getKey(),
    ])->assertOk()->assertJsonPath('data.service_type.id', $serviceType->getKey());
});

it('refuses an update without the update capability', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view']);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}", ['title' => 'X'])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Assignment
|--------------------------------------------------------------------------
*/

it('assigns and unassigns the person in charge', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();
    $colleague = User::factory()->for($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}/assignment", [
        'pic_user_id' => $colleague->getKey(),
    ])->assertOk()->assertJsonPath('data.pic.id', $colleague->getKey());

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}/assignment", [
        'pic_user_id' => null,
    ])->assertOk()->assertJsonPath('data.pic', null);
});

it('refuses a cross office pic even at ALL scope', function (): void {
    // ASSIGNED grants reach when pic_user_id == actor.id, so a cross-office PIC
    // would hand somebody reach their own scope never included.
    [$actor, $office] = managementActor(notaryCapabilities(), DataScope::ALL);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();
    $outsider = User::factory()->for(Office::factory())->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}/assignment", [
        'pic_user_id' => $outsider->getKey(),
    ])->assertStatus(422);
});

it('requires the assign capability rather than update', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view', 'notary.matters.update']);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();
    $colleague = User::factory()->for($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/matters/{$matter->getKey()}/assignment", [
        'pic_user_id' => $colleague->getKey(),
    ])->assertForbidden();
});

it('offers only active users of the matter office as candidates', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $colleague = User::factory()->for($office)->create();
    $disabled = User::factory()->for($office)->create(['is_active' => false]);
    $outsider = User::factory()->for(Office::factory())->create();

    $ids = collect(
        $this->actingAs($actor)->getJson("/api/v1/notary/matters/{$matter->getKey()}/assignment/options")
            ->assertOk()->json('data.users')
    )->pluck('id');

    expect($ids)->toContain($colleague->getKey())
        ->and($ids)->not->toContain($disabled->getKey())
        ->and($ids)->not->toContain($outsider->getKey());
});

/*
|--------------------------------------------------------------------------
| Complete and cancel
|--------------------------------------------------------------------------
*/

it('completes a matter and stamps the completion time', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED');

    $fresh = $matter->fresh();

    expect($fresh->completed_at)->not->toBeNull()
        ->and($fresh->updated_by)->toBe($actor->getKey())
        ->and($fresh->matter_number)->toBe($matter->matter_number);
});

it('cancels a matter without inventing a reason or timestamp', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'CANCELLED');

    expect($matter->fresh()->completed_at)->toBeNull();
});

it('keeps completed and cancelled matters in the ordinary list', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->status(MatterStatus::CANCELLED)->create();

    $ids = collect($this->actingAs($actor)->getJson('/api/v1/notary/matters')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toContain($matter->getKey());
});

it('deletes nothing when completing or cancelling', function (): void {
    [$actor, $office] = managementActor(notaryCapabilities());
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/cancel")->assertOk();

    expect(DB::table('matters')->whereNotNull('deleted_at')->count())->toBe(0)
        ->and(Matter::query()->count())->toBe(1);
});

it('separates complete and cancel capabilities', function (): void {
    [$actor, $office] = managementActor(['notary.matters.view', 'notary.matters.complete']);
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for($project)->create();

    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/cancel")->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/notary/matters/{$matter->getKey()}/complete")->assertOk();
});

it('exposes no way to set the statuses no capability owns', function (): void {
    // OPEN, COMPLETED and CANCELLED are reachable; IN_PROGRESS, WAITING,
    // ON_HOLD and ARCHIVED are canonical vocabulary that no M4 capability can
    // set, because Matter has no `change_status` code. Accepted deliberately
    // rather than engineered around (D-109).
    //
    // **Narrowed at M4.7**, which gives stages their own routes (D-112). The
    // `matters/{matter}/stage` entry left this list because it was never about
    // stages: Matter Status and Workflow Stage are separate concepts (CLAUDE.md
    // section 18), and a stage route is not a way to set a *status*. What
    // replaces it is the assertion that actually holds — moving a stage writes
    // no `matters.status` — which `MatterWorkflowTest` proves behaviourally.
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());

    foreach (['matters/{matter}/status', 'matters/{matter}/change-status'] as $absent) {
        expect($uris->filter(fn (string $uri): bool => str_contains($uri, $absent)))->toBeEmpty($absent);
    }
});
