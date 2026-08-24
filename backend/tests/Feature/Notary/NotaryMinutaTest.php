<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Authorization\PermissionScopeRules;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Document;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\NotaryMinuta;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use App\Policies\NotaryDeedPolicy;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function minutaActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function minutaDeed(Office $office, ?User $createdBy = null): NotaryDeed
{
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
        'created_by' => $createdBy?->getKey(),
    ]);

    return NotaryDeed::factory()->forMatter($matter)->create();
}

/*
|--------------------------------------------------------------------------
| Table shape
|--------------------------------------------------------------------------
*/

it('carries exactly the canonical notary_minuta columns plus its office carrier', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 17. `office_id` is the one
    // addition, recorded at M6.0 as the composite-key carrier.
    $columns = Schema::getColumnListing('notary_minuta');
    sort($columns);

    $expected = [
        'archive_location', 'archived_at', 'archived_by', 'bundle_number',
        'created_at', 'document_id', 'id', 'notary_deed_id', 'notes',
        'office_id', 'release_status', 'updated_at', 'volume_number',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('keeps the ERD singular table name', function (): void {
    // `minuta` is already the Indonesian legal term; pluralising it to
    // `notary_minutas` would invent a word (05_I18N_LEGAL_TERMINOLOGY.md).
    expect((new NotaryMinuta)->getTable())->toBe('notary_minuta')
        ->and(Schema::hasTable('notary_minutas'))->toBeFalse();
});

it('carries no deleted_at and no soft delete', function (): void {
    // The ERD omits the column and the catalogue has no `notary.minuta.delete`.
    expect(Schema::hasColumn('notary_minuta', 'deleted_at'))->toBeFalse()
        ->and(in_array(
            SoftDeletes::class,
            class_uses_recursive(NotaryMinuta::class),
            true
        ))->toBeFalse();
});

it('gives a minuta a generated ULID primary key', function (): void {
    $minuta = NotaryMinuta::factory()->create();

    expect(Str::isUlid($minuta->id))->toBeTrue()
        ->and($minuta->getIncrementing())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| release_status: the field a brief keeps trying to fill in
|--------------------------------------------------------------------------
*/

it('creates release_status with no default and no vocabulary', function (): void {
    // The ERD names the column and gives it NO values at all. The
    // DRAFT/ARCHIVED/RELEASED triple appears in no canonical document, and the
    // archive trigger is open question four (D-120). So the column exists, carries
    // no default, and nothing writes it.
    $minuta = NotaryMinuta::factory()->create();

    expect($minuta->release_status)->toBeNull()
        ->and($minuta->archived_at)->toBeNull()
        ->and($minuta->archived_by)->toBeNull();
});

it('defines no minuta status enum anywhere', function (): void {
    // An enum would be a vocabulary. There is none to have.
    expect(class_exists('App\Domains\Notary\Enums\NotaryMinutaStatus'))->toBeFalse()
        ->and(class_exists('App\Domains\Notary\Enums\MinutaReleaseStatus'))->toBeFalse();
});

it('keeps release_status out of mass assignment', function (): void {
    $minuta = NotaryMinuta::factory()->create();

    $minuta->fill([
        'release_status' => 'ARCHIVED',
        'archived_at' => now(),
        'archived_by' => User::factory()->create()->getKey(),
    ]);

    expect($minuta->release_status)->toBeNull()
        ->and($minuta->archived_at)->toBeNull()
        ->and($minuta->archived_by)->toBeNull();
});

it('refuses half an archival', function (): void {
    // Nothing writes the pair in M6; the guard exists so a later milestone cannot
    // write half of one.
    $minuta = NotaryMinuta::factory()->create();

    expect(fn () => $minuta->forceFill(['archived_at' => now()])->save())
        ->toThrow(RuntimeException::class, 'pair');
});

/*
|--------------------------------------------------------------------------
| One per deed
|--------------------------------------------------------------------------
*/

it('allows only one minuta per deed', function (): void {
    // A Minuta Akta is the original record of one deed — the term carries the
    // cardinality (D-120), and a unique index makes it structural.
    $deed = NotaryDeed::factory()->create();

    NotaryMinuta::factory()->forDeed($deed)->create();

    expect(fn () => NotaryMinuta::factory()->forDeed($deed)->create())
        ->toThrow(QueryException::class);
});

it('reaches its minuta from the deed and its deed from the minuta', function (): void {
    $deed = NotaryDeed::factory()->create();
    $minuta = NotaryMinuta::factory()->forDeed($deed)->create();

    expect($deed->minuta->is($minuta))->toBeTrue()
        ->and($minuta->deed->is($deed))->toBeTrue()
        ->and($minuta->document)->not->toBeNull();
});

it('keeps the deed own minuta_document_id separate from the filing record', function (): void {
    // Two legitimate things: that column answers *which file*, this row answers
    // *which shelf*. A deed may carry either, both, or neither.
    $deed = NotaryDeed::factory()->create();
    $file = Document::factory()->create(['office_id' => $deed->office_id]);

    $deed->forceFill(['minuta_document_id' => $file->getKey()])->save();
    $minuta = NotaryMinuta::factory()->forDeed($deed)->create();

    expect($deed->fresh()->minuta_document_id)->toBe($file->getKey())
        ->and($minuta->document_id)->not->toBe($file->getKey());
});

/*
|--------------------------------------------------------------------------
| Office is structural
|--------------------------------------------------------------------------
*/

it('refuses a document from another office', function (): void {
    $deed = NotaryDeed::factory()->create();
    $foreign = Document::factory()->create();

    expect($foreign->office_id)->not->toBe($deed->office_id);

    expect(fn () => NotaryMinuta::factory()->forDeed($deed)->document($foreign)->create())
        ->toThrow(QueryException::class);
});

it('refuses a minuta whose office disagrees with its deed', function (): void {
    $deed = NotaryDeed::factory()->create();
    $elsewhere = Office::factory()->create();

    expect(fn () => NotaryMinuta::factory()->create([
        'notary_deed_id' => $deed->getKey(),
        'office_id' => $elsewhere->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses to delete a document a minuta points at', function (): void {
    $minuta = NotaryMinuta::factory()->create();
    $document = $minuta->document;

    expect(fn () => $document->forceDelete())->toThrow(QueryException::class);
});

it('refuses to move a minuta between offices or deeds', function (string $column): void {
    $minuta = NotaryMinuta::factory()->create();
    $other = $column === 'office_id'
        ? Office::factory()->create()->getKey()
        : NotaryDeed::factory()->create()->getKey();

    expect(fn () => $minuta->forceFill([$column => $other])->save())
        ->toThrow(RuntimeException::class, 'immutable');
})->with(['office_id', 'notary_deed_id']);

/*
|--------------------------------------------------------------------------
| The endpoints
|--------------------------------------------------------------------------
*/

it('answers 404 when no minuta has been filed', function (): void {
    // Not an empty payload: a deed with nothing filed and a deed whose filing the
    // caller may not see are different situations, and the interface renders them
    // differently.
    [$actor, $office] = minutaActor(['notary.deeds.view', 'notary.minuta.view']);

    $deed = minutaDeed($office);

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta")
        ->assertNotFound();
});

it('files and then reads a minuta', function (): void {
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.view', 'notary.minuta.create', 'documents.view',
    ]);

    $deed = minutaDeed($office);
    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $document->getKey(),
        'archive_location' => 'Lemari 3',
        'volume_number' => 'VIII',
        'bundle_number' => '12',
    ])->assertCreated()
        ->assertJsonPath('data.document.id', $document->getKey())
        ->assertJsonPath('data.archive_location', 'Lemari 3')
        // Canonical columns, exposed and empty.
        ->assertJsonPath('data.release_status', null)
        ->assertJsonPath('data.archived_at', null);

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta")
        ->assertOk()->assertJsonPath('data.volume_number', 'VIII');
});

it('refuses a second filing rather than replacing the first', function (): void {
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.create', 'documents.view',
    ]);

    $deed = minutaDeed($office);
    NotaryMinuta::factory()->forDeed($deed)->create();

    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $document->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('document_id');
});

it('files against a deed in any status', function (string $state): void {
    // *When* an office files the original is a question section 6 does not answer,
    // so gating this on FINALIZED would be answering it.
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.create', 'documents.view',
    ]);

    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    $deed = NotaryDeed::factory()->forMatter($matter)->{$state}()->create();
    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $document->getKey(),
    ])->assertCreated();
})->with(['reviewed', 'approved', 'finalized']);

