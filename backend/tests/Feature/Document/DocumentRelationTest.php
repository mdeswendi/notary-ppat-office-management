<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Document\Enums\DocumentRelationType;
use App\Domains\Matter\Enums\MatterDomain;
use App\Models\Document;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * An actor holding the named permissions at one scope, in a fresh Office.
 *
 * Named distinctly rather than reusing `documentManager` from the M5.2 suite: a
 * function declared in a Pest file is a global PHP function, and two files
 * declaring the same name is a fatal error — the collision M4.5 hit.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function relationActor(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * Everything an office worker managing document filing would hold.
 *
 * @return array<int, string>
 */
function relationCapabilities(): array
{
    return [
        'documents.view', 'documents.update',
        'parties.view', 'projects.view',
        'notary.matters.view', 'ppat.matters.view',
    ];
}

function matterInOffice(Office $office, MatterDomain $domain = MatterDomain::NOTARY): Matter
{
    return Matter::factory()
        ->for(Project::factory()->for($office)->create())
        ->domain($domain)
        ->create();
}

/*
|--------------------------------------------------------------------------
| Attach
|--------------------------------------------------------------------------
*/

it('attaches a document to each buildable relation type', function (string $type, string $group): void {
    // `$group` is passed rather than derived: "party" pluralizes to "parties",
    // and building the key as `$type . 's'` produced `partys` — a test that
    // failed for its own spelling rather than for the product's behaviour.
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $target = match ($type) {
        'party' => Party::factory()->individual()->for($office)->create(),
        'project' => Project::factory()->for($office)->create(),
        'matter' => matterInOffice($office),
    };

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => $type,
            'entity_id' => $target->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath("data.{$group}.0.id", $target->getKey())
        ->assertJsonPath("data.{$group}.0.entity_type", $type);

    $table = DocumentRelationType::from($type)->junction();

    expect($table::query()->count())->toBe(1);
})->with([
    ['party', 'parties'],
    ['project', 'projects'],
    ['matter', 'matters'],
]);

it('writes the office carrier from the document, never from the request', function (): void {
    // The two composite foreign keys resolve through this column, so one source
    // means they cannot disagree with each other (D-116).
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
            // Ignored: the carrier is not request input.
            'office_id' => Office::factory()->create()->getKey(),
        ])
        ->assertCreated();

    $row = DB::table('project_documents')->first();

    expect($row->office_id)->toBe($office->getKey())
        ->and($row->attached_by)->toBe($actor->getKey())
        ->and($row->attached_at)->not->toBeNull();
});

it('refuses a second attachment of the same pair', function (): void {
    // A surface rule, not a schema one: the junction carries no
    // UNIQUE (owner_id, document_id), because M5.1 declined to invent a
    // cardinality rule (D-116). D-110 said what to do instead — state it and
    // validate it — which is what this is.
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    $payload = ['entity_type' => 'project', 'entity_id' => $project->getKey()];

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertCreated();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertStatus(422);

    expect(DB::table('project_documents')->count())->toBe(1);
});

it('keeps the schema open even though the surface refuses duplicates', function (): void {
    // The point of the previous test's distinction, made explicit: a direct
    // insert of a second row succeeds, so no migration blocks an office that
    // later needs one deliberately.
    $office = Office::factory()->create();
    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    foreach (range(1, 2) as $ignored) {
        DB::table('project_documents')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $project->getKey(),
            'document_id' => $document->getKey(),
            'office_id' => $office->getKey(),
            'attached_by' => null,
            'attached_at' => now(),
        ]);
    }

    expect(DB::table('project_documents')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The two capabilities attaching needs
|--------------------------------------------------------------------------
*/

it('refuses attaching without documents.update', function (): void {
    [$actor, $office] = relationActor(['documents.view', 'projects.view']);

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertForbidden();

    expect(DB::table('project_documents')->count())->toBe(0);
});

it('refuses attaching to a record the caller cannot reach', function (): void {
    // `documents.update` is authority over a document's filing; it is never
    // authority to discover which records exist.
    [$actor, $office] = relationActor(['documents.view', 'documents.update']);

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertStatus(422);

    expect(DB::table('project_documents')->count())->toBe(0);
});

it('refuses a matter reached under the other domain\'s capability', function (): void {
    // The namespace comes from the Matter's own `domain` column, so holding only
    // the Notary code cannot reach a PPAT Matter. The caller supplies an id and
    // nothing else — this is the stricter check, not the D-101 hazard.
    [$actor, $office] = relationActor([
        'documents.view', 'documents.update', 'notary.matters.view',
    ]);

    $document = Document::factory()->inOffice($office)->create();
    $ppatMatter = matterInOffice($office, MatterDomain::PPAT);
    $notaryMatter = matterInOffice($office, MatterDomain::NOTARY);

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'matter',
            'entity_id' => $ppatMatter->getKey(),
        ])
        ->assertStatus(422);

    // The same actor reaches a Notary Matter, so the refusal above is the domain
    // check rather than a broken fixture.
    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'matter',
            'entity_id' => $notaryMatter->getKey(),
        ])
        ->assertCreated();
});

