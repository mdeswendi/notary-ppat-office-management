<?php

use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Document\Actions\UploadDocument;
use App\Domains\Document\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\Office;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The reference year comes from the application clock, so the suite freezes
    // it rather than depending on when it runs.
    Date::setTestNow('2026-08-24 09:00:00');

    Storage::fake('local');
});

afterEach(function (): void {
    Date::setTestNow();
});

/**
 * An actor holding the named permissions at one scope, in a fresh Office.
 *
 * @param  array<int, string>  $permissions
 * @return array{0: User, 1: Office}
 */
function documentManager(array $permissions = [], DataScope $scope = DataScope::OFFICE): array
{
    $office = Office::factory()->create();
    $actor = User::factory()->for($office)->create();

    foreach ($permissions as $permission) {
        grantPermissionScope($actor, $permission, $scope);
    }

    return [$actor->fresh(), $office];
}

/**
 * The capability set an ordinary office worker filing documents would hold.
 *
 * @return array<int, string>
 */
function documentCapabilities(): array
{
    return [
        'documents.view', 'documents.upload', 'documents.download',
        'documents.update', 'documents.verify', 'documents.archive', 'documents.delete',
    ];
}

function uploadedPdf(string $name = 'akta-uji.pdf', string $contents = 'isi dokumen uji'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/*
|--------------------------------------------------------------------------
| Upload
|--------------------------------------------------------------------------
*/

it('files a document, its first version, and a reference', function (): void {
    [$actor] = documentManager(documentCapabilities());

    $response = $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Sertipikat Uji',
        'document_type_code' => 'SERTIPIKAT',
        'notes' => 'Dokumen uji',
        'file' => uploadedPdf(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Sertipikat Uji')
        ->assertJsonPath('data.document_type_code', 'SERTIPIKAT')
        ->assertJsonPath('data.status', DocumentStatus::RECEIVED->value)
        ->assertJsonPath('data.is_sensitive', false)
        ->assertJsonPath('data.document_number', 'DOC-2026-000001')
        ->assertJsonPath('data.current_version.version_number', 1)
        ->assertJsonPath('data.current_version.original_filename', 'akta-uji.pdf')
        ->assertJsonPath('data.current_version.is_current', true);

    $document = Document::query()->firstOrFail();

    expect($document->office_id)->toBe($actor->office_id)
        ->and($document->created_by)->toBe($actor->getKey())
        ->and($document->updated_by)->toBe($actor->getKey())
        ->and($document->current_version_id)->not->toBeNull()
        ->and($document->versions()->count())->toBe(1);
});

it('creates RECEIVED rather than DRAFT, so verification is reachable', function (): void {
    // M5.1 created DRAFT. Had that continued, verify would have answered 422 to
    // every document that exists, because nothing moves a document out of DRAFT.
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
    ])->assertCreated();

    $document = Document::query()->firstOrFail();

    expect($document->status)->toBe(DocumentStatus::RECEIVED)
        ->and($document->status->isVerifiable())->toBeTrue();
});

it('writes the file to the private disk under office, year and month', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf('dokumen.pdf', 'isi tersimpan'),
    ])->assertCreated();

    $version = DocumentVersion::query()->firstOrFail();

    expect($version->storage_disk)->toBe('local')
        ->and($version->storage_path)->toStartWith("documents/{$office->getKey()}/2026/08/")
        ->and($version->storage_path)->not->toContain('public/')
        ->and($version->storage_path)->not->toContain('uploads/')
        ->and(Storage::disk('local')->exists($version->storage_path))->toBeTrue()
        ->and($version->checksum_sha256)->toBe(hash('sha256', 'isi tersimpan'))
        ->and($version->file_size)->toBe(strlen('isi tersimpan'));
});

