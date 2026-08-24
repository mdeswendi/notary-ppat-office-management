<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Document;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\NotaryMatter;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding the named deed capabilities at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function deedApiActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

function notaryMatterIn(Office $office, ?User $createdBy = null, ?User $pic = null): Matter
{
    return Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
        'created_by' => $createdBy?->getKey(),
        'pic_user_id' => $pic?->getKey(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('lists only deeds the caller may reach', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $mine = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();
    NotaryDeed::factory()->forMatter(notaryMatterIn(Office::factory()->create()))->create();

    $response = $this->actingAs($actor)->getJson('/api/v1/notary/deeds');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->getKey())
        // The total counts only what the caller may see; no filter can widen it.
        ->assertJsonPath('meta.total', 1);
});

it('refuses the list without the capability', function (): void {
    [$actor] = deedApiActor([]);

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds')->assertForbidden();
});

it('answers 404 for a deed the caller cannot reach', function (): void {
    // Not 403: a 403 would confirm the record exists somewhere the caller may not
    // look (the D-098 convention).
    [$actor] = deedApiActor(['notary.deeds.view']);

    $elsewhere = NotaryDeed::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$elsewhere->getKey()}")
        ->assertNotFound();
});

it('filters by status, matter and deed date', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $matter = notaryMatterIn($office);
    $other = notaryMatterIn($office);

    $approved = NotaryDeed::factory()->forMatter($matter)->approved()->create(['deed_date' => '2026-03-01']);
    NotaryDeed::factory()->forMatter($other)->create(['deed_date' => '2026-09-01']);

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?status=APPROVED')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->getKey());

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?matter_id={$matter->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?deed_date_from=2026-06-01')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('ignores an unrecognised filter rather than erroring', function (): void {
    // A stale bookmark should show the unfiltered list, not a 422.
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?status=NONSENSE')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('searches title and deed number without escaping visibility', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $found = NotaryDeed::factory()->forMatter(notaryMatterIn($office))
        ->numbered('UJI-XYZ')->create(['title' => 'Akta Uji Satu']);
    NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create(['title' => 'Lainnya']);

    // A deed in another Office carrying the same number must not surface.
    NotaryDeed::factory()->forMatter(notaryMatterIn(Office::factory()->create()))
        ->numbered('UJI-XYZ')->create();

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?search=UJI-XYZ')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $found->getKey());
});

/*
|--------------------------------------------------------------------------
| What the payload does and does not carry
|--------------------------------------------------------------------------
*/

it('carries the matter stub and the deed own document pointers', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $matter = notaryMatterIn($office);
    $document = Document::factory()->create(['office_id' => $office->getKey()]);

    $deed = NotaryDeed::factory()->forMatter($matter)->create([
        'draft_document_id' => $document->getKey(),
    ]);

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.matter.id', $matter->getKey())
        ->assertJsonPath('data.matter.domain', 'NOTARY')
        ->assertJsonPath('data.draft_document.id', $document->getKey())
        ->assertJsonPath('data.final_document', null);
});

it('carries no parties and no tasks', function (): void {
    // Participation answers to `notary.matters.parties.view` and tasks to
    // `tasks.view`, each with its own Data Scope. Embedding either would make a deed
    // capability a way to read them (D-105, M5.4).
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}");

    $response->assertOk();

    expect($response->json('data'))
        ->not->toHaveKey('parties')
        ->not->toHaveKey('tasks')
        ->not->toHaveKey('documents')
        ->not->toHaveKey('register_entry')
        ->not->toHaveKey('protocol');
});

it('never carries party identity', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $body = $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}")
        ->assertOk()->getContent();

    expect($body)->not->toContain('nik')->not->toContain('npwp');
});

/*
|--------------------------------------------------------------------------
| Creation
|--------------------------------------------------------------------------
*/

it('records a deed against a reachable notary matter', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.create', 'notary.deeds.view', 'notary.matters.view']);

    $matter = notaryMatterIn($office);

    $response = $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
        'matter_id' => $matter->getKey(),
        'title' => 'Akta Uji',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.deed_number', null)
        ->assertJsonPath('data.matter.id', $matter->getKey());

    // Office is inherited from the Matter, never accepted from the caller.
    expect(NotaryDeed::query()->first()->office_id)->toBe($office->getKey());
});

