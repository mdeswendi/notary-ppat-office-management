<?php

namespace App\Domains\Document;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a document's bytes go, and how they come back (M5.1, D-114, D-116).
 *
 * ## One disk, private, and never served
 *
 * The `local` disk roots at `storage/app/private`, and M5.0 set its `serve` flag
 * to false (D-114) so the `GET`/`PUT /storage/{path}` routes that reached into
 * that directory no longer exist. **This class issues no URL of any kind** — no
 * signed URL, no temporary URL, no path a client could try — because a URL that
 * authorizes by possession is a second authorization path beside the Policy
 * chain, and a second one that happens to work is the problem rather than the
 * convenience it looks like.
 *
 * A file is read by streaming it from a surface that authorized the actor against
 * the Document record first. That surface is M5.2's; this class only puts bytes
 * somewhere safe and finds them again.
 *
 * ## The path
 *
 * ```text
 * documents/{office_id}/{YYYY}/{MM}/{ulid}.{ext}
 * ```
 *
 * `office_id` leads so a misconfigured backup, or an operator listing a
 * directory, meets the Office boundary the database enforces. The date segments
 * keep any single directory from growing without bound.
 *
 * **The stored name is generated and the original is never a path component.**
 * User-supplied filenames carry traversal sequences, case collisions, characters
 * no filesystem agrees about, and — for a KTP scan — often the subject's own name.
 * The original is kept in metadata so a download can restore it; it never touches
 * the disk.
 *
 * The extension is derived from the uploaded name and reduced to lowercase
 * alphanumerics, so a crafted name cannot smuggle a separator into the path. A
 * file with no usable extension is stored without one rather than refused: the
 * bytes and the recorded `mime_type` are what matter.
 *
 * ## The checksum
 *
 * SHA-256, computed from **the bytes actually written** rather than from the
 * upload before it lands. Hashing the source would attest to something other than
 * what is stored, which is precisely the case a checksum exists to catch.
 *
 * ## The disk is a constructor parameter, not a constant
 *
 * Every real document goes through the default — `app(DocumentStorage::class)`
 * resolves this with no disk argument, so production behaviour is exactly what
 * it was when `DISK` was a class constant. The parameter exists for exactly one
 * other caller: local demo tooling, which constructs its own
 * `new DocumentStorage('local_demo')` so demo files land on a disk that is never
 * `storage/app/private` and is never read by anything that serves a real
 * document. Nothing in this class decides which disk that is beyond taking
 * whatever it is handed.
 */
class DocumentStorage
{
    public const ROOT = 'documents';

    public function __construct(
        private readonly string $disk = 'local',
    ) {}

    /**
     * The disk this instance stores to and reads from — never a credential or a
     * path, just the configured disk name a caller may need to clean up after
     * itself (see {@see delete()}).
     */
    public function diskName(): string
    {
        return $this->disk;
    }

    /**
     * Store an uploaded file and return the metadata a version row needs.
     *
     * **Writes no database row.** The caller decides whether the write happened
     * inside a transaction and what to do if the insert then fails — see the
     * note on orphans below.
     *
     * @return array{storage_disk: string, storage_path: string, original_filename: string,
     *               stored_filename: string, mime_type: string, file_size: int,
     *               checksum_sha256: string}
     */
    public function store(UploadedFile $file, Document $document): array
    {
        $now = Date::now();

        $storedFilename = $this->storedFilename($file);

        $directory = sprintf(
            '%s/%s/%s/%s',
            self::ROOT,
            $document->office_id,
            $now->format('Y'),
            $now->format('m'),
        );

        $path = $this->disk()->putFileAs($directory, $file, $storedFilename);

        if ($path === false) {
            throw new RuntimeException(
                'Failed to store the uploaded document. '
                .'Refusing to record a version whose file is not on disk: a metadata row pointing at '
                .'nothing is worse than a failed upload, because it looks like a document that exists.'
            );
        }

        return [
            'storage_disk' => $this->disk,
            'storage_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            // The browser-supplied type is not trusted for anything but display;
            // `getMimeType()` reads the file itself.
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => (int) $this->disk()->size($path),
            'checksum_sha256' => $this->checksum($path),
        ];
    }

    /**
     * The SHA-256 of a stored file, lowercase hex.
     *
     * Read from the disk rather than from an upload, so it attests to what is
     * actually there.
     */
    public function checksum(string $storagePath): string
    {
        $absolute = $this->disk()->path($storagePath);

        $digest = hash_file('sha256', $absolute);

        if ($digest === false) {
            throw new RuntimeException("Could not checksum the stored file at [{$storagePath}].");
        }

        return $digest;
    }

    /**
     * Whether the bytes on disk still match what was recorded at upload.
     *
     * Nothing calls this in M5.1. It exists because the checksum is otherwise a
     * column nobody can act on, and verifying an archive is exactly the operation
     * it was stored for.
     */
    public function matchesChecksum(DocumentVersion $version): bool
    {
        return $this->exists($version)
            && hash_equals($version->checksum_sha256, $this->checksum($version->storage_path));
    }

    public function exists(DocumentVersion $version): bool
    {
        return Storage::disk($version->storage_disk)->exists($version->storage_path);
    }

    /**
     * A read stream for one version.
     *
     * **Authorization is the caller's job and is not optional.** This class
     * knows nothing about who is asking; handing it a version is a statement
     * that a Policy already said yes.
     *
     * @return resource
     */
    public function readStream(DocumentVersion $version)
    {
        $stream = Storage::disk($version->storage_disk)->readStream($version->storage_path);

        if ($stream === null) {
            throw new RuntimeException(
                "The file for version [{$version->getKey()}] is missing from disk. "
                .'The metadata says it exists, which means either the file was removed outside the '
                .'application or a write failed without rolling back.'
            );
        }

        return $stream;
    }

    /**
     * Remove a stored file.
     *
     * **Nothing in M5 calls this on a live document**, and it is not a delete
     * path: `CLAUDE.md` section 30 prefers archive over deletion for legal
     * records, and section 19 forbids overwriting a version. It exists so a
     * caller that wrote a file and then failed to record it can clean up rather
     * than leaving an orphan — the one case where removing bytes is right.
     */
    public function delete(string $disk, string $storagePath): bool
    {
        return Storage::disk($disk)->delete($storagePath);
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * A generated, unguessable filename that cannot alter the path.
     */
    private function storedFilename(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        // Everything that is not a lowercase letter or digit is dropped, so a
        // name like `x.pdf/../../etc` cannot contribute a separator, and a
        // double extension cannot smuggle one either.
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        $name = (string) Str::ulid();

        return $extension === '' ? $name : "{$name}.{$extension}";
    }
}