it('refuses a target in another office, even for an ALL-scoped actor', function (): void {
    // ALL is reach over records that already exist, never authority to redefine
    // which Office owns what.
    [$actor, $office] = relationActor(relationCapabilities(), DataScope::ALL);

    $document = Document::factory()->inOffice($office)->create();
    $elsewhere = Project::factory()->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $elsewhere->getKey(),
        ])
        ->assertStatus(422);

    expect(DB::table('project_documents')->count())->toBe(0);
});

it('refuses a soft-deleted target', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();
    $project->delete();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertStatus(422);
});

it('requires the sensitive capability to file a sensitive document', function (): void {
    // Attaching runs through `documents.update`, which applies the sensitivity
    // condition on top of reach (D-115).
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->sensitive()->create();
    $project = Project::factory()->for($office)->create();

    $payload = ['entity_type' => 'project', 'entity_id' => $project->getKey()];

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertForbidden();

    grantPermissionScope($actor, 'documents.sensitive.view', DataScope::OFFICE);

    $this->actingAs($actor->fresh())
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| The four blocked types
|--------------------------------------------------------------------------
*/

it('refuses a relation type whose junction does not exist', function (string $type): void {
    // `property`, `notary_deed` and `ppat_deed` are recommended by
    // 03_DATABASE_ERD.md section 14 and their tables belong to M6 and M7. A
    // composite foreign key cannot point at a table that is not there, so they
    // are refused by the enum with a field error rather than stubbed (D-115).
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => $type,
            'entity_id' => (string) Str::ulid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entity_type');
})->with(['property', 'notary_deed', 'ppat_deed', 'matter_requirement', 'deed']);

it('names exactly the three buildable relation types', function (): void {
    expect(DocumentRelationType::values())->toBe(['party', 'project', 'matter']);
});

it('builds no junction table for a target that does not exist', function (string $table): void {
    expect(Schema::hasTable($table))->toBeFalse($table);
})->with([
    'property_documents', 'notary_deed_documents',
    'ppat_deed_documents', 'matter_requirement_documents',
]);

/*
|--------------------------------------------------------------------------
| Detach
|--------------------------------------------------------------------------
*/

it('detaches without touching the document', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();
    $payload = ['entity_type' => 'project', 'entity_id' => $project->getKey()];

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertCreated();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/documents/{$document->getKey()}/relations", $payload)
        ->assertOk()
        ->assertJsonPath('data.projects', []);

    expect(DB::table('project_documents')->count())->toBe(0)
        // The document survives untouched — that is the whole reason attachment
        // lives in a junction rather than as a column.
        ->and(Document::query()->whereKey($document->getKey())->exists())->toBeTrue()
        ->and(Project::query()->whereKey($project->getKey())->exists())->toBeTrue();
});

it('refuses to detach an attachment that does not exist', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    $this->actingAs($actor)
        ->deleteJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertStatus(422);
});

it('removes every duplicate row in one detach', function (): void {
    // The schema permits duplicates even though the attach surface refuses to
    // create one, so detaching once must leave nothing behind rather than require
    // the caller to click until the list empties.
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    foreach (range(1, 3) as $ignored) {
        DB::table('project_documents')->insert([
            'id' => (string) Str::ulid(),
            'project_id' => $project->getKey(),
            'document_id' => $document->getKey(),
            'office_id' => $office->getKey(),
            'attached_by' => null,
            'attached_at' => now(),
        ]);
    }

    $this->actingAs($actor)
        ->deleteJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertOk();

    expect(DB::table('project_documents')->count())->toBe(0);
});