it('replaces the file without touching the deed', function (): void {
    // Correcting a bad scan is the point of `update`, and both Documents keep their
    // own version histories either side of it.
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.update', 'documents.view',
    ]);

    $deed = minutaDeed($office);
    $minuta = NotaryMinuta::factory()->forDeed($deed)->create();
    $replacement = Document::factory()->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $replacement->getKey(),
        'archive_location' => 'Lemari 4',
    ])->assertOk()
        ->assertJsonPath('data.document.id', $replacement->getKey())
        ->assertJsonPath('data.archive_location', 'Lemari 4');

    expect($minuta->fresh()->notary_deed_id)->toBe($deed->getKey());
});

it('refuses a document the caller cannot reach', function (): void {
    // `notary.minuta.create` is authority to record a filing, never authority to
    // discover which Documents exist (the D-118 two-question rule).
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.create',
    ]);

    $deed = minutaDeed($office);
    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    // No `documents.view` grant at all.
    $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $document->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('document_id');
});

it('gives an unreachable, cross-office and nonexistent document the same answer', function (): void {
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.create', 'documents.view',
    ]);

    $deed = minutaDeed($office);
    $elsewhere = Document::factory()->create();

    foreach ([$elsewhere->getKey(), (string) Str::ulid()] as $candidate) {
        $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
            'document_id' => $candidate,
        ])->assertStatus(422)->assertJsonValidationErrors('document_id');
    }
});