it('refuses creation against a matter the caller cannot reach', function (): void {
    // `notary.deeds.create` is authority to record a deed, never authority to
    // discover which Matters exist (D-118's two-question rule).
    [$actor] = deedApiActor(['notary.deeds.create', 'notary.matters.view']);

    $elsewhere = notaryMatterIn(Office::factory()->create());

    $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
        'matter_id' => $elsewhere->getKey(),
        'title' => 'Akta Uji',
    ])->assertStatus(422)->assertJsonValidationErrors('matter_id');
});

it('gives an unreachable, ppat and nonexistent matter the same answer', function (): void {
    // One indistinguishable field error, so no request shape turns this endpoint
    // into a probe.
    [$actor, $office] = deedApiActor(['notary.deeds.create', 'notary.matters.view']);

    $ppat = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    foreach ([$ppat->getKey(), (string) Str::ulid()] as $candidate) {
        $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
            'matter_id' => $candidate,
            'title' => 'Akta Uji',
        ])->assertStatus(422)->assertJsonValidationErrors('matter_id');
    }
});

it('refuses a deed number at creation', function (): void {
    // It answers to `notary.deeds.number` on its own endpoint. Accepting it here
    // would let `notary.deeds.create` perform an act a separate capability controls.
    [$actor, $office] = deedApiActor(['notary.deeds.create', 'notary.matters.view']);

    $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
        'matter_id' => notaryMatterIn($office)->getKey(),
        'title' => 'Akta Uji',
        'deed_number' => 'UJI-1',
    ])->assertStatus(422)->assertJsonValidationErrors('deed_number');
});

it('refuses every system-controlled field on presence', function (string $field, mixed $value): void {
    [$actor, $office] = deedApiActor(['notary.deeds.create', 'notary.matters.view']);

    $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
        'matter_id' => notaryMatterIn($office)->getKey(),
        'title' => 'Akta Uji',
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['status', 'APPROVED'],
    ['office_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ['approved_at', '2026-01-01T00:00:00Z'],
    ['locked_at', '2026-01-01T00:00:00Z'],
]);

it('lets a deed be created without a date or a type code', function (): void {
    // A deed being drafted has not been executed, and no deed type catalogue exists.
    [$actor, $office] = deedApiActor(['notary.deeds.create', 'notary.matters.view']);

    $this->actingAs($actor)->postJson('/api/v1/notary/deeds', [
        'matter_id' => notaryMatterIn($office)->getKey(),
        'title' => 'Akta Uji',
    ])->assertCreated()
        ->assertJsonPath('data.deed_date', null)
        ->assertJsonPath('data.deed_type_code', null);
});

/*
|--------------------------------------------------------------------------
| The lifecycle ladder
|--------------------------------------------------------------------------
*/

it('walks a deed from draft to finalized', function (): void {
    [$actor, $office] = deedApiActor([
        'notary.deeds.view', 'notary.deeds.review', 'notary.deeds.approve', 'notary.deeds.finalize',
    ]);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();
    $id = $deed->getKey();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$id}/review")
        ->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW')
        ->assertJsonPath('data.reviewed_by.id', $actor->getKey());

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$id}/approve")
        ->assertOk()->assertJsonPath('data.status', 'APPROVED');

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$id}/finalize")
        ->assertOk()->assertJsonPath('data.status', 'FINALIZED')
        ->assertJsonPath('data.is_read_only', true);
});

it('refuses an act the status does not permit, with 422 not 403', function (string $act): void {
    // The caller is authorized and would succeed on a deed in a different state, so
    // a 403 would send them to ask for a permission that would not help.
    [$actor, $office] = deedApiActor([
        'notary.deeds.view', 'notary.deeds.review', 'notary.deeds.approve', 'notary.deeds.finalize',
    ]);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/{$act}")
        ->assertStatus(422);
})->with(['approve', 'finalize']);

it('refuses to review a deed twice', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.review']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->reviewed()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/review")
        ->assertStatus(422);
});

