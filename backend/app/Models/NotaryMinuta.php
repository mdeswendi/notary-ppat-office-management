<?php

namespace App\Models;

use App\Policies\NotaryDeedPolicy;
use Database\Factories\NotaryMinutaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Minuta Akta — where the original deed record is filed (M6.3, D-120).
 *
 * **The table name is singular and stays that way.** `03_DATABASE_ERD.md` section 17
 * names it `notary_minuta`; *minuta* is already the Indonesian legal term and
 * pluralising it to `notary_minutas` would invent a word. `05_I18N_LEGAL_TERMINOLOGY.md`
 * fixes **Minuta Akta** as terminology that must be used exactly as written.
 *
 * **`office_id`, `notary_deed_id` and `document_id` are immutable... except one.**
 * Office is the security boundary; the deed is what this Minuta *is the original
 * record of*, and moving it would silently re-file one deed's original under
 * another. `document_id` is deliberately **mutable** — replacing a bad scan is
 * ordinary correction, and the M6.3 brief asked for it explicitly. The Document's own
 * version history is untouched either way (D-116).
 *
 * **`release_status`, `archived_at` and `archived_by` are not fillable and nothing
 * writes them.** The ERD names all three and gives `release_status` no vocabulary at
 * all; *"What triggers Minuta Akta archiving, and what release conditions apply?"* is
 * open question four. `notary.minuta.archive` and `notary.minuta.release` stay
 * registered and unimplemented until somebody answers it.
 */
#[Fillable([
    'document_id',
    'archive_location',
    'volume_number',
    'bundle_number',
    'notes',
])]
class NotaryMinuta extends Model
{
    /** @use HasFactory<NotaryMinutaFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The ERD's name, singular. Eloquent would otherwise guess `notary_minutas`.
     */
    protected $table = 'notary_minuta';

    protected static function booted(): void
    {
        static::updating(function (self $minuta): void {
            if ($minuta->isDirty('office_id')) {
                throw new RuntimeException(
                    'notary_minuta.office_id is immutable (M6.3). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a '
                    .'Minuta between Offices would silently redefine who may see it — and would '
                    .'strand the three references the composite keys hold to that Office.'
                );
            }

            if ($minuta->isDirty('notary_deed_id')) {
                throw new RuntimeException(
                    'notary_minuta.notary_deed_id is immutable (M6.3, D-120). '
                    .'A Minuta Akta is the original record of one deed; re-pointing it would file '
                    .'one deed\'s original under another. Correcting the file replaces document_id.'
                );
            }
        });

        // Enforced by a PostgreSQL CHECK too; this is what holds the same rule on
        // the SQLite connection the suite runs on. Nothing in M6 writes the pair —
        // the guard exists so a later milestone cannot write half of one.
        static::saving(function (self $minuta): void {
            if (($minuta->archived_at !== null) !== ($minuta->archived_by !== null)) {
                throw new RuntimeException(
                    'notary_minuta archival is recorded as a pair (M6.3). '
                    .'archived_at and archived_by are written together and cleared together: half of '
                    .'an archival is a row nobody can explain.'
                );
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The deed whose original this is.
     *
     * Also the source of every Data Scope predicate — see
     * {@see NotaryDeedPolicy}. A Minuta has no owner, no assignee and
     * no Office of its own choosing; it is reached exactly as its deed is.
     */
    public function deed(): BelongsTo
    {
        return $this->belongsTo(NotaryDeed::class, 'notary_deed_id');
    }

    /**
     * The file.
     *
     * A Document, never a version. Replacing this pointer changes which file the
     * Minuta refers to and leaves both Documents' version histories intact.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Canonical column, written by nothing in M6.
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}
