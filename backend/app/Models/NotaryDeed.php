<?php

namespace App\Models;

use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\NotaryDeedVisibility;
use Database\Factories\NotaryDeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A Notarial Deed — Akta Notaris (M6.1, D-120).
 *
 * **`office_id` and `matter_id` are immutable.** Office is the security boundary and
 * the `OFFICE` scope predicate; moving a deed between Offices would silently
 * redefine who may see it and would strand six references the composite keys hold to
 * that Office. `matter_id` is what the deed *is the output of* — a deed that changed
 * Matter would be a different deed, and its `OWN` and `ASSIGNED` reach would move
 * with it, since both resolve through the parent.
 *
 * **`status`, the three act-pairs and `locked_at` are not fillable.** Each act
 * answers to its own capability — `notary.deeds.review`, `.approve`, `.finalize` —
 * so letting mass assignment reach any of them would make `notary.deeds.update` a
 * silent superset of all three (the D-091 discipline). `deed_number` is excluded for
 * the same reason: it answers to `notary.deeds.number`.
 *
 * **No `SoftDeletes`, and no `deleted_at` column to support one.** The ERD omits it,
 * `03_DATABASE_ERD.md` section 33 prefers states over destructive deletion for
 * finalized legal records, `CLAUDE.md` section 30 forbids user-facing hard delete of
 * finalized Deeds, and no `notary.deeds.delete` capability exists.
 *
 * **`VOID` and `SUPERSEDED` are reachable by no method on this class.** They are
 * canonical vocabulary the CHECK constraint admits; the correction mechanisms that
 * would produce them are open question five. See {@see NotaryDeedStatus}.
 */
#[Fillable([
    'title',
    'deed_date',
    'deed_type_code',
    'draft_document_id',
    'final_document_id',
    'minuta_document_id',
])]
class NotaryDeed extends Model
{
    /** @use HasFactory<NotaryDeedFactory> */
    use HasFactory;

    use HasUlids;

    protected static function booted(): void
    {
        static::updating(function (self $deed): void {
            if ($deed->isDirty('office_id')) {
                throw new RuntimeException(
                    'notary_deeds.office_id is immutable (M6.1). '
                    .'Office is the security boundary and the OFFICE scope predicate, so moving a deed '
                    .'between Offices would silently redefine who may see it — and would strand the six '
                    .'references the composite keys hold to that Office.'
                );
            }

            if ($deed->isDirty('matter_id')) {
                throw new RuntimeException(
                    'notary_deeds.matter_id is immutable (M6.1, D-120). '
                    .'A deed is the output of one Matter, and its OWN and ASSIGNED reach resolve '
                    .'through that Matter — changing it would move the deed between people\'s reach '
                    .'without anybody deciding it.'
                );
            }
        });

        // The three act-pairs are enforced by PostgreSQL CHECKs; this is what holds
        // the same rule on the SQLite connection the suite runs on, so a half-written
        // approval fails in the tests rather than only in production.
        static::saving(function (self $deed): void {
            foreach (['reviewed', 'approved', 'finalized'] as $act) {
                $hasWhen = $deed->{"{$act}_at"} !== null;
                $hasWho = $deed->{"{$act}_by"} !== null;

                if ($hasWhen !== $hasWho) {
                    throw new RuntimeException(
                        "notary_deeds {$act} is recorded as a pair (M6.1). "
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
     * The Matter this deed is the output of.
     *
     * Also the source of the `OWN` and `ASSIGNED` predicates — see
     * {@see NotaryDeedVisibility}.
     */
    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function draftDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'draft_document_id');
    }

    public function finalDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'final_document_id');
    }

    /**
     * The Minuta Akta file.
     *
     * A pointer at a Document, not at the `notary_minuta` metadata row — that table
     * arrives at M6.3 and records where the physical original is filed. The two are
     * different questions: this is *which file*, that is *which shelf*.
     */
    public function minutaDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'minuta_document_id');
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
            'status' => NotaryDeedStatus::class,
            'deed_date' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }
}