it('refuses every lifecycle field on presence', function (string $field, mixed $value): void {
    [$actor, $office] = minutaActor([
        'notary.deeds.view', 'notary.minuta.create', 'documents.view',
    ]);

    $deed = minutaDeed($office);
    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    $this->actingAs($actor)->postJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta", [
        'document_id' => $document->getKey(),
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['release_status', 'ARCHIVED'],
    ['archived_at', '2026-01-01T00:00:00Z'],
    ['notary_deed_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
]);

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('does not let a deed capability reach the minuta', function (): void {
    // `notary.minuta.*` is its own family: an actor who may read a deed does not
    // thereby read where its original is filed.
    [$actor, $office] = minutaActor(['notary.deeds.view', 'notary.deeds.update'], DataScope::ALL);

    $deed = minutaDeed($office);
    NotaryMinuta::factory()->forDeed($deed)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta")
        ->assertForbidden();
});

it('does not let a minuta capability reach the deed', function (): void {
    // The symmetric statement. Reaching a filing record says nothing about reading
    // the deed itself — and without `notary.deeds.view` the deed is a 404.
    [$actor, $office] = minutaActor(['notary.minuta.view', 'notary.minuta.update'], DataScope::ALL);

    $deed = minutaDeed($office);
    NotaryMinuta::factory()->forDeed($deed)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}")
        ->assertNotFound();
});

it('grants exactly the one act the capability names', function (string $held, string $ability): void {
    [$actor, $office] = minutaActor(['notary.deeds.view', $held]);

    $deed = minutaDeed($office);

    foreach (['viewMinuta', 'createMinuta', 'updateMinuta'] as $candidate) {
        expect(app(NotaryDeedPolicy::class)->{$candidate}($actor, $deed))->toBe($candidate === $ability);
    }
})->with([
    ['notary.minuta.view', 'viewMinuta'],
    ['notary.minuta.create', 'createMinuta'],
    ['notary.minuta.update', 'updateMinuta'],
]);

it('resolves minuta reach through the deed own predicates', function (): void {
    // A Minuta has no owner and no assignee: it is reached exactly as its deed is,
    // and the deed's OWN predicate resolves through the parent Matter (D-120).
    [$actor, $office] = minutaActor(['notary.deeds.view', 'notary.minuta.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    $mine = minutaDeed($office, createdBy: $actor);
    $theirs = minutaDeed($office, createdBy: $colleague);

    NotaryMinuta::factory()->forDeed($mine)->create();
    NotaryMinuta::factory()->forDeed($theirs)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$mine->getKey()}/minuta")->assertOk();
    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$theirs->getKey()}/minuta")->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| What M6.3 deliberately did not build
|--------------------------------------------------------------------------
*/

it('registers no new permission', function (): void {
    expect(app(PermissionRegistry::class)->all())->toHaveCount(177);
});

it('has no delete capability to authorize a delete', function (): void {
    // The M6.3 brief asked for a soft delete restricted to DRAFT. It would need both
    // a column the ERD omits and a code the catalogue does not have.
    expect(app(PermissionRegistry::class)->all())->not->toContain('notary.minuta.delete');
});

it('exposes no delete, archive or release ability', function (string $ability): void {
    // `archive` and `release` are canonical codes that stay unimplemented: the
    // trigger for both is open question four (D-120).
    expect(method_exists(NotaryDeedPolicy::class, $ability))->toBeFalse();
})->with(['deleteMinuta', 'archiveMinuta', 'releaseMinuta', 'restoreMinuta']);

it('names exactly three minuta routes', function (): void {
    $names = collect(app('router')->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_contains($name, 'minuta'))
        ->values()
        ->all();

    sort($names);

    expect($names)->toBe([
        'api.v1.notary.deeds.minuta.show',
        'api.v1.notary.deeds.minuta.store',
        'api.v1.notary.deeds.minuta.update',
    ]);
});

it('exposes no top-level minuta address', function (): void {
    // The M4.5 convention (D-105): no address reaches a Minuta without naming the
    // deed it belongs to, because a Minuta has no independent existence.
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'minuta'));

    foreach ($uris as $uri) {
        expect($uri)->toContain('deeds/{deed}/minuta');
    }
});

it('refuses DELETE on the minuta address', function (): void {
    [$actor, $office] = minutaActor(['notary.deeds.view', 'notary.minuta.view'], DataScope::ALL);

    $deed = minutaDeed($office);
    NotaryMinuta::factory()->forDeed($deed)->create();

    $this->actingAs($actor)->deleteJson("/api/v1/notary/deeds/{$deed->getKey()}/minuta")
        ->assertStatus(405);
});

it('offers the four assignable scopes for every minuta capability', function (string $code): void {
    expect(app(PermissionScopeRules::class)->allowedFor($code))->toBe([
        DataScope::OWN,
        DataScope::ASSIGNED,
        DataScope::OFFICE,
        DataScope::ALL,
    ]);
})->with([
    'notary.minuta.view',
    'notary.minuta.create',
    'notary.minuta.update',
    'notary.minuta.archive',
    'notary.minuta.release',
]);

it('improvises no audit store', function (): void {
    expect(Schema::hasTable('audit_logs'))->toBeFalse()
        ->and(Schema::hasTable('activities'))->toBeFalse()
        ->and(Schema::hasTable('notary_minuta_history'))->toBeFalse();
});