it('never returns a storage path, filename or checksum', function (): void {
    // A path invites a client to try it; a checksum invites a client to treat its
    // own digest as the server's agreement.
    [$actor] = documentManager(documentCapabilities());

    $response = $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
    ])->assertCreated();

    $body = $response->getContent();

    foreach (['storage_path', 'stored_filename', 'checksum_sha256', 'documents/'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('attaches the document to a party, project and matter in one upload', function (): void {
    [$actor, $office] = documentManager([...documentCapabilities(), 'parties.view', 'projects.view', 'notary.matters.view']);

    $party = Party::factory()->individual()->for($office)->create();
    $project = Project::factory()->for($office)->create();
    $matter = Matter::factory()->for(Project::factory()->for($office)->create())->create();

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
        'related_to' => [
            'party_id' => $party->getKey(),
            'project_id' => $project->getKey(),
            'matter_id' => $matter->getKey(),
        ],
    ])->assertCreated()
        ->assertJsonPath('data.related.parties.0.id', $party->getKey())
        ->assertJsonPath('data.related.projects.0.id', $project->getKey())
        ->assertJsonPath('data.related.matters.0.id', $matter->getKey());

    $document = Document::query()->firstOrFail();

    // The office carrier is written from the Document, never from the request.
    foreach (['party_documents', 'project_documents', 'matter_documents'] as $table) {
        expect(DB::table($table)->where('document_id', $document->getKey())->value('office_id'))
            ->toBe($office->getKey());

        expect(DB::table($table)->where('document_id', $document->getKey())->value('attached_by'))
            ->toBe($actor->getKey());
    }
});

it('files a document with no attachment at all', function (): void {
    // The ordinary state of a scan that arrives by email: nobody knows yet which
    // matter it belongs to. Requiring a relation would make the common case
    // impossible.
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Belum Terkait',
        'file' => uploadedPdf(),
    ])->assertCreated()
        ->assertJsonPath('data.related.parties', [])
        ->assertJsonPath('data.related.matters', []);
});

it('refuses an attachment the caller cannot reach', function (): void {
    // `documents.upload` is authority to file a document, never authority to
    // discover which records exist.
    [$actor, $office] = documentManager(documentCapabilities());

    $unreachable = Project::factory()->for($office)->create();

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
        'related_to' => ['project_id' => $unreachable->getKey()],
    ])->assertStatus(422);

    expect(Document::query()->count())->toBe(0)
        ->and(DB::table('project_documents')->count())->toBe(0);
});

it('refuses an attachment in another office and files nothing', function (): void {
    [$actor] = documentManager([...documentCapabilities(), 'projects.view'], DataScope::ALL);

    $elsewhere = Project::factory()->create();

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
        'related_to' => ['project_id' => $elsewhere->getKey()],
    ])->assertStatus(422);

    // Even for an ALL-scoped actor. ALL is reach over records that already exist,
    // never permission to redefine which Office owns what.
    expect(Document::query()->count())->toBe(0);
});