it('refuses detaching without documents.update', function (): void {
    [$actor, $office] = relationActor(['documents.view', 'projects.view']);

    $document = Document::factory()->inOffice($office)->create();
    $project = Project::factory()->for($office)->create();

    DB::table('project_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'document_id' => $document->getKey(),
        'office_id' => $office->getKey(),
        'attached_by' => null,
        'attached_at' => now(),
    ]);

    $this->actingAs($actor)
        ->deleteJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'project',
            'entity_id' => $project->getKey(),
        ])
        ->assertForbidden();

    expect(DB::table('project_documents')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Reading relations
|--------------------------------------------------------------------------
*/

it('lists every attachment grouped by type', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $party = Party::factory()->individual()->for($office)->create();
    $project = Project::factory()->for($office)->create();
    $matter = matterInOffice($office);

    foreach ([
        ['party', $party->getKey()],
        ['project', $project->getKey()],
        ['matter', $matter->getKey()],
    ] as [$type, $id]) {
        $this->actingAs($actor)
            ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
                'entity_type' => $type,
                'entity_id' => $id,
            ])
            ->assertCreated();
    }

    $response = $this->actingAs($actor)
        ->getJson("/api/v1/documents/{$document->getKey()}/relations")
        ->assertOk();

    expect($response->json('data.parties'))->toHaveCount(1)
        ->and($response->json('data.projects'))->toHaveCount(1)
        ->and($response->json('data.matters'))->toHaveCount(1)
        ->and($response->json('data.matters.0.domain'))->toBe('NOTARY')
        ->and($response->json('data.projects.0.reference'))->toBe($project->project_number);
});

it('exposes no party identity and no office carrier in the relation list', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    $this->actingAs($actor)
        ->postJson("/api/v1/documents/{$document->getKey()}/relations", [
            'entity_type' => 'party',
            'entity_id' => $party->getKey(),
        ])
        ->assertCreated();

    $body = $this->actingAs($actor)
        ->getJson("/api/v1/documents/{$document->getKey()}/relations")
        ->assertOk()
        ->getContent();

    // Identifiers are redacted first: a lowercased ULID is Crockford base32 and
    // can legitimately spell `npwp` — the collision that made an M4 guard flaky.
    $redacted = strtolower((string) preg_replace('/"[0-9a-z]{26}"/', '"[ulid]"', (string) $body));

    foreach (['nik', 'npwp', 'tax_id', 'office_id'] as $absent) {
        expect(str_contains($redacted, $absent))->toBeFalse($absent);
    }
});

it('requires documents.view to read relations', function (): void {
    [$actor, $office] = relationActor([]);

    $document = Document::factory()->inOffice($office)->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/documents/{$document->getKey()}/relations")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Document reachability
|--------------------------------------------------------------------------
*/

it('answers 404 for a document in another office', function (): void {
    [$actor] = relationActor(relationCapabilities());

    $elsewhere = Document::factory()->create();

    $this->actingAs($actor)
        ->getJson("/api/v1/documents/{$elsewhere->getKey()}/relations")
        ->assertNotFound();
});

it('answers 404 for a soft-deleted document', function (): void {
    [$actor, $office] = relationActor(relationCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $document->delete();

    $this->actingAs($actor)
        ->getJson("/api/v1/documents/{$document->getKey()}/relations")
        ->assertNotFound();
});

it('refuses every relation endpoint to a guest', function (string $method): void {
    $id = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

    $this->{$method}("/api/v1/documents/{$id}/relations", [])->assertUnauthorized();
})->with(['getJson', 'postJson', 'deleteJson']);

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

it('improvises no audit store', function (): void {
    // D-115: no half-measure ships. An application log is not append-only in the
    // sense CLAUDE.md section 31 means, is not queryable by resource, and is the
    // stopgap that becomes permanent. `attached_by` and `attached_at` record who
    // and when on the row; the event record waits for the store built to hold it.
    expect(Schema::hasTable('audit_logs'))->toBeFalse()
        ->and(Schema::hasTable('activity_log'))->toBeFalse()
        ->and(Schema::hasTable('activities'))->toBeFalse()
        ->and(class_exists('App\Models\Activity'))->toBeFalse();
});

it('registers no new permission', function (): void {
    // Attaching is a correction to a document's own filing rather than a new act,
    // so no `documents.attach` code was added to the canonical catalogue.
    expect(PermissionRegistry::all())->toHaveCount(177);
});
