<?php

use App\Domains\Document\DocumentStorage;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use ReflectionMethod;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(DocumentStorage::DISK);
});

/*
|--------------------------------------------------------------------------
| Where the bytes go
|--------------------------------------------------------------------------
*/

it('stores a file under office, year and month on the private disk', function (): void {
    // `office_id` leads so a misconfigured backup, or an operator listing a
    // directory, meets the Office boundary the database enforces. The date
    // segments keep any single directory from growing without bound.
    $document = Document::factory()->create();
    $file = UploadedFile::fake()->createWithContent('akta-uji.pdf', 'isi dokumen uji');

    $metadata = app(DocumentStorage::class)->store($file, $document);

    expect($metadata['storage_disk'])->toBe('local')
        ->and($metadata['storage_path'])->toStartWith('documents/'.$document->office_id.'/')
        // The model's key is a lowercased ULID (Laravel's `HasUlids`); the
        // generated filename comes straight from `Str::ulid()` and is uppercase.
        ->and($metadata['storage_path'])->toMatch('#^documents/[0-9a-z]{26}/\d{4}/\d{2}/[0-9A-Z]{26}\.pdf$#')
        ->and(Storage::disk('local')->exists($metadata['storage_path']))->toBeTrue();
});

it('never writes into a public web directory', function (): void {
    // CLAUDE.md section 19. The path is checked here, by a database CHECK on
    // PostgreSQL, and by the model guard on both engines — three places, because
    // this is the rule a legal document system may not get wrong.
    $document = Document::factory()->create();
    $file = UploadedFile::fake()->createWithContent('surat-uji.pdf', 'isi');

    $path = app(DocumentStorage::class)->store($file, $document)['storage_path'];

    expect($path)->not->toContain('public/')
        ->and($path)->not->toContain('uploads/');
});

it('never uses the uploader\'s filename as a path component', function (): void {
    // User-supplied names carry traversal sequences, case collisions, characters
    // no filesystem agrees about, and — for a KTP scan — often the subject's own
    // name. The original is kept in metadata so a download can restore it; it
    // never touches the disk.
    $document = Document::factory()->create();
    $file = UploadedFile::fake()->createWithContent('Budi Santoso KTP.pdf', 'isi');

    $metadata = app(DocumentStorage::class)->store($file, $document);

    expect($metadata['original_filename'])->toBe('Budi Santoso KTP.pdf')
        ->and($metadata['storage_path'])->not->toContain('Budi')
        ->and($metadata['storage_path'])->not->toContain('Santoso')
        ->and($metadata['stored_filename'])->not->toContain('Budi');
});

it('reduces the extension so a crafted name cannot alter the path', function (string $name, string $expectedSuffix): void {
    // Everything that is not a lowercase letter or digit is dropped, so a name
    // cannot contribute a separator and a double extension cannot smuggle one.
    $document = Document::factory()->create();
    $file = UploadedFile::fake()->createWithContent($name, 'isi');

    $metadata = app(DocumentStorage::class)->store($file, $document);

    expect($metadata['stored_filename'])->toEndWith($expectedSuffix)
        // Four separators and no more: documents / office / year / month / file.
        ->and(substr_count($metadata['storage_path'], '/'))->toBe(4);
})->with([
    'ordinary' => ['dokumen.pdf', '.pdf'],
    'uppercase' => ['DOKUMEN.PDF', '.pdf'],
    'punctuation' => ['dokumen.p-d-f', '.pdf'],
    'double extension' => ['dokumen.pdf.exe', '.exe'],
]);

it('stores a file with no usable extension rather than refusing it', function (): void {
    // The bytes and the recorded `mime_type` are what matter; a name that
    // happens to carry no extension is not a reason to refuse a legal document.
    $document = Document::factory()->create();

    $metadata = app(DocumentStorage::class)->store(
        UploadedFile::fake()->createWithContent('dokumen', 'isi'),
        $document,
    );

    expect($metadata['stored_filename'])->toMatch('/^[0-9A-Z]{26}$/')
        ->and(substr_count($metadata['storage_path'], '/'))->toBe(4)
        ->and(Storage::disk('local')->exists($metadata['storage_path']))->toBeTrue();
});

it('generates a filename that shares nothing between two uploads', function (): void {
    $document = Document::factory()->create();
    $storage = app(DocumentStorage::class);

    $first = $storage->store(UploadedFile::fake()->createWithContent('a.pdf', 'isi'), $document);
    $second = $storage->store(UploadedFile::fake()->createWithContent('a.pdf', 'isi'), $document);

    expect($first['stored_filename'])->not->toBe($second['stored_filename'])
        ->and($first['storage_path'])->not->toBe($second['storage_path'])
        // Identical bytes, so the checksums agree — which is the checksum doing
        // its job, not a collision.
        ->and($first['checksum_sha256'])->toBe($second['checksum_sha256']);
});