it('leaves no file behind when the database work fails', function (): void {
    // The filesystem is not transactional, so an orphan file is the one case
    // DocumentStorage::delete exists for.
    //
    // The failure is forced with a nonexistent party id rather than a mocked
    // event, so the path exercised is a real one: the junction insert violates a
    // foreign key **after** the bytes have already landed on disk.
    [$actor] = documentManager(documentCapabilities());

    expect(fn () => app(UploadDocument::class)->handle(
        $actor,
        uploadedPdf(),
        ['title' => 'Uji'],
        ['party_id' => (string) Str::ulid()],
    ))->toThrow(QueryException::class);

    expect(Document::withTrashed()->count())->toBe(0)
        ->and(DocumentVersion::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a file type that is not allowed', function (): void {
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => UploadedFile::fake()->createWithContent('script.sh', '#!/bin/sh'),
    ])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('refuses a system-controlled field that is present', function (string $field, mixed $value): void {
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
        $field => $value,
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'office id' => ['office_id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    'document number' => ['document_number', 'DOC-2026-000999'],
    'status' => ['status', 'VERIFIED'],
    'checksum' => ['checksum_sha256', 'a'],
    'storage path' => ['storage_path', 'public/x.pdf'],
    // Present-but-null is refused too: a caller told "accepted" about an
    // instruction that was discarded is worse than one told no.
    'null status' => ['status', null],
]);

it('refuses upload without the capability', function (): void {
    [$actor] = documentManager(['documents.view']);

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| List
|--------------------------------------------------------------------------
*/

it('lists only documents the caller may reach', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    Document::factory()->count(2)->inOffice($office)->create();
    Document::factory()->count(3)->create();

    $this->actingAs($actor)->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('hides sensitive documents from an actor without the sensitive capability', function (): void {
    // Excluded in the query, so the pagination total stays honest — a stub for an
    // unreachable sensitive document is a question the M5 lock leaves open, so
    // M5.2 renders none rather than half-showing one.
    [$actor, $office] = documentManager(documentCapabilities());

    Document::factory()->inOffice($office)->create();
    Document::factory()->inOffice($office)->sensitive()->create();

    $this->actingAs($actor)->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);

    grantPermissionScope($actor, 'documents.sensitive.view', DataScope::OFFICE);

    $this->actingAs($actor->fresh())->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('searches title and document number without escaping visibility', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    Document::factory()->inOffice($office)->create(['title' => 'Sertipikat Tanah']);
    Document::factory()->inOffice($office)->create(['title' => 'Kartu Keluarga']);
    Document::factory()->create(['title' => 'Sertipikat Milik Kantor Lain']);

    $this->actingAs($actor)->getJson('/api/v1/documents?search=Sertipikat')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Sertipikat Tanah');
});

it('filters by status, type, sensitivity and relation', function (): void {
    [$actor, $office] = documentManager([...documentCapabilities(), 'documents.sensitive.view', 'projects.view']);

    $project = Project::factory()->for($office)->create();

    $ktp = Document::factory()->inOffice($office)->type('KTP')->sensitive()->create();
    Document::factory()->inOffice($office)->type('AKTA')->status(DocumentStatus::VERIFIED)->create();

    DB::table('project_documents')->insert([
        'id' => (string) Str::ulid(),
        'project_id' => $project->getKey(),
        'document_id' => $ktp->getKey(),
        'office_id' => $office->getKey(),
        'attached_by' => null,
        'attached_at' => now(),
    ]);

    $this->actingAs($actor)->getJson('/api/v1/documents?document_type_code=KTP')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ktp->getKey());

    $this->actingAs($actor)->getJson('/api/v1/documents?status=VERIFIED')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($actor)->getJson('/api/v1/documents?is_sensitive=true')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ktp->getKey());

    $this->actingAs($actor)->getJson("/api/v1/documents?project_id={$project->getKey()}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ktp->getKey());
});

it('ignores an unrecognized filter rather than erroring', function (): void {
    // A stale bookmark should show the unfiltered list, not a 422.
    [$actor, $office] = documentManager(documentCapabilities());

    Document::factory()->count(2)->inOffice($office)->create();

    $this->actingAs($actor)->getJson('/api/v1/documents?status=NOT_A_STATUS&sort_by=storage_path')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('costs no extra query per row in a list', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    Document::factory()->inOffice($office)->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($actor)->getJson('/api/v1/documents')->assertOk();
    $small = $queries;

    Document::factory()->count(9)->inOffice($office)->create();

    $queries = 0;
    $this->actingAs($actor)->getJson('/api/v1/documents')->assertOk();

    expect($queries)->toBe($small);
});

it('excludes a soft-deleted document from the list', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $kept = Document::factory()->inOffice($office)->create();
    Document::factory()->inOffice($office)->create()->delete();

    $this->actingAs($actor)->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->getKey());
});

/*
|--------------------------------------------------------------------------
| Detail
|--------------------------------------------------------------------------
*/

it('returns metadata and every version, newest first', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $first = DocumentVersion::factory()->forDocument($document)->versionNumber(1)->create();
    $second = DocumentVersion::factory()->forDocument($document)->versionNumber(2)->create();

    $document->current_version_id = $second->getKey();
    $document->save();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.versions.0.version_number', 2)
        ->assertJsonPath('data.versions.0.is_current', true)
        ->assertJsonPath('data.versions.1.version_number', 1)
        ->assertJsonPath('data.versions.1.is_current', false)
        ->assertJsonMissingPath('data.versions.0.storage_path')
        ->assertJsonMissingPath('data.versions.0.checksum_sha256');

    expect($first->getKey())->not->toBe($second->getKey());
});

it('answers 404 for a document in another office', function (): void {
    // Unreachable and nonexistent are indistinguishable — a 403 would confirm the
    // record exists somewhere the caller may not look.
    [$actor] = documentManager(documentCapabilities());

    $elsewhere = Document::factory()->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$elsewhere->getKey()}")
        ->assertNotFound();
});

it('answers 404 for a soft-deleted document', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $document->delete();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Download
|--------------------------------------------------------------------------
*/

it('streams the current file with the uploader\'s filename', function (): void {
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf('Surat Kuasa.pdf', 'isi surat kuasa'),
    ])->assertCreated();

    $document = Document::query()->firstOrFail();

    $response = $this->actingAs($actor)->get("/api/v1/documents/{$document->getKey()}/download");

    $response->assertOk();

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toContain('attachment')
        // RFC 5987 percent-encodes the space, which is exactly the escaping this
        // header needs: the name is data the uploader supplied, and a raw one
        // containing a quote or semicolon could break out of the header.
        ->and($disposition)->toContain("filename*=utf-8''Surat%20Kuasa.pdf")
        // The ASCII fallback carries the document number rather than the original
        // name, which for a KTP scan is often the subject's own.
        ->and($disposition)->toContain('filename=DOC-2026-000001.bin')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->streamedContent())->toBe('isi surat kuasa');
});

