<?php

use App\Domains\Authorization\PermissionRegistry;
use App\Domains\Document\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Table shape
|--------------------------------------------------------------------------
*/

it('gives a document a generated ULID primary key', function (): void {
    $document = Document::factory()->create();

    expect($document->getKeyType())->toBe('string')
        ->and($document->getIncrementing())->toBeFalse()
        ->and(strlen($document->id))->toBe(26)
        ->and(Str::isUlid($document->id))->toBeTrue();
});

it('carries exactly the canonical M5.1 document columns', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 13. Asserting the exact set
    // turns an accidental addition into a failing test rather than a silent
    // schema change.
    //
    // `updated_by` is present because the ERD lists it, even though the M5.1
    // plan omitted it: a metadata correction has an author, and `updated_at`
    // alone records that something changed without recording who.
    $columns = Schema::getColumnListing('documents');
    sort($columns);

    $expected = [
        'archived_at', 'archived_by', 'created_at', 'created_by', 'current_version_id',
        'deleted_at', 'document_date', 'document_number', 'document_type_code',
        'expiry_date', 'id', 'is_sensitive', 'notes', 'office_id', 'status', 'title',
        'updated_at', 'updated_by',
    ];
    sort($expected);

    expect($columns)->toBe($expected);
});

it('carries exactly the canonical M5.1 version columns and no updated_at', function (): void {
    // The ERD gives this table `uploaded_at` and neither `created_at` nor
    // `updated_at`. That is transcribed rather than tidied: an `updated_at` would
    // advertise a mutation that must never happen, and `created_at` beside
    // `uploaded_at` would be two names for one fact.
    $columns = Schema::getColumnListing('document_versions');
    sort($columns);

    $expected = [
        'checksum_sha256', 'document_id', 'file_size', 'id', 'mime_type',
        'original_filename', 'storage_disk', 'storage_path', 'stored_filename',
        'uploaded_at', 'uploaded_by', 'version_number',
    ];
    sort($expected);

    expect($columns)->toBe($expected)
        ->and(Schema::hasColumn('document_versions', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('document_versions', 'created_at'))->toBeFalse()
        ->and((new DocumentVersion)->usesTimestamps())->toBeFalse();
});

it('replaces is_current with a current version pointer', function (): void {
    // The M5.0 ruling, resolved here (D-116). A boolean would need a partial
    // unique index to mean "exactly one"; partial indexes do not exist on the
    // SQLite connection the suite runs on, so the two engines would disagree
    // about what is representable — the shape D-111 already refused once.
    expect(Schema::hasColumn('document_versions', 'is_current'))->toBeFalse()
        ->and(Schema::hasColumn('documents', 'current_version_id'))->toBeTrue();
});

it('keeps a document valid before its first file lands', function (): void {
    // Nullable permanently rather than pending: creating the row, writing the
    // version, then pointing at it is the ordinary upload order.
    $document = Document::factory()->create();

    expect($document->fresh()->current_version_id)->toBeNull()
        ->and($document->versions()->count())->toBe(0);
});

it('leaves document_number nullable until a creation path allocates one', function (): void {
    // Exactly as `project_number` was until M3.3 and `matter_number` until M4.4:
    // no creation path allocates one yet, so NOT NULL would make a Document
    // unwritable for a whole milestone. The milestone that builds upload stamps
    // it inside the creating transaction and tightens the column.
    $document = Document::factory()->create();

    expect($document->fresh()->document_number)->toBeNull();

    $numbered = Document::factory()->numbered()->create();

    expect($numbered->fresh()->document_number)->toMatch('/^DOC-\d{4}-\d{6,}$/');
});

it('leaves document_type_code opaque rather than constraining it', function (): void {
    // No canonical document defines a document-type catalogue: `KTP`, `NPWP` and
    // `AKTA` appear in prose as examples, and constraining the column would turn
    // examples into a catalogue the documents never claimed (D-115).
    $document = Document::factory()->type('SOMETHING_NOBODY_ENUMERATED')->create();

    expect($document->fresh()->document_type_code)->toBe('SOMETHING_NOBODY_ENUMERATED');
});

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

it('names exactly the seven canonical document statuses', function (): void {
    // Transcribed from 03_DATABASE_ERD.md section 13. Only DRAFT is reachable in
    // M5.1 — no transition rule exists, and inventing one would be a business
    // rule no canonical document states.
    expect(DocumentStatus::values())
        ->toBe(['DRAFT', 'RECEIVED', 'UNDER_REVIEW', 'VERIFIED', 'FINAL', 'ARCHIVED', 'VOID']);
});

it('stores a machine code rather than a translated label', function (): void {
    $document = Document::factory()->create();

    expect(DB::table('documents')->where('id', $document->getKey())->value('status'))
        ->toBe('DRAFT');
});

it('refuses a status the enum does not name', function (): void {
    // On PostgreSQL a CHECK refuses it; on SQLite the enum cast does. Either way
    // the value never lands.
    Document::factory()->create(['status' => 'Sedang Diproses']);
})->throws(ValueError::class);

/*
|--------------------------------------------------------------------------
| Sensitivity
|--------------------------------------------------------------------------
*/

it('defaults is_sensitive to false and never infers it from the type', function (): void {
    // D-115: deriving sensitivity from `document_type_code` would encode which
    // document kinds are sensitive — a judgement that varies by office and one
    // no canonical document makes.
    $ktp = Document::factory()->type('KTP')->create();

    expect($ktp->fresh()->is_sensitive)->toBeFalse();

    $flagged = Document::factory()->type('SURAT')->sensitive()->create();

    expect($flagged->fresh()->is_sensitive)->toBeTrue();
});

it('stores no party identity on the document record', function (): void {
    // A Document record must never carry NIK, NPWP or any other sensitive
    // identity — not as a column, not "for convenience". D-105's rule, one
    // domain across.
    foreach (['nik', 'npwp', 'tax_id', 'identity_number', 'party_id'] as $column) {
        expect(Schema::hasColumn('documents', $column))->toBeFalse($column);
    }
});

/*
|--------------------------------------------------------------------------
| Office ownership
|--------------------------------------------------------------------------
*/

it('refuses to move a document between offices', function (): void {
    $document = Document::factory()->create();
    $document->office_id = Office::factory()->create()->getKey();
    $document->save();
})->throws(RuntimeException::class, 'immutable');

it('refuses to delete an office that still holds documents', function (): void {
    $office = Office::factory()->create();
    Document::factory()->inOffice($office)->create();

    DB::table('offices')->where('id', $office->getKey())->delete();
})->throws(QueryException::class);

it('scopes the document number to its office', function (): void {
    // Composite, never global: two Offices may both hold `DOC-2026-000001`, and
    // a global index would fail the second office's first document for no
    // explicable reason.
    $first = Office::factory()->create();
    $second = Office::factory()->create();

    Document::factory()->inOffice($first)->create(['document_number' => 'DOC-2026-000001']);
    Document::factory()->inOffice($second)->create(['document_number' => 'DOC-2026-000001']);

    expect(Document::query()->where('document_number', 'DOC-2026-000001')->count())->toBe(2);
});

it('refuses two documents with the same number in one office', function (): void {
    $office = Office::factory()->create();

    Document::factory()->inOffice($office)->create(['document_number' => 'DOC-2026-000042']);
    Document::factory()->inOffice($office)->create(['document_number' => 'DOC-2026-000042']);
})->throws(QueryException::class);

/*
|--------------------------------------------------------------------------
| Versions are written once
|--------------------------------------------------------------------------
*/

it('refuses to update an existing version', function (): void {
    // CLAUDE.md section 19 and the ERD both require that an existing version is
    // never overwritten: a correction adds a version, and the previous file
    // stays exactly as it was.
    $version = DocumentVersion::factory()->create();

    $version->mime_type = 'image/png';
    $version->save();
})->throws(RuntimeException::class, 'write-once');

it('keeps the previous version untouched when a correction arrives', function (): void {
    $document = Document::factory()->create();

    $first = DocumentVersion::factory()->forDocument($document)->versionNumber(1)->create();
    $originalPath = $first->storage_path;
    $originalChecksum = $first->checksum_sha256;

    DocumentVersion::factory()->forDocument($document)->versionNumber(2)->create();

    $first->refresh();

    expect($first->storage_path)->toBe($originalPath)
        ->and($first->checksum_sha256)->toBe($originalChecksum)
        ->and($document->versions()->count())->toBe(2);
});

it('orders versions newest first', function (): void {
    $document = Document::factory()->create();

    foreach ([1, 2, 3] as $number) {
        DocumentVersion::factory()->forDocument($document)->versionNumber($number)->create();
    }

    expect($document->versions()->pluck('version_number')->all())->toBe([3, 2, 1]);
});

it('refuses two versions at the same position in one document', function (): void {
    $document = Document::factory()->create();

    DocumentVersion::factory()->forDocument($document)->versionNumber(1)->create();
    DocumentVersion::factory()->forDocument($document)->versionNumber(1)->create();
})->throws(QueryException::class);

it('allows the same version number in different documents', function (): void {
    DocumentVersion::factory()->versionNumber(1)->create();
    DocumentVersion::factory()->versionNumber(1)->create();

    expect(DocumentVersion::query()->where('version_number', 1)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Private storage
|--------------------------------------------------------------------------
*/

it('refuses a storage path inside a public web directory', function (string $path): void {
    // CLAUDE.md section 19 names both directories explicitly. PostgreSQL has a
    // CHECK; the model guard is what holds on SQLite, where the suite runs.
    DocumentVersion::factory()->create(['storage_path' => $path]);
})->with([
    'public/x.pdf',
    'uploads/x.pdf',
    'documents/public/x.pdf',
    'documents/uploads/x.pdf',
])->throws(RuntimeException::class);

it('refuses a checksum that is not 64 lowercase hex characters', function (string $checksum): void {
    // A truncated or uppercased digest compares unequal to a correctly computed
    // one, and the mismatch looks like corruption rather than a formatting
    // mistake.
    DocumentVersion::factory()->create(['checksum_sha256' => $checksum]);
})->with([
    'too short' => ['abc123'],
    'uppercase' => [str_repeat('A', 64)],
    'non hex' => [str_repeat('z', 64)],
])->throws(RuntimeException::class);

it('never serializes the storage path or the stored filename', function (): void {
    // Neither is a secret on its own — M5.0 turned off the route that served
    // that directory (D-114) — but an API returning a storage path invites a
    // client to try it, and the next person to add a disk with `serve => true`
    // would turn a leaked path into a live download.
    $version = DocumentVersion::factory()->create();

    expect($version->toArray())->not->toHaveKey('storage_path')
        ->and($version->toArray())->not->toHaveKey('stored_filename')
        ->and($version->toArray())->toHaveKey('original_filename');
});

/*
|--------------------------------------------------------------------------
| The current-version pointer
|--------------------------------------------------------------------------
*/

it('accepts a pointer at one of its own versions', function (): void {
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->forDocument($document)->create();

    $document->current_version_id = $version->getKey();
    $document->save();

    expect($document->fresh()->currentVersion->getKey())->toBe($version->getKey());
});

it('refuses a pointer at another document\'s version', function (): void {
    // The composite foreign key makes this unrepresentable on PostgreSQL; the
    // model guard is what holds the same rule on SQLite, where the suite runs,
    // so a defect fails here rather than only in production.
    $document = Document::factory()->create();
    $foreign = DocumentVersion::factory()->create();

    $document->current_version_id = $foreign->getKey();
    $document->save();
})->throws(RuntimeException::class, 'must name a version of this document');

it('refuses a pointer at a version that does not exist', function (): void {
    $document = Document::factory()->create();

    $document->current_version_id = (string) Str::ulid();
    $document->save();
})->throws(RuntimeException::class);

it('lets a document be hard-deleted together with its versions', function (): void {
    // `document_id` cascades, so deleting a Document removes versions the
    // Document itself still points at — and the delete succeeds, because the
    // referencing `documents` row goes in the same statement and leaves nothing
    // pointing at the version. The M5.1 PostgreSQL probe confirmed the composite
    // key behaves the same under `RESTRICT` and `NO ACTION`, which is why it is
    // declared `RESTRICT` like every other key here.
    //
    // M5.1 exposes no deletion path — this asserts the schema is coherent, not
    // that anything may call it.
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->forDocument($document)->create();

    $document->current_version_id = $version->getKey();
    $document->save();

    DB::table('documents')->where('id', $document->getKey())->delete();

    expect(DB::table('documents')->where('id', $document->getKey())->exists())->toBeFalse()
        ->and(DB::table('document_versions')->where('id', $version->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Junctions
|--------------------------------------------------------------------------
*/

it('builds exactly three of the seven recommended junction tables', function (): void {
    // D-115: the other four reference `properties` (M7), `notary_deeds` (M6),
    // `ppat_deeds` (M7) and `matter_requirements` — none of which exists, and a
    // foreign key cannot point at a table that is not there. None is stubbed:
    // not created empty, not created without its key, and not replaced by a
    // polymorphic `documentable_type` column, which section 14 argues against
    // explicitly for records where referential integrity matters.
    foreach (['party_documents', 'project_documents', 'matter_documents'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    foreach ([
        'property_documents', 'notary_deed_documents',
        'ppat_deed_documents', 'matter_requirement_documents',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    foreach (['documentable_type', 'documentable_id'] as $column) {
        expect(Schema::hasColumn('documents', $column))->toBeFalse($column);
    }
});

it('carries an office constraint carrier on every junction', function (string $table): void {
    expect(Schema::hasColumn($table, 'office_id'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'document_id'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'attached_at'))->toBeTrue()
        ->and(Schema::hasColumn($table, 'attached_by'))->toBeTrue()
        // A relationship, not a history: detaching removes the row.
        ->and(Schema::hasColumn($table, 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn($table, 'deleted_at'))->toBeFalse();
})->with(['party_documents', 'project_documents', 'matter_documents']);

it('makes a cross-office attachment unrepresentable', function (): void {
    // Structural rather than validated, and **including for an actor holding
    // `ALL`**: `ALL` grants reach and administrative visibility, never permission
    // to redefine domain ownership.
    $officeA = Office::factory()->create();
    $officeB = Office::factory()->create();

    $document = Document::factory()->inOffice($officeA)->create();
    $project = Project::factory()->for($officeB)->create();

    // The carrier is written from the Document, so the Project half is the one
    // that fails — which is the point: the two endpoints resolve through one
    // `office_id` and cannot disagree.
    DB::table('project_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'document_id' => $document->getKey(),
        'office_id' => $officeA->getKey(),
        'attached_by' => null,
        'attached_at' => now(),
    ]);
})->throws(QueryException::class);

it('accepts an attachment when both endpoints share the office', function (): void {
    $office = Office::factory()->create();
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

    expect(DB::table('project_documents')->count())->toBe(1);
});

it('imposes no attachment cardinality rule', function (): void {
    // Following D-105 and D-110 exactly: no canonical document says a Document
    // may be attached to a Party only once, and a unique index is a business
    // rule wearing an index's clothing.
    $office = Office::factory()->create();
    $document = Document::factory()->inOffice($office)->create();
    $party = Party::factory()->individual()->for($office)->create();

    foreach (range(1, 2) as $ignored) {
        DB::table('party_documents')->insert([
            'id' => (string) Str::ulid(),
            'party_id' => $party->getKey(),
            'document_id' => $document->getKey(),
            'office_id' => $office->getKey(),
            'attached_by' => null,
            'attached_at' => now(),
        ]);
    }

    expect(DB::table('party_documents')->count())->toBe(2);
});

it('refuses to delete an attached record or its document', function (): void {
    // RESTRICT everywhere: removing a Matter must never take a document with it,
    // and a document must never become unreachable because something it was
    // attached to went away.
    $office = Office::factory()->create();
    $document = Document::factory()->inOffice($office)->create();

    // The Matter inherits its Office from its Project (D-099), which is what
    // makes both halves of the composite key agree.
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create();

    DB::table('matter_documents')->insert([
        'id' => (string) Str::ulid(),
        'matter_id' => $matter->getKey(),
        'document_id' => $document->getKey(),
        'office_id' => $office->getKey(),
        'attached_by' => null,
        'attached_at' => now(),
    ]);

    expect(fn () => DB::table('documents')->where('id', $document->getKey())->delete())
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('matters')->where('id', $matter->getKey())->delete())
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Soft deletes are reserved, not implemented
|--------------------------------------------------------------------------
*/

it('reserves deleted_at without giving Document a soft-delete lifecycle', function (): void {
    // The M4.2 position (D-102): the column exists because the ERD carries it and
    // because `documents.delete` is canonical and "must be heavily restricted",
    // but no global scope filters any query, so "invisible because deleted"
    // cannot be confused with "invisible because out of scope".
    expect(Schema::hasColumn('documents', 'deleted_at'))->toBeTrue()
        ->and(in_array(
            SoftDeletes::class,
            class_uses_recursive(Document::class),
            true
        ))->toBeFalse();
});

it('treats archiving as a state rather than a deletion', function (): void {
    $user = User::factory()->create();
    $document = Document::factory()->archived($user)->create();

    expect($document->fresh()->status)->toBe(DocumentStatus::ARCHIVED)
        ->and($document->fresh()->archived_at)->not->toBeNull()
        ->and($document->fresh()->deleted_at)->toBeNull()
        // Reachable, because somebody must be able to read what the office
        // archived (CLAUDE.md section 63).
        ->and(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Milestone boundary
|--------------------------------------------------------------------------
*/

it('builds no task, requirement or template table', function (): void {
    // M5.1 is the Document schema and nothing else. Tasks are M5.4;
    // `service_document_requirements` and `matter_requirements` are deferred to
    // M6/M7 with the legal content that would justify them; `task_templates` is
    // deferred outright.
    foreach ([
        'tasks', 'task_templates', 'matter_requirements',
        'service_document_requirements', 'document_templates',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }
});

it('adds no gating column to the workflow tables', function (): void {
    // `required_before_stage_code` is the M5.0 gap, recorded rather than guessed
    // (D-115). Gating a stage transition on document completeness is a legal
    // workflow rule, and CLAUDE.md section 62 forbids inventing one.
    expect(Schema::hasColumn('workflow_stages', 'required_before_stage_code'))->toBeFalse()
        ->and(Schema::hasColumn('documents', 'required_before_stage_code'))->toBeFalse()
        ->and(Schema::hasColumn('documents', 'workflow_stage_id'))->toBeFalse();
});

it('exposes no document route', function (): void {
    // Backend foundation only, following M2.1, M3.1, M4.1, M4.2 and M4.6.
    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_contains($uri, 'document'))
        ->values();

    expect($routes)->toBeEmpty();
});

it('registers no new permission', function (): void {
    // All nine `documents.*` codes have been canonical since the catalogue was
    // transcribed. M5.1 decides their predicates; it adds nothing.
    expect(PermissionRegistry::all())->toHaveCount(177);
});

/*
|--------------------------------------------------------------------------
| Reversibility
|--------------------------------------------------------------------------
*/

it('migrates, rolls back, and re-migrates cleanly', function (): void {
    // Derived rather than hardcoded: a literal `--step` silently starts testing
    // a different migration the moment a later milestone adds one.
    $steps = rollbackStepsTo('create_documents_table');

    $this->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    expect(Schema::hasTable('documents'))->toBeFalse()
        ->and(Schema::hasTable('document_versions'))->toBeFalse()
        ->and(Schema::hasTable('document_reference_counters'))->toBeFalse()
        ->and(Schema::hasTable('party_documents'))->toBeFalse()
        ->and(Schema::hasTable('project_documents'))->toBeFalse()
        ->and(Schema::hasTable('matter_documents'))->toBeFalse()
        // Everything below M5.1 survives, which is the half of the claim a
        // rollback test usually forgets to make.
        ->and(Schema::hasTable('matters'))->toBeTrue()
        ->and(Schema::hasTable('matter_workflows'))->toBeTrue()
        ->and(Schema::hasTable('parties'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('documents'))->toBeTrue()
        ->and(Schema::hasTable('document_versions'))->toBeTrue()
        ->and(Schema::hasTable('matter_documents'))->toBeTrue();
});
