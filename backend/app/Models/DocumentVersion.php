<?php

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One uploaded file, written once (M5.1, D-116).
 *
 * **Immutable, and enforced rather than intended.** `CLAUDE.md` section 19 and
 * `03_DATABASE_ERD.md` section 13 both say never overwrite an existing version;
 * this model refuses `update` outright. A correction is a new version, which is
 * the whole reason the file lives here rather than on the Document.
 *
 * That is why there is **no `updated_at`**: the ERD gives this table `uploaded_at`
 * alone, and a column recording when a version changed would advertise a mutation
 * that must never happen. Eloquent's automatic timestamps are off to match,
 * following `project_parties` (D-098).
 *
 * **`storage_path` and `stored_filename` are hidden.** Neither is a secret on its
 * own — M5.0 turned off the route that served that directory (D-114) — but an API
 * that returns a storage path invites a client to try it, and the next person to
 * add a disk with `serve => true` would turn a leaked path into a live download.
 * The file is reachable only by streaming it from a surface that authorized the
 * actor first.
 *
 * **`checksum_sha256` proves the bytes are the ones uploaded**, which is only
 * meaningful because nothing rewrites them. It is lowercase hex and exactly 64
 * characters; PostgreSQL enforces the format, and this model refuses a malformed
 * digest on the test connection too.
 */
#[Fillable([
    'version_number',
    'storage_disk',
    'storage_path',
    'original_filename',
    'stored_filename',
    'mime_type',
    'file_size',
    'checksum_sha256',
    'uploaded_at',
])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * `uploaded_at` and nothing else. See the class docblock.
     */
    public $timestamps = false;

    /**
     * Never serialized. The path is infrastructure, not information a client has
     * any use for.
     *
     * @var list<string>
     */
    protected $hidden = ['storage_path', 'stored_filename'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'document_versions is write-once (M5.1, D-116). '
                .'CLAUDE.md section 19 and the ERD both require that an existing version is never '
                .'overwritten: a correction adds a version, and the previous file stays exactly as '
                .'it was. Editing one would rewrite what the checksum attests to.'
            );
        });

        static::saving(function (self $version): void {
            // The private-path rule, enforced on both engines. PostgreSQL has a
            // CHECK; this is what holds on SQLite, where the suite runs.
            $path = (string) $version->storage_path;

            foreach (['public/', 'uploads/'] as $forbidden) {
                if (str_starts_with($path, $forbidden) || str_contains($path, '/'.$forbidden)) {
                    throw new RuntimeException(
                        "document_versions.storage_path must not contain [{$forbidden}] (CLAUDE.md section 19). "
                        .'A legal document never lives in a public web directory.'
                    );
                }
            }

            if (preg_match('/^[0-9a-f]{64}$/', (string) $version->checksum_sha256) !== 1) {
                throw new RuntimeException(
                    'document_versions.checksum_sha256 must be 64 lowercase hex characters (M5.1). '
                    .'A truncated or uppercased digest compares unequal to a correctly computed one, '
                    .'and the mismatch looks like corruption rather than a formatting mistake.'
                );
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }
}
