<?php

use App\Domains\Audit\Enums\AuditEvent;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\AuditLog;
use App\Models\Individual;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The audit foundation (M8.1, D-123) — the store D-115 has been waiting for
 * since M1.
 */
function auditActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
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
| Schema
|--------------------------------------------------------------------------
*/

it('builds both canonical batch-7 tables', function (): void {
    // ERD §24 and §25. Deferred from M1 (D-033) and M5 (D-115) on the batch
    // ordering, and built here because that ordering has now been satisfied for
    // three milestones running.
    expect(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(Schema::hasTable('activities'))->toBeTrue();
});

it('transcribes the audit_logs field list exactly', function (string $column): void {
    expect(Schema::hasColumn('audit_logs', $column))->toBeTrue();
})->with([
    'id', 'office_id', 'actor_user_id', 'event',
    'auditable_type', 'auditable_id',
    'old_values', 'new_values',
    'ip_address', 'user_agent', 'reason', 'created_at',
]);

it('gives audit_logs no updated_at and no deleted_at', function (string $column): void {
    // The ERD says so in as many words: "No: updated_at, deleted_at. Audit logs
    // are append-only."
    expect(Schema::hasColumn('audit_logs', $column))->toBeFalse();
})->with(['updated_at', 'deleted_at']);

/*
|--------------------------------------------------------------------------
| Append-only
|--------------------------------------------------------------------------
*/

it('refuses to update an audit row', function (): void {
    // CLAUDE.md §31 forbids `audit.update`. The catalogue has no such code, and
    // this is the other half: no internal method can perform one either.
    [$actor] = auditActor();

    $log = app(AuditLogger::class)->log(AuditEvent::LOGIN, $actor, $actor);

    // Set directly rather than through `update()`: nothing is fillable, so mass
    // assignment would leave the model clean and `save()` would return without
    // firing `updating` at all. A no-op save is not an update; this is.
    $log->reason = 'rewritten';

    expect(fn () => $log->save())
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses to delete an audit row', function (): void {
    [$actor] = auditActor();

    $log = app(AuditLogger::class)->log(AuditEvent::LOGIN, $actor, $actor);

    expect(fn () => $log->delete())
        ->toThrow(RuntimeException::class, 'append-only');
});

/*
|--------------------------------------------------------------------------
| Redaction — the rule D-115 restates with more force
|--------------------------------------------------------------------------
*/

it('never writes a sensitive value into old or new values', function (): void {
    [$actor, $office] = auditActor();

    $party = Party::factory()->create(['office_id' => $office->getKey()]);
    $individual = Individual::factory()->create([
        'party_id' => $party->getKey(),
        'nik' => '3174010101900001',
    ]);

    app(AuditLogger::class)->created($individual, $actor);

    $log = AuditLog::query()->where('auditable_type', Individual::class)->sole();

    // The key survives — an auditor still learns the field was populated — and
    // the value never reaches the table (D-105, D-115).
    expect($log->new_values)->toHaveKey('nik')
        ->and($log->new_values['nik'])->toBe('[redacted]')
        ->and(json_encode($log->new_values))->not->toContain('3174010101900001');
});

it('records that a sensitive field was read, never what it said', function (): void {
    [$actor, $office] = auditActor(['parties.view', 'parties.identity.view', 'parties.identity.nik.view_full']);

    $party = Party::factory()->create(['office_id' => $office->getKey()]);
    $individual = Individual::factory()->create([
        'party_id' => $party->getKey(),
        'nik' => '3174010101900002',
    ]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    $log = AuditLog::query()->where('event', AuditEvent::SENSITIVE_ACCESS->value)->sole();

    expect($log->new_values)->toBe(['field' => 'nik'])
        ->and($log->actor_user_id)->toBe($actor->getKey())
        ->and(json_encode($log->toArray()))->not->toContain('3174010101900002');
});

it('files a cross-office reveal against the record owner, not the reader', function (): void {
    // The case the composite actor key would have made unrepresentable, and the
    // reason `audit_logs.actor_user_id` is a plain foreign key. An `ALL`-scope
    // actor reaching another Office is exactly what an auditor needs recorded —
    // and the row must land where that Office will look for it.
    [$actor] = auditActor(
        ['parties.view', 'parties.identity.view', 'parties.identity.nik.view_full'],
        DataScope::ALL,
    );

    $elsewhere = Office::factory()->create();
    $party = Party::factory()->create(['office_id' => $elsewhere->getKey()]);
    $individual = Individual::factory()->create(['party_id' => $party->getKey()]);

    $this->actingAs($actor)
        ->postJson("/api/v1/individuals/{$individual->party_id}/identity/nik/reveal")
        ->assertOk();

    $log = AuditLog::query()->where('event', AuditEvent::SENSITIVE_ACCESS->value)->sole();

    expect($log->office_id)->toBe($elsewhere->getKey())
        ->and($log->actor_user_id)->toBe($actor->getKey())
        ->and($log->office_id)->not->toBe($actor->office_id);
});

/*
|--------------------------------------------------------------------------
| Session events
|--------------------------------------------------------------------------
*/

it('audits a login once per session, not once per request', function (): void {
    // `Login`, deliberately not `Authenticated` — the latter fires on every
    // authenticated request and would bury real events under thousands of rows
    // in a table nobody may delete from.
    $office = Office::factory()->create();
    $user = User::factory()->for($office)->create(['password' => bcrypt('rahasia-uji-123')]);

    $this->postJson('/login', ['email' => $user->email, 'password' => 'rahasia-uji-123'])
        ->assertNoContent();

    // Several authenticated requests after the one login.
    $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertOk();
    $this->actingAs($user->fresh())->getJson('/api/v1/me')->assertOk();

    expect(AuditLog::query()->where('event', AuditEvent::LOGIN->value)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The read surface — `audit.view` authorizes something at last
|--------------------------------------------------------------------------
*/

it('refuses the audit trail to an actor without audit.view', function (): void {
    [$actor] = auditActor(['projects.view']);

    $this->actingAs($actor)->getJson('/api/v1/audit-logs')->assertForbidden();
});

it('serves the audit trail to an actor holding audit.view', function (): void {
    [$actor, $office] = auditActor(['audit.view']);

    app(AuditLogger::class)->log(AuditEvent::LOGIN, $actor, $actor);

    $this->actingAs($actor)->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.event', AuditEvent::LOGIN->value)
        ->assertJsonPath('data.0.actor.id', $actor->getKey());
});

it('confines the audit trail to the actor own office below ALL scope', function (): void {
    [$actor, $office] = auditActor(['audit.view']);

    $elsewhere = Office::factory()->create();
    $stranger = User::factory()->for($elsewhere)->create();

    app(AuditLogger::class)->log(AuditEvent::LOGIN, $actor, $actor);
    app(AuditLogger::class)->log(AuditEvent::LOGIN, $stranger, $stranger);

    $response = $this->actingAs($actor)->getJson('/api/v1/audit-logs')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('offers no write route on the audit trail', function (string $method): void {
    // Structural, not conventional: there is no address that could reach an
    // update or a delete, whatever a caller holds.
    [$actor] = auditActor(['audit.view']);

    $this->actingAs($actor)->json($method, '/api/v1/audit-logs')->assertStatus(405);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

/*
|--------------------------------------------------------------------------
| Integration
|--------------------------------------------------------------------------
*/

it('audits a created record without being asked by the caller', function (): void {
    [$actor] = auditActor(['projects.view', 'projects.create']);

    $this->actingAs($actor)->postJson('/api/v1/projects', [
        'title' => 'Akuisisi Tanah PT ABC',
    ])->assertCreated();

    $project = Project::query()->sole();

    $log = AuditLog::query()
        ->where('auditable_type', Project::class)
        ->where('event', AuditEvent::CREATED->value)
        ->sole();

    expect($log->auditable_id)->toBe($project->getKey())
        ->and($log->actor_user_id)->toBe($actor->getKey())
        ->and($log->office_id)->toBe($actor->office_id);
});