it('refuses a sensitive download to an actor holding every relevant code', function (): void {
    // D-115: no sensitive-download surface before an audit store exists.
    [$actor, $office] = documentManager([
        ...documentCapabilities(), 'documents.sensitive.view', 'documents.sensitive.download',
    ]);

    $sensitive = Document::factory()->inOffice($office)->sensitive()->create();
    DocumentVersion::factory()->forDocument($sensitive)->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$sensitive->getKey()}/download")
        ->assertForbidden();

    // And the interface is told the same thing, so it never offers the button.
    $this->actingAs($actor)->getJson("/api/v1/documents/{$sensitive->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_download', false);
});

it('refuses download to an actor holding only view', function (): void {
    [$actor, $office] = documentManager(['documents.view']);

    $document = Document::factory()->inOffice($office)->create();
    DocumentVersion::factory()->forDocument($document)->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}/download")
        ->assertForbidden();
});

it('answers 404 when a document has no file', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}/download")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Update
|--------------------------------------------------------------------------
*/

it('corrects metadata without touching the file', function (): void {
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Judul Lama',
        'file' => uploadedPdf(),
    ])->assertCreated();

    $document = Document::query()->firstOrFail();
    $versionId = $document->current_version_id;
    $checksum = $document->currentVersion->checksum_sha256;

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'title' => 'Judul Baru',
        'notes' => 'Dikoreksi',
    ])->assertOk()->assertJsonPath('data.title', 'Judul Baru');

    $document->refresh();

    expect($document->title)->toBe('Judul Baru')
        ->and($document->notes)->toBe('Dikoreksi')
        ->and($document->updated_by)->toBe($actor->getKey())
        ->and($document->current_version_id)->toBe($versionId)
        ->and($document->currentVersion->checksum_sha256)->toBe($checksum);
});

it('does not erase a field the patch omits', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create(['notes' => 'Catatan asli']);

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'title' => 'Judul Baru',
    ])->assertOk();

    expect($document->fresh()->notes)->toBe('Catatan asli');
});

it('refuses a replacement file through update', function (): void {
    // A correction to the bytes is a new version, never an edit.
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'file' => 'anything',
    ])->assertStatus(422)->assertJsonValidationErrors('file');
});

it('refuses a status change through update', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'status' => 'VERIFIED',
    ])->assertStatus(422)->assertJsonValidationErrors('status');
});

