<?php

namespace App\Models;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\PpatDeedVisibility;
use Database\Factories\PpatDeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * A PPAT Deed (M7.1, D-121).
 *
 * The structural twin of {@see NotaryDeed}, with two differences that are not
 * cosmetic:
 *
 * **One document pointer, not three.** `final_document_id` only — PPAT supporting
 * material is the Warkah, which is its own table. The ERD says so, and adding a
 * `minuta_document_id` by analogy would suggest PPAT deeds have Minuta Akta, which is
 * a Notary instrument.
 *
 * **The status vocabulary is a decision, not a transcription.** The ERD gives
 * `ppat_deeds` no status values; M7 adopts Notary six on `CLAUDE.md` section 29
 * authority. See {@see PpatDeedStatus}.
 *
 * **`office_id` and `matter_id` are immutable.** Office is the security boundary and
 * the `OFFICE` predicate; `matter_id` is what the deed is the output of, and its
 * `OWN` and `ASSIGNED` reach resolve through that Matter, so changing it would move
 * the deed between people reach without anybody deciding it.
 *
 * **`status`, the three act-pairs, `deed_number` and `locked_at` are not fillable.**
 * Each answers to its own capability, so mass assignment reaching any of them would
 * make `ppat.deeds.update` a silent superset of four other codes (D-091).
 *
 * **No `SoftDeletes` and no `deleted_at`.** The ERD omits it, section 33 prefers
 * states over destructive deletion for finalized legal records, `CLAUDE.md` section
 * 30 forbids user-facing hard delete of Deeds, and no `ppat.deeds.delete` capability
 * exists.
 */
#[Fillable([
    'title',
    'deed_date',
    'deed_type_code',
    'final_document_id',
])]
class PpatDeed extends Model
{
    /** @use HasFactory<PpatDeedFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $deed): void {
            if ($deed->isDirty('office_id')) {
                throw new RuntimeException(
                    'ppat_deeds.office_id is immutable (M7.1). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a '
                    .'deed between Offices would silently redefine who may see it, and would strand '
                    .'the five references the composite keys hold to that Office.'
                );
            }

            if ($deed->isDirty('matter_id')) {
                throw new RuntimeException(
                    'ppat_deeds.matter_id is immutable (M7.1, D-121). '
                    .'A deed is the output of one Matter, and its OWN and ASSIGNED reach resolve '
                    .'through that Matter, so changing it would move the deed between people '
                    .'without anybody deciding it.'
                );
            }
        });

        // The three act-pairs are enforced by PostgreSQL CHECKs; this holds the same
        // rule on the SQLite connection the suite runs on.
        static::saving(function (self $deed): void {
            foreach (['reviewed', 'approved', 'finalized'] as $act) {
                $hasWhen = $deed->{"{$act}_at"} !== null;
                $hasWho = $deed->{"{$act}_by"} !== null;

                if ($hasWhen !== $hasWho) {
                    throw new RuntimeException(
                        "ppat_deeds {$act} is recorded as a pair (M7.1). "
                        ."{$act}_at and {$act}_by are written together and cleared together: half of "
                        .'an act is a row nobody can explain.'
                    );
                }
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The Matter this deed is the output of, and the source of every Data Scope
     * predicate — see {@see PpatDeedVisibility}.
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    /**
     * The executed deed.
     *
     * The only document pointer the ERD gives this table. Supporting material is the
     * Warkah.
     */
    public function finalDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'final_document_id');
    }

    /**
     * The supporting documents bound with this deed.
     *
     * `hasOne`, enforced by a unique index: a Warkah is the bundle *of one deed*.
     */
    public function warkah(): HasOne
    {
        return $this->hasOne(PpatWarkah::class, 'ppat_deed_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * Read-only under normal operations.
     *
     * `CLAUDE.md` sections 29 and 64: once finalized, prevent normal edits, show the
     * record as locked, and preserve the original values.
     */
    public function isReadOnly(): bool
    {
        return $this->status->isSettled() || $this->locked_at !== null;
    }

    protected function casts(): array
    {
        return [
            'status' => PpatDeedStatus::class,
            'deed_date' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }
}