/*
|--------------------------------------------------------------------------
| The checksum
|--------------------------------------------------------------------------
*/

it('checksums the bytes actually written rather than the upload', function (): void {
    // Hashing the source would attest to something other than what is stored,
    // which is precisely the case a checksum exists to catch.
    $document = Document::factory()->create();
    $contents = 'isi dokumen uji '.uniqid();

    $metadata = app(DocumentStorage::class)->store(
        UploadedFile::fake()->createWithContent('dokumen.pdf', $contents),
        $document,
    );

    expect($metadata['checksum_sha256'])
        ->toBe(hash('sha256', Storage::disk('local')->get($metadata['storage_path'])))
        ->and($metadata['checksum_sha256'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($metadata['file_size'])->toBe(strlen($contents));
});

it('detects a file whose bytes no longer match what was recorded', function (): void {
    $document = Document::factory()->create();
    $storage = app(DocumentStorage::class);

    $metadata = $storage->store(
        UploadedFile::fake()->createWithContent('dokumen.pdf', 'asli'),
        $document,
    );

    $version = DocumentVersion::factory()->forDocument($document)->create($metadata);

    expect($storage->matchesChecksum($version))->toBeTrue();

    Storage::disk('local')->put($metadata['storage_path'], 'diubah');

    expect($storage->matchesChecksum($version->fresh()))->toBeFalse();
});

it('reports a missing file rather than pretending it matches', function (): void {
    $document = Document::factory()->create();
    $storage = app(DocumentStorage::class);

    $metadata = $storage->store(
        UploadedFile::fake()->createWithContent('dokumen.pdf', 'isi'),
        $document,
    );

    $version = DocumentVersion::factory()->forDocument($document)->create($metadata);

    Storage::disk('local')->delete($metadata['storage_path']);

    expect($storage->exists($version))->toBeFalse()
        ->and($storage->matchesChecksum($version))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Reading it back
|--------------------------------------------------------------------------
*/

it('streams a stored version', function (): void {
    $document = Document::factory()->create();
    $storage = app(DocumentStorage::class);

    $metadata = $storage->store(
        UploadedFile::fake()->createWithContent('dokumen.pdf', 'isi dokumen uji'),
        $document,
    );

    $version = DocumentVersion::factory()->forDocument($document)->create($metadata);

    $stream = $storage->readStream($version);

    expect(stream_get_contents($stream))->toBe('isi dokumen uji');

    fclose($stream);
});

it('fails loudly when the metadata says a file exists and it does not', function (): void {
    $document = Document::factory()->create();
    $storage = app(DocumentStorage::class);

    $metadata = $storage->store(
        UploadedFile::fake()->createWithContent('dokumen.pdf', 'isi'),
        $document,
    );

    $version = DocumentVersion::factory()->forDocument($document)->create($metadata);

    Storage::disk('local')->delete($metadata['storage_path']);

    $storage->readStream($version->fresh());
})->throws(RuntimeException::class, 'missing from disk');

/*
|--------------------------------------------------------------------------
| No second authorization path
|--------------------------------------------------------------------------
*/

it('issues no URL of any kind', function (): void {
    // A URL that authorizes by possession is a second authorization path beside
    // the Policy chain, and a second one that happens to work is the problem
    // rather than the convenience it looks like. A file is read by streaming it
    // from a surface that authorized the actor against the Document record first.
    $methods = array_map(
        fn (ReflectionMethod $method): string => strtolower($method->getName()),
        (new ReflectionClass(DocumentStorage::class))->getMethods(),
    );

    foreach (['url', 'temporaryurl', 'signedurl', 'publicurl', 'downloadurl'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }

    $source = file_get_contents(app_path('Domains/Document/DocumentStorage.php'));

    $executable = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    foreach (['->url(', '->temporaryUrl(', 'URL::signedRoute', 'Storage::url'] as $forbidden) {
        expect($executable)->not->toContain($forbidden);
    }
});

it('keeps the private disk unserved over HTTP', function (): void {
    // D-114: M5.0 turned the `serve` flag off, which removed the
    // `GET /storage/{path}` route that reached into `storage/app/private`.
    expect(config('filesystems.disks.local.serve'))->toBeFalse();

    $routes = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'storage/'))
        ->values();

    expect($routes)->toBeEmpty();
});