it('lets sensitivity change before verification', function (): void {
    [$actor, $office] = documentManager([...documentCapabilities(), 'documents.sensitive.view']);

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::RECEIVED)->create();

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'is_sensitive' => true,
    ])->assertOk()->assertJsonPath('data.is_sensitive', true);
});

it('refuses a sensitivity change once the document is settled', function (string $status): void {
    // Verification is the moment somebody accepted the document as what it claims
    // to be, classification included. Flipping the flag afterwards would silently
    // redefine which capability a download answers to.
    [$actor, $office] = documentManager([...documentCapabilities(), 'documents.sensitive.view']);

    $document = Document::factory()->inOffice($office)
        ->status(DocumentStatus::from($status))
        ->create();

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'is_sensitive' => true,
    ])->assertStatus(422);

    expect($document->fresh()->is_sensitive)->toBeFalse();
})->with(['VERIFIED', 'FINAL', 'ARCHIVED']);

it('accepts a patch that resends the current sensitivity unchanged', function (): void {
    // Refusing this would make the whole form unusable on a verified document —
    // the interface would have to know to strip a field it legitimately displays.
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::VERIFIED)->create();

    $this->actingAs($actor)->patchJson("/api/v1/documents/{$document->getKey()}", [
        'title' => 'Judul Baru',
        'is_sensitive' => false,
    ])->assertOk()->assertJsonPath('data.title', 'Judul Baru');
});

/*
|--------------------------------------------------------------------------
| Verify, archive, delete
|--------------------------------------------------------------------------
*/

it('verifies a received document', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::RECEIVED)->create();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$document->getKey()}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'VERIFIED');

    expect($document->fresh()->updated_by)->toBe($actor->getKey());
});

it('refuses to verify from an ineligible status', function (string $status): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::from($status))->create();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$document->getKey()}/verify")
        ->assertStatus(422);

    expect($document->fresh()->status->value)->toBe($status);
})->with(['DRAFT', 'VERIFIED', 'FINAL', 'ARCHIVED', 'VOID']);

it('archives a verified document without deleting it', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::VERIFIED)->create();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$document->getKey()}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'ARCHIVED');

    $document->refresh();

    expect($document->archived_at)->not->toBeNull()
        ->and($document->archived_by)->toBe($actor->getKey())
        ->and($document->deleted_at)->toBeNull();

    // Still readable. Somebody must be able to read what the office put away.
    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}")->assertOk();
});

it('refuses to archive an unverified document', function (string $status): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::from($status))->create();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$document->getKey()}/archive")
        ->assertStatus(422);
})->with(['DRAFT', 'RECEIVED', 'UNDER_REVIEW', 'ARCHIVED', 'VOID']);

it('soft deletes a received document and keeps its file', function (): void {
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::RECEIVED)->create();
    $version = DocumentVersion::factory()->forDocument($document)->create();

    $this->actingAs($actor)->deleteJson("/api/v1/documents/{$document->getKey()}")
        ->assertNoContent();

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeFalse()
        ->and(Document::withTrashed()->whereKey($document->getKey())->exists())->toBeTrue()
        ->and(DB::table('document_versions')->where('id', $version->getKey())->exists())->toBeTrue();
});

it('refuses to delete anything somebody has verified', function (string $status): void {
    // `documents.delete` must be heavily restricted, and the restriction is a
    // status rule rather than a permission one.
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::from($status))->create();

    $this->actingAs($actor)->deleteJson("/api/v1/documents/{$document->getKey()}")
        ->assertStatus(422);

    expect(Document::query()->whereKey($document->getKey())->exists())->toBeTrue();
})->with(['VERIFIED', 'FINAL', 'ARCHIVED', 'VOID']);

