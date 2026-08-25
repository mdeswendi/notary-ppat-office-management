<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\PpatDeed;
use App\Models\PpatWarkah;
use App\Models\PpatWarkahItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * An actor holding the named Warkah capabilities at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function warkahApiActor(array $permissions, DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * A PPAT Deed under a fresh Matter and Project in the given Office.
 */
function warkahDeedIn(Office $office): PpatDeed
{
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create([
        'office_id' => $office->getKey(),
        'domain' => MatterDomain::PPAT,
    ]);

    return PpatDeed::factory()->forMatter($matter)->create();
}

function warkahPartyIn(Office $office): Party
{
    return Party::factory()->create(['office_id' => $office->getKey()]);
}

function warkahDocumentIn(Office $office): Document
{
    return Document::factory()->create(['office_id' => $office->getKey()]);
}

/*
|--------------------------------------------------------------------------
| Starting a bundle — reading never does
|--------------------------------------------------------------------------
*/

it('answers 404 while the office has not started a bundle', function (): void {
    // **The M7.4 brief asked the read endpoint to create one.** A `view` capability
    // that silently writes is one nobody can reason about, and a read-only actor's
    // page load would insert a row. The 404 is the M6.3 convention for this shape.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertNotFound();

    expect(PpatWarkah::query()->count())->toBe(0);
});

it('starts the bundle on the first line, not on a read', function (): void {
    // There is no `ppat.warkah.create` in the catalogue, so composing the checklist is
    // what brings the bundle into existence.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")->assertNotFound();

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Fotokopi KTP Penjual',
        'title_en' => 'Copy of seller identity card',
    ])->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.status', 'INCOMPLETE');

    expect(PpatWarkah::query()->count())->toBe(1);
});

it('starts at most one bundle per deed', function (): void {
    // `UNIQUE (ppat_deed_id)` — one bundle per deed (lock section 8.3).
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    foreach (['Satu', 'Dua', 'Tiga'] as $title) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => $title,
            'title_en' => $title,
        ])->assertCreated();
    }

    expect(PpatWarkah::query()->count())->toBe(1);
    expect(PpatWarkahItem::query()->count())->toBe(3);
});

it('refuses the bundle without the warkah capability', function (): void {
    // **Reading a deed is not reading its supporting bundle.** The two families are
    // separate, and holding `ppat.deeds.view` reaches nothing here.
    [$actor, $office] = warkahApiActor(['ppat.deeds.view']);

    $deed = warkahDeedIn($office);
    PpatWarkah::factory()->forDeed($deed)->create();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertNotFound();
});

it('answers 404 for a deed in another office', function (): void {
    [$actor] = warkahApiActor(['ppat.warkah.view']);

    $elsewhere = warkahDeedIn(Office::factory()->create());
    PpatWarkah::factory()->forDeed($elsewhere)->create();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$elsewhere->getKey()}/warkah")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Completeness — counted, never judged
|--------------------------------------------------------------------------
*/

it('counts documents, never item statuses', function (): void {
    // **The M7 lock section 8.2, at the API.** A document being attached is
    // observable; "verified" would need an item-status vocabulary the ERD does not
    // define (O-041).
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    foreach (['Satu', 'Dua', 'Tiga', 'Empat'] as $title) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => $title,
            'title_en' => $title,
        ])->assertCreated();
    }

    // Four lines, nothing collected.
    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 0);

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertCreated();

    // One of four.
    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 25);
});

it('reads an empty bundle as 0 percent, not 100', function (): void {
    // A bundle nobody has listed anything for has collected nothing, and 100 would be
    // the most misleading answer available.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => 'UNDER_REVIEW',
    ])->assertOk()->assertJsonPath('data.completeness_percentage', 0);
});

it('falls when the last document on a line is detached', function (): void {
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();
    $document = warkahDocumentIn($office);

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => $document->getKey()],
    )->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 100);

    $this->actingAs($actor)->deleteJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents/{$document->getKey()}"
    )->assertNoContent();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 0);
});

it('recomputes when a line is removed', function (): void {
    // Both terms move: a line with no document raises the percentage when it goes.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    foreach (['Satu', 'Dua'] as $title) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => $title, 'title_en' => $title,
        ])->assertCreated();
    }

    $collected = PpatWarkahItem::query()->orderBy('sequence_no')->first();
    $empty = PpatWarkahItem::query()->orderByDesc('sequence_no')->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$collected->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 50);

    $this->actingAs($actor)->deleteJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$empty->getKey()}"
    )->assertNoContent();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.completeness_percentage', 100);
});