it('does not let one lifecycle capability perform another', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.review']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->reviewed()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/approve")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Finalization does three things it was asked to do and does not
|--------------------------------------------------------------------------
*/

it('finalizes without assigning a deed number', function (): void {
    // "Set deed_number jika belum" asserts *when* a deed is numbered, which is half
    // of open question one. `notary.deeds.number` is its own capability (D-120).
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.finalize']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->approved()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', 'FINALIZED')
        ->assertJsonPath('data.deed_number', null);
});

it('finalizes without writing locked_at', function (): void {
    // Who locks a deed and under what conditions is the correction-mechanism
    // question, and there is no `notary.deeds.lock` capability.
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.finalize']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->approved()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/finalize")
        ->assertOk()->assertJsonPath('data.locked_at', null);
});

it('finalizes without creating a register entry', function (): void {
    // `requires_register_entry` is stored and branches on nothing (M6.1). The
    // register procedure is open question two and the table is batch 11.
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.finalize']);

    $matter = notaryMatterIn($office);
    NotaryMatter::factory()->forMatter($matter)->create(['requires_register_entry' => true]);

    $deed = NotaryDeed::factory()->forMatter($matter)->approved()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/finalize")->assertOk();

    expect(Schema::hasTable('notary_register_entries'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Editing, and what finalization forbids
|--------------------------------------------------------------------------
*/

it('edits a deed the status still permits', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.update']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}", [
        'title' => 'Akta Uji Diperbarui',
        'deed_date' => '2026-05-05',
    ])->assertOk()
        ->assertJsonPath('data.title', 'Akta Uji Diperbarui')
        ->assertJsonPath('data.deed_date', '2026-05-05');
});

it('still permits editing an approved deed', function (): void {
    // CLAUDE.md section 29 denies normal updates *once finalized* and says nothing
    // about approval. The narrower rule the brief asked for — approval freezes the
    // content — is an approval requirement, which section 62 forbids inventing.
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.update']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->approved()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}", [
        'title' => 'Masih boleh',
    ])->assertOk();
});

it('refuses to edit a finalized deed', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.update'], DataScope::ALL);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->finalized()->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}", [
        'title' => 'Tidak boleh',
    ])->assertForbidden();
});

it('refuses to move a deed to another matter', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.update']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}", [
        'matter_id' => notaryMatterIn($office)->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('matter_id');
});

/*
|--------------------------------------------------------------------------
| Numbering
|--------------------------------------------------------------------------
*/

it('records a deed number in any status, in whatever format the office uses', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.number']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/number", [
        'deed_number' => '12 / VIII / 2026',
    ])->assertOk()->assertJsonPath('data.deed_number', '12 / VIII / 2026');
});

it('refuses a number another deed in the same office already holds', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.number']);

    $matter = notaryMatterIn($office);
    NotaryDeed::factory()->forMatter($matter)->numbered('UJI-1')->create();
    $second = NotaryDeed::factory()->forMatter($matter)->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$second->getKey()}/number", [
        'deed_number' => 'UJI-1',
    ])->assertStatus(422)->assertJsonValidationErrors('deed_number');
});

it('lets a deed keep the number it already has', function (): void {
    // Re-recording the same number is not a false conflict.
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.number']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->numbered('UJI-1')->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/number", [
        'deed_number' => 'UJI-1',
    ])->assertOk();
});

it('does not let finalize reach numbering', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.finalize']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->patchJson("/api/v1/notary/deeds/{$deed->getKey()}/number", [
        'deed_number' => 'UJI-1',
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Capability flags
|--------------------------------------------------------------------------
*/

it('folds status eligibility into the flags', function (): void {
    [$actor, $office] = deedApiActor([
        'notary.deeds.view', 'notary.deeds.update', 'notary.deeds.review',
        'notary.deeds.approve', 'notary.deeds.finalize', 'notary.deeds.number',
    ]);

    $draft = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$draft->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_review', true)
        ->assertJsonPath('data.can_approve', false)
        ->assertJsonPath('data.can_finalize', false)
        // Numbering is deliberately not folded: tying it to a lifecycle position
        // would answer open question one.
        ->assertJsonPath('data.can_record_number', true);
});

it('turns can_update off on a finalized deed', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.deeds.update'], DataScope::ALL);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->finalized()->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}")
        ->assertOk()->assertJsonPath('data.can_update', false);
});