it('exposes no restore endpoint', function (): void {
    // Reading `documents.delete` as "may also undelete" would make one capability
    // do two jobs. Restoring is a milestone decision with its own question.
    [$actor, $office] = documentManager(documentCapabilities());

    $document = Document::factory()->inOffice($office)->create();
    $document->delete();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$document->getKey()}/restore")
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Capability independence
|--------------------------------------------------------------------------
*/

it('lets no document capability reach another endpoint', function (): void {
    [$actor, $office] = documentManager(['documents.view']);

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::RECEIVED)->create();
    $id = $document->getKey();

    $this->actingAs($actor)->postJson("/api/v1/documents/{$id}/verify")->assertForbidden();
    $this->actingAs($actor)->postJson("/api/v1/documents/{$id}/archive")->assertForbidden();
    $this->actingAs($actor)->patchJson("/api/v1/documents/{$id}", ['title' => 'x'])->assertForbidden();
    $this->actingAs($actor)->deleteJson("/api/v1/documents/{$id}")->assertForbidden();
    $this->actingAs($actor)->getJson("/api/v1/documents/{$id}/download")->assertForbidden();
});

it('reports capability flags that match what the endpoints will do', function (): void {
    [$actor, $office] = documentManager(['documents.view', 'documents.verify']);

    $document = Document::factory()->inOffice($office)->status(DocumentStatus::RECEIVED)->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$document->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_verify', true)
        ->assertJsonPath('data.can_archive', false)
        ->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.can_delete', false)
        ->assertJsonPath('data.can_download', false);
});

it('reports can_verify false where the status makes verifying impossible', function (): void {
    // A flag that says yes to something the endpoint answers 422 to is worse than
    // no flag: the interface offers a button that cannot work.
    [$actor, $office] = documentManager(documentCapabilities());

    $verified = Document::factory()->inOffice($office)->status(DocumentStatus::VERIFIED)->create();

    $this->actingAs($actor)->getJson("/api/v1/documents/{$verified->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.can_verify', false)
        ->assertJsonPath('data.can_archive', true)
        ->assertJsonPath('data.can_delete', false);
});

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/

it('offers document types as suggestions that nothing validates against', function (): void {
    [$actor] = documentManager(documentCapabilities());

    $response = $this->actingAs($actor)->getJson('/api/v1/documents/options')->assertOk();

    expect($response->json('data.document_types'))->toContain('KTP')
        ->and($response->json('data.statuses'))->toBe(DocumentStatus::values())
        ->and($response->json('data.mime_types'))->toContain('application/pdf')
        ->and($response->json('data.max_upload_kilobytes'))->toBe(20480);

    // A type the list does not name is still accepted, because the code is opaque.
    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'document_type_code' => 'SESUATU_YANG_TIDAK_TERDAFTAR',
        'file' => uploadedPdf(),
    ])->assertCreated()->assertJsonPath('data.document_type_code', 'SESUATU_YANG_TIDAK_TERDAFTAR');
});

it('refuses options without the upload capability', function (): void {
    [$actor] = documentManager(['documents.view']);

    $this->actingAs($actor)->getJson('/api/v1/documents/options')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

it('refuses every document endpoint to a guest', function (string $method, string $path): void {
    $this->{$method}("/api/v1/documents{$path}", [])->assertUnauthorized();
})->with([
    ['getJson', ''],
    ['postJson', ''],
    ['getJson', '/options'],
    ['getJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['patchJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['deleteJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ'],
    ['getJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/download'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/verify'],
    ['postJson', '/01JZZZZZZZZZZZZZZZZZZZZZZZ/archive'],
]);

it('never allocates a reference for a request that fails', function (): void {
    // The allocation is inside the transaction, so a rollback takes the counter
    // increment with it and the number is not spent.
    [$actor] = documentManager(documentCapabilities());

    $this->actingAs($actor)->postJson('/api/v1/documents', [
        'title' => 'Uji',
        'file' => UploadedFile::fake()->createWithContent('script.sh', '#!/bin/sh'),
    ])->assertStatus(422);

    expect(DB::table('document_reference_counters')->count())->toBe(0);

    $this->actingAs($actor)->post('/api/v1/documents', [
        'title' => 'Uji',
        'file' => uploadedPdf(),
    ])->assertCreated()->assertJsonPath('data.document_number', 'DOC-2026-000001');
});