it('never derives the status from the percentage', function (): void {
    // `COMPLETE` does not follow from 100% and 100% does not require `COMPLETE` —
    // which of the two governs sufficiency is open question three.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()
        ->assertJsonPath('data.completeness_percentage', 100)
        // Still INCOMPLETE, because a percentage is not a decision.
        ->assertJsonPath('data.status', 'INCOMPLETE');
});

it('reports the fraction the percentage came from', function (): void {
    // A reader who sees *1 of 3 lines* understands what is being counted; one who sees
    // *33%* does not.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    foreach (['Satu', 'Dua', 'Tiga'] as $title) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => $title, 'title_en' => $title,
        ])->assertCreated();
    }

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertCreated();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items")
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.collected', 1)
        ->assertJsonPath('meta.completeness_percentage', 33);
});

/*
|--------------------------------------------------------------------------
| Status — settable, and not gated
|--------------------------------------------------------------------------
*/

it('sets the two statuses the update capability owns', function (string $status): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => $status,
    ])->assertOk()->assertJsonPath('data.status', $status);
})->with(['INCOMPLETE', 'UNDER_REVIEW']);

it('refuses complete through the update capability', function (): void {
    // `COMPLETE` comes with a stamped pair and answers to `ppat.warkah.verify`.
    // Accepting it here would let one capability perform an act a separate one was
    // granted to control (D-091).
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => 'COMPLETE',
    ])->assertStatus(422)->assertJsonValidationErrors('status');
});

it('refuses the two statuses nothing reaches', function (string $status): void {
    // Both codes are canonical; what is missing is the trigger. *"What are the
    // binding/archiving requirements for deeds and supporting Warkah?"* is open
    // question eight (D-064, O-041).
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.verify',
        'ppat.warkah.finalize', 'ppat.warkah.archive',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => $status,
    ])->assertStatus(422)->assertJsonValidationErrors('status');
})->with(['FINALIZED', 'ARCHIVED']);

it('enforces no transition matrix', function (): void {
    // The M7 lock section 8.2: *"Status is settable and not gated."* The two gates the
    // brief proposed — a minimum item count, and every item verified — are verification
    // rules, and open question three does not answer them. D-102 refused the same shape
    // on `MatterStatus`.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.verify',
    ]);

    $deed = warkahDeedIn($office);

    // Straight to UNDER_REVIEW with no items at all.
    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => 'UNDER_REVIEW',
    ])->assertOk();

    // And back again.
    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => 'INCOMPLETE',
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Verification
|--------------------------------------------------------------------------
*/

it('verifies a bundle and stamps the pair', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.verify']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETE')
        ->assertJsonPath('data.verified_by.id', $actor->getKey());

    $warkah = PpatWarkah::query()->first();

    expect($warkah->verified_at)->not->toBeNull();
    expect($warkah->verified_by)->toBe($actor->getKey());
    // Never written in M7 — the trigger is open question eight.
    expect($warkah->finalized_at)->toBeNull();
    expect($warkah->finalized_by)->toBeNull();
});

it('verifies an empty bundle, because completeness gates nothing', function (): void {
    // *"100% does not mean complete in law. It means every item this office listed has
    // a document."* Requiring 100% would assert the office's own checklist is the legal
    // requirement, which is open question three.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.verify']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETE')
        ->assertJsonPath('data.completeness_percentage', 0);
});

it('refuses verification without the verify capability', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/verify")
        ->assertForbidden();
});

it('keeps the verification stamp when the bundle goes back for review', function (): void {
    // Somebody did check it on that date; `CLAUDE.md` section 63 asks that facts not be
    // overwritten because the current state moved on.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.verify',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/verify")->assertOk();

    $this->actingAs($actor)->patchJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/status", [
        'status' => 'UNDER_REVIEW',
    ])->assertOk()->assertJsonPath('data.verified_by.id', $actor->getKey());
});

/*
|--------------------------------------------------------------------------
| Items — the office writes its own checklist
|--------------------------------------------------------------------------
*/

it('requires both titles, because they are bilingual database fields', function (array $body): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", $body)
        ->assertStatus(422);
})->with([
    [['title_id' => 'Satu']],
    [['title_en' => 'One']],
    [[]],
]);

it('accepts a line with no requirement code', function (): void {
    // **Inverts the brief.** `requirement_code` is stored and matched against nothing:
    // what it would match is a requirement template, and D-104 keeps those unbuilt.
    // Requiring a code that refers to no catalogue would make an office invent one.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Sertipikat asli',
        'title_en' => 'Original certificate',
    ])->assertCreated()->assertJsonPath('data.requirement_code', null);
});