it('reports every flag false for a view-only caller', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds/{$deed->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.can_review', false)
        ->assertJsonPath('data.can_approve', false)
        ->assertJsonPath('data.can_finalize', false)
        ->assertJsonPath('data.can_record_number', false);
});

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/

it('offers only the four reachable statuses', function (): void {
    // VOID and SUPERSEDED are canonical vocabulary no code path produces (D-120), so
    // offering them would invite a filter that always returns nothing.
    [$actor] = deedApiActor(['notary.deeds.view']);

    $this->actingAs($actor)->getJson('/api/v1/notary/deeds/options')
        ->assertOk()
        ->assertJsonPath('data.statuses', ['DRAFT', 'UNDER_REVIEW', 'APPROVED', 'FINALIZED']);
});

it('offers only matters creation would accept', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view', 'notary.matters.view'], DataScope::ALL);

    $mine = notaryMatterIn($office);
    notaryMatterIn(Office::factory()->create());

    // Own Office only, because that is what the create Policy accepts even for an
    // ALL-scoped actor — offering the rest would be a dead control.
    $response = $this->actingAs($actor)->getJson('/api/v1/notary/deeds/options')->assertOk();

    expect($response->json('data.matters'))->toHaveCount(1)
        ->and($response->json('data.matters.0.id'))->toBe($mine->getKey());
});

/*
|--------------------------------------------------------------------------
| What has no route
|--------------------------------------------------------------------------
*/

it('exposes no delete, void or lock route', function (string $method, string $suffix, int $expected): void {
    // `notary_deeds` has no `deleted_at`, and the catalogue has no
    // `notary.deeds.delete`, `.void` or `.lock` code. The brief asked for a soft
    // delete and its own constraints — no migration, no new permission — rule it
    // out (D-120).
    //
    // The two expected statuses say something slightly different, and both are
    // asserted rather than blurred into one: `DELETE` on the deed path is **405**
    // because the path exists and the verb does not, while `/void` and `/lock` are
    // **404** because the paths were never registered at all.
    [$actor, $office] = deedApiActor(['notary.deeds.view'], DataScope::ALL);

    $deed = NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $this->actingAs($actor)->json($method, "/api/v1/notary/deeds/{$deed->getKey()}{$suffix}")
        ->assertStatus($expected);
})->with([
    ['DELETE', '', 405],
    ['PATCH', '/void', 404],
    ['PATCH', '/lock', 404],
]);

it('names no deed route the catalogue could not authorize', function (): void {
    // Asserted against the route collection rather than only over HTTP, so a route
    // added later is caught even if no test exercises its path.
    $names = collect(app('router')->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'api.v1.notary.deeds.'))
        ->values()
        ->all();

    sort($names);

    // **Extended at M6.3**, which nests the three Minuta routes under the deed
    // (D-105's convention: no address reaches a Minuta without naming its deed). The
    // guard fired on them, which is what it is for — every name here is checked
    // against a canonical capability, and `notary.minuta.view`, `.create` and
    // `.update` are three of the five the catalogue defines.
    //
    // The two it does **not** define — a `delete` for either family — still have no
    // route, and `notary.minuta.archive` and `.release` have none either because
    // their trigger is an open domain question (D-120).
    expect($names)->toBe([
        'api.v1.notary.deeds.approve',
        'api.v1.notary.deeds.finalize',
        'api.v1.notary.deeds.index',
        'api.v1.notary.deeds.minuta.show',
        'api.v1.notary.deeds.minuta.store',
        'api.v1.notary.deeds.minuta.update',
        'api.v1.notary.deeds.number',
        'api.v1.notary.deeds.options',
        'api.v1.notary.deeds.review',
        'api.v1.notary.deeds.show',
        'api.v1.notary.deeds.store',
        'api.v1.notary.deeds.update',
    ]);
});

