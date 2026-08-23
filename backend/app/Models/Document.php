<?php

namespace App\Models;

use App\Domains\Document\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * The office's record of one document (M5.1, D-116).
 *
 * **A Document is not a file.** The bytes live on {@see DocumentVersion}; this
 * carries identity, classification and state. That separation is what makes
 * "never overwrite a version" (`CLAUDE.md` section 19) expressible at all.
 *
 * **`office_id` is immutable**, following `ServiceType` and `WorkflowTemplate`:
 * it is the security boundary and the `OFFICE` scope predicate, and moving a
 * document between Offices would silently redefine who may read it. Whether a
 * transfer should ever exist is its own architecture decision, not an edit.
 *
 * **`is_sensitive` is set by whoever files the document and never inferred**
 * (D-115). Deriving it from `document_type_code` would encode which document
 * kinds are sensitive — a judgement that varies by office, and one no canonical
 * document makes.
 *
 * **No `SoftDeletes`, though `deleted_at` exists.** The column is reserved
 * capability the ERD carries and `documents.delete` is canonical and *"must be
 * heavily restricted"*; M5.1 builds no deletion path, so no global scope filters
 * any query and "invisible because deleted" cannot be confused with "invisible
 * because out of scope" — the M4.2 position (D-102).
 *
 * **Archiving is a state, not a deletion**, and `CLAUDE.md` section 30 prefers it
 * for legal records. `archived_at` / `archived_by` are written by the milestone
 * that owns archiving; nothing here reaches them.
 */
#[Fillable([
    'document_type_code',
    'title',
    'is_sensitive',
    'document_date',
    'expiry_date',
    'notes',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty('office_id')) {
                throw new RuntimeException(
                    'documents.office_id is immutable (M5.1). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a '
                    .'document between Offices would silently redefine who may read it. '
                    .'Lifting this needs its own architecture decision.'
                );
            }
        });

        // The composite foreign key that enforces this is PostgreSQL-only —
        // SQLite cannot add a foreign key to an existing table, and the two
        // tables reference each other so one key must arrive by ALTER. This
        // guard is what holds the same rule on the test connection, so a defect
        // fails in the suite rather than only in production.
        static::saving(function (self $document): void {
            if ($document->current_version_id === null) {
                return;
            }

            $belongsHere = DocumentVersion::query()
                ->whereKey($document->current_version_id)
                ->where('document_id', $document->getKey())
                ->exists();

            if (! $belongsHere) {
                throw new RuntimeException(
                    'documents.current_version_id must name a version of this document (M5.1, D-116). '
                    .'A pointer at another document\'s version would make "which file is current" '
                    .'answerable two different ways.'
                );
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The version whose file is the document as it currently stands.
     *
     * Nullable permanently rather than pending: a Document may legitimately exist
     * before its first file lands, which is the ordinary state between creating
     * the row and writing the version.
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * Every version, newest first.
     *
     * Ordered here rather than at each call site: a version list read out of
     * order invites somebody to mistake the first row for the current one.
     * `version_number` is unique per document, so the order is total.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'is_sensitive' => 'boolean',
            'document_date' => 'date',
            'expiry_date' => 'date',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