it('refuses an item status, because the column has no vocabulary', function (string $status): void {
    // The brief specified six values and a default of MISSING. The ERD gives this
    // column no values at all, which is why M7.1 built no enum and why completeness
    // counts documents (O-041). Refused on presence, not silently dropped.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu',
        'title_en' => 'One',
        'status' => $status,
    ])->assertStatus(422)->assertJsonValidationErrors('status');
})->with(['MISSING', 'RECEIVED', 'VERIFIED', 'NOT_APPLICABLE']);

it('reports has_document instead of a status', function (): void {
    // What replaces the vocabulary the ERD never defined: the fact that is observable
    // and needs none.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    $response = $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    expect($response->json('data.has_document'))->toBeFalse();
    // The canonical column is exposed and always null.
    expect($response->json('data.status'))->toBeNull();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertCreated()->assertJsonPath('data.has_document', true);
});

it('orders new lines to the end of the checklist', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    foreach (['Satu', 'Dua', 'Tiga'] as $title) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => $title, 'title_en' => $title,
        ])->assertCreated();
    }

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items")->assertOk();

    expect(array_column($response->json('data'), 'title_id'))->toBe(['Satu', 'Dua', 'Tiga']);
});

it('corrects a line without reaching its bundle or its office', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Salah', 'title_en' => 'Wrong',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->patchJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}",
        ['title_id' => 'Benar', 'title_en' => 'Right'],
    )->assertOk()->assertJsonPath('data.title_id', 'Benar');

    foreach (['warkah_id', 'office_id', 'status'] as $field) {
        $this->actingAs($actor)->patchJson(
            "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}",
            [$field => (string) Str::ulid()],
        )->assertStatus(422)->assertJsonValidationErrors($field);
    }
});

it('names a party only when the caller may reach it', function (): void {
    // `ppat.warkah.update` is authority to compose a checklist, never authority to
    // discover which Parties exist.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'KTP', 'title_en' => 'ID card',
        'party_id' => warkahPartyIn($office)->getKey(),
    ])->assertStatus(422)->assertJsonValidationErrors('party_id');
});

it('gives an unreachable and a nonexistent party the same answer', function (): void {
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'parties.view',
    ]);

    $deed = warkahDeedIn($office);

    foreach ([warkahPartyIn(Office::factory()->create())->getKey(), (string) Str::ulid()] as $candidate) {
        $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => 'KTP', 'title_en' => 'ID card',
            'party_id' => $candidate,
        ])->assertStatus(422)->assertJsonValidationErrors('party_id');
    }
});

it('clears the party when the caller sends null, and leaves it when they omit it', function (): void {
    // `array_key_exists`, never `??`: a caller who sent null means "clear it" and one
    // who omitted the key means "leave it alone".
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'parties.view',
    ]);

    $deed = warkahDeedIn($office);
    $party = warkahPartyIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'KTP', 'title_en' => 'ID card',
        'party_id' => $party->getKey(),
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    // Omitted: unchanged.
    $this->actingAs($actor)->patchJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}",
        ['notes' => 'Diperiksa'],
    )->assertOk();

    expect($item->fresh()->party_id)->toBe($party->getKey());

    // Sent as null: cleared.
    $this->actingAs($actor)->patchJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}",
        ['party_id' => null],
    )->assertOk();

    expect($item->fresh()->party_id)->toBeNull();
});

it('removes a line and leaves the documents standing', function (): void {
    // A hard delete of the line and its junction rows — `ppat_warkah_items` has no
    // `deleted_at`. The Documents themselves are untouched.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);
    $document = warkahDocumentIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => $document->getKey()],
    )->assertCreated();

    $this->actingAs($actor)->deleteJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}"
    )->assertNoContent();

    expect(PpatWarkahItem::query()->count())->toBe(0);
    expect(Document::query()->find($document->getKey()))->not->toBeNull();
});