it('registers no ppat deed route', function (): void {
    // PPAT deeds are a different table in a different milestone.
    [$actor] = deedApiActor(['notary.deeds.view']);

    $this->actingAs($actor)->getJson('/api/v1/ppat/deeds')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The Project filter (O-037)
|--------------------------------------------------------------------------
*/

it('filters deeds by the project their matter belongs to', function (): void {
    // A deed has no `project_id`; the filter correlates through `matter_id`. This is
    // what the Project detail page asks, and it is a filter rather than a nested
    // route for the reason D-118 gave: one question, one surface.
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $project = Project::factory()->for($office)->create();
    $other = Project::factory()->for($office)->create();

    $matterA = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);
    $matterB = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);
    $elsewhere = Matter::factory()->for($other)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    // Two deeds across two Matters of one Project, one under a different Project.
    NotaryDeed::factory()->forMatter($matterA)->create();
    NotaryDeed::factory()->forMatter($matterB)->create();
    NotaryDeed::factory()->forMatter($elsewhere)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$project->getKey()}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('combines the project filter with the matter and status filters', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $project = Project::factory()->for($office)->create();

    $matterA = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);
    $matterB = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);

    NotaryDeed::factory()->forMatter($matterA)->approved()->create();
    NotaryDeed::factory()->forMatter($matterA)->create();
    NotaryDeed::factory()->forMatter($matterB)->create();

    $project = $project->getKey();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$project}&matter_id={$matterA->getKey()}")
        ->assertOk()->assertJsonPath('meta.total', 2);

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$project}&status=APPROVED")
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('never widens what the actor may see', function (): void {
    // The point of it being a filter: it narrows within visibility and can never
    // reach past it. An actor whose scope excludes a deed sees nothing more by
    // naming its Project — which is also why the filter needs no `projects.view`
    // check of its own.
    [$actor, $office] = deedApiActor(['notary.deeds.view'], DataScope::OWN);

    $colleague = User::factory()->for($office)->create();

    $project = Project::factory()->for($office)->create();

    $mine = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
        'created_by' => $actor->getKey(),
    ]);
    $theirs = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
        'created_by' => $colleague->getKey(),
    ]);

    $reachable = NotaryDeed::factory()->forMatter($mine)->create();
    NotaryDeed::factory()->forMatter($theirs)->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$project->getKey()}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $reachable->getKey());
});

it('returns nothing for a project in another office', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    $farProject = Project::factory()->for(Office::factory()->create())->create();

    $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$farProject->getKey()}")
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('shows no PPAT matter deeds, because there are none to show', function (): void {
    // `notary_deeds` rows are only ever created against NOTARY Matters — the Policy
    // refuses a PPAT parent — so a Project holding both domains yields only its
    // Notary output here. PPAT deeds are a different table in M7.
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    $project = Project::factory()->for($office)->create();

    $notary = Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::NOTARY,
    ]);
    Matter::factory()->for($project)->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    NotaryDeed::factory()->forMatter($notary)->create();

    $response = $this->actingAs($actor)->getJson("/api/v1/notary/deeds?project_id={$project->getKey()}")
        ->assertOk()->assertJsonPath('meta.total', 1);

    expect($response->json('data.0.matter.domain'))->toBe('NOTARY');
});

it('ignores a blank or nonexistent project id rather than erroring', function (): void {
    [$actor, $office] = deedApiActor(['notary.deeds.view']);

    NotaryDeed::factory()->forMatter(notaryMatterIn($office))->create();

    // Blank is no filter at all — a stale bookmark shows the unfiltered list.
    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?project_id=')
        ->assertOk()->assertJsonPath('meta.total', 1);

    // A project that does not exist matches nothing, and is not a 422.
    $this->actingAs($actor)->getJson('/api/v1/notary/deeds?project_id=01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertOk()->assertJsonPath('meta.total', 0);
});

it('exposes no nested project deeds route', function (): void {
    // D-118: one question, one surface. `GET /projects/{project}/notary-deeds` would
    // be a second address for what `?project_id=` already answers, and the first
    // divergence between them would be a bug (O-037).
    $uris = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'projects/{project}'));

    foreach ($uris as $uri) {
        expect($uri)->not->toContain('deeds');
    }
});