it('answers 404 for a line belonging to another deed', function (): void {
    // Scoped to the parent, so no address reaches a line without naming its deed.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $mine = warkahDeedIn($office);
    $other = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$other->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->patchJson(
        "/api/v1/ppat/deeds/{$mine->getKey()}/warkah/items/{$item->getKey()}",
        ['title_id' => 'Dicuri'],
    )->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Documents — attaching is not reading
|--------------------------------------------------------------------------
*/

it('refuses a document the caller cannot reach', function (): void {
    // `ppat.warkah.upload` never becomes a way to discover which files exist.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertStatus(422)->assertJsonValidationErrors('document_id');
});

it('refuses attaching without the upload capability', function (): void {
    // Writing down which documents a transaction needs is a different job from
    // producing them.
    //
    // **403, not 404.** The caller holds `ppat.warkah.view`, so the bundle is a thing
    // they can see; what they lack is authority over this act. Reachability is answered
    // by the resolver and authority by the Policy, and collapsing the two would tell a
    // legitimate reader that nothing is there.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->postJson(
        "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents",
        ['document_id' => warkahDocumentIn($office)->getKey()],
    )->assertForbidden();
});

it('answers 403 for an act the reader may not perform, and 404 for a bundle they cannot see', function (): void {
    // The distinction the whole surface turns on, pinned in one place.
    $office = Office::factory()->create();
    $deed = warkahDeedIn($office);

    // Holds `view`: the bundle is visible, the act is not theirs.
    $reader = User::factory()->for($office)->create();
    grantPermissionScope($reader, 'ppat.warkah.view', DataScope::OFFICE);

    $this->actingAs($reader->fresh())
        ->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => 'Satu', 'title_en' => 'One',
        ])->assertForbidden();

    // Holds no Warkah capability at all: the bundle is not a thing they can see, and
    // an unreachable one stays indistinguishable from a nonexistent one (D-098).
    $stranger = User::factory()->for($office)->create();
    grantPermissionScope($stranger, 'ppat.deeds.view', DataScope::OFFICE);

    $this->actingAs($stranger->fresh())
        ->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
            'title_id' => 'Satu', 'title_en' => 'One',
        ])->assertNotFound();
});

it('does not double-attach the same document to one line', function (): void {
    // The junction has a composite primary key `(warkah_item_id, document_id)`.
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.upload', 'documents.view',
    ]);

    $deed = warkahDeedIn($office);
    $document = warkahDocumentIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();
    $url = "/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items/{$item->getKey()}/documents";

    $this->actingAs($actor)->postJson($url, ['document_id' => $document->getKey()])->assertCreated();
    $this->actingAs($actor)->postJson($url, ['document_id' => $document->getKey()])->assertCreated();

    expect($item->fresh()->documents()->count())->toBe(1);
});

it('never carries party identity on a line', function (): void {
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'parties.view',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'KTP', 'title_en' => 'ID card',
        'party_id' => warkahPartyIn($office)->getKey(),
    ])->assertCreated();

    $body = $this->actingAs($actor)
        ->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items")->assertOk()->getContent();

    expect($body)->not->toContain('nik')->not->toContain('npwp');
});

/*
|--------------------------------------------------------------------------
| The list surface
|--------------------------------------------------------------------------
*/

it('lists only bundles whose deed the caller may reach', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view']);

    $mine = PpatWarkah::factory()->forDeed(warkahDeedIn($office))->create();
    PpatWarkah::factory()->forDeed(warkahDeedIn(Office::factory()->create()))->create();

    $this->actingAs($actor)->getJson('/api/v1/ppat/warkah')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->getKey())
        ->assertJsonPath('meta.total', 1);
});

it('finds the bundles that are still short', function (): void {
    // The one question a top-level surface answers that the deed page cannot.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view']);

    $short = PpatWarkah::factory()->forDeed(warkahDeedIn($office))->create();
    $short->forceFill(['completeness_percentage' => 40])->save();

    $full = PpatWarkah::factory()->forDeed(warkahDeedIn($office))->create();
    $full->forceFill(['completeness_percentage' => 100])->save();

    $this->actingAs($actor)->getJson('/api/v1/ppat/warkah?incomplete_only=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $short->getKey());
});

it('refuses the list without the capability', function (): void {
    [$actor] = warkahApiActor([]);

    $this->actingAs($actor)->getJson('/api/v1/ppat/warkah')->assertForbidden();
});

it('offers the three reachable statuses and names the two that are not', function (): void {
    [$actor] = warkahApiActor(['ppat.warkah.view']);

    $response = $this->actingAs($actor)->getJson('/api/v1/ppat/warkah/options')->assertOk();

    expect($response->json('data.statuses'))->toBe(['INCOMPLETE', 'UNDER_REVIEW', 'COMPLETE']);
    // Settable through `updateStatus`; COMPLETE is absent because it answers to
    // `verify` on its own endpoint.
    expect($response->json('data.settable_statuses'))->toBe(['INCOMPLETE', 'UNDER_REVIEW']);
    expect($response->json('data.unreachable_statuses'))->toBe(['FINALIZED', 'ARCHIVED']);
});

/*
|--------------------------------------------------------------------------
| Capability flags and routes that must not exist
|--------------------------------------------------------------------------
*/

it('reports every flag false for a view-only caller', function (): void {
    [$actor, $office] = warkahApiActor(['ppat.warkah.view']);

    $deed = warkahDeedIn($office);
    PpatWarkah::factory()->forDeed($deed)->create();

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")->assertOk();

    foreach (['can_manage', 'can_verify', 'can_upload'] as $flag) {
        expect($response->json("data.{$flag}"))->toBeFalse();
    }

    // No flag for an act with no route — offering one would invite a control that
    // cannot work.
    expect($response->json('data'))
        ->not->toHaveKey('can_finalize')
        ->not->toHaveKey('can_archive');
});

it('exposes no finalize or archive route', function (string $method, string $path): void {
    // Both codes are canonical and both stay unimplemented (D-064, O-041).
    [$actor, $office] = warkahApiActor([
        'ppat.warkah.view', 'ppat.warkah.update', 'ppat.warkah.verify',
        'ppat.warkah.finalize', 'ppat.warkah.archive',
    ]);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->json($method, "/api/v1/ppat/deeds/{$deed->getKey()}/warkah{$path}")
        ->assertNotFound();
})->with([
    ['POST', '/finalize'],
    ['POST', '/archive'],
    ['PATCH', '/finalize'],
]);

it('exposes no top-level warkah item address', function (): void {
    // A line has no existence apart from its bundle, and a bundle none apart from its
    // deed — the D-105 convention.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);

    $this->actingAs($actor)->postJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah/items", [
        'title_id' => 'Satu', 'title_en' => 'One',
    ])->assertCreated();

    $item = PpatWarkahItem::query()->first();

    $this->actingAs($actor)->patchJson("/api/v1/ppat/warkah/items/{$item->getKey()}", [
        'title_id' => 'Dicuri',
    ])->assertNotFound();

    $this->actingAs($actor)->deleteJson("/api/v1/ppat/warkah/items/{$item->getKey()}")
        ->assertNotFound();
});

it('exposes no delete route for a bundle', function (): void {
    // There is no `ppat.warkah.delete` in the catalogue.
    [$actor, $office] = warkahApiActor(['ppat.warkah.view', 'ppat.warkah.update']);

    $deed = warkahDeedIn($office);
    PpatWarkah::factory()->forDeed($deed)->create();

    $this->actingAs($actor)->deleteJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertStatus(405);
});

it('names no warkah route the catalogue could not authorize', function (): void {
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'api.v1.ppat.warkah.'))
        ->sort()
        ->values()
        ->all();

    // Every name here is checked against a canonical capability: `ppat.warkah.view`,
    // `.update`, `.verify` and `.upload` are four of the six the catalogue defines.
    //
    // **`ppat.warkah.finalize` and `.archive` are the other two, and no route names
    // either** — both are registered and unimplemented, because their trigger is open
    // question eight (D-064, O-041).
    expect($names)->toBe([
        'api.v1.ppat.warkah.index',
        'api.v1.ppat.warkah.items.destroy',
        'api.v1.ppat.warkah.items.documents.destroy',
        'api.v1.ppat.warkah.items.documents.store',
        'api.v1.ppat.warkah.items.index',
        'api.v1.ppat.warkah.items.store',
        'api.v1.ppat.warkah.items.update',
        'api.v1.ppat.warkah.options',
        'api.v1.ppat.warkah.show',
        'api.v1.ppat.warkah.status',
        'api.v1.ppat.warkah.verify',
    ]);
});

it('keeps the two unreachable statuses storable and unreached', function (): void {
    // Stored vocabulary with no code path (D-121 section 12): a row written directly to
    // the database carries one, and the enum renders it, but nothing in the product
    // sets it.
    expect(PpatWarkahStatus::unreachable())
        ->toBe([PpatWarkahStatus::FINALIZED, PpatWarkahStatus::ARCHIVED]);

    [$actor, $office] = warkahApiActor(['ppat.warkah.view']);

    $deed = warkahDeedIn($office);
    $warkah = PpatWarkah::factory()->forDeed($deed)->create();
    $warkah->forceFill(['status' => PpatWarkahStatus::FINALIZED->value])->save();

    $this->actingAs($actor)->getJson("/api/v1/ppat/deeds/{$deed->getKey()}/warkah")
        ->assertOk()->assertJsonPath('data.status', 'FINALIZED');
});
