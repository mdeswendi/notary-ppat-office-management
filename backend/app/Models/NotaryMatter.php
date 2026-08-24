<?php

namespace App\Models;

use Database\Factories\NotaryMatterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What is true of a Matter because it is a Notary Matter (M6.1, D-120).
 *
 * **An extension, not a second Matter root.** `matters` is the one root and carries
 * the canonical `domain` discriminator (M4.2). This row exists only where a Matter
 * needs Notary-specific classification, and it is keyed by `matter_id` — no
 * surrogate ULID, because a second identifier would be a second way to name the same
 * Matter.
 *
 * **No `HasUlids`.** The key is not generated here; it is the Matter's, and
 * generating one would produce a row pointing at nothing.
 *
 * **The three semantic columns are stored and read. Nothing branches on them.**
 * `requires_register_entry` in particular does not cause a Repertorium entry to be
 * created when a deed is finalized — the M6 brief asked for that, the procedure is
 * open question two in `08_NOTARY_WORKFLOW.md` section 6, and there is no register
 * table in M6 to write into. The columns record the office's own classification of
 * the Matter; acting on it needs a rule somebody has written down.
 */
#[Fillable([
    'deed_category',
    'requires_minuta',
    'requires_register_entry',
    'notes',
])]
class NotaryMatter extends Model
{
    /** @use HasFactory<NotaryMatterFactory> */
    use HasFactory;

    protected $primaryKey = 'matter_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::updating(function (self $extension): void {
            if ($extension->isDirty('matter_id')) {
                throw new RuntimeException(
                    'notary_matters.matter_id is immutable (M6.1). '
                    .'It is the primary key and the Matter this row extends; moving it would '
                    .'silently reclassify a different Matter.'
                );
            }

            if ($extension->isDirty('office_id')) {
                throw new RuntimeException(
                    'notary_matters.office_id is immutable (M6.1). '
                    .'It is inherited from the Matter and carries the composite key that keeps the '
                    .'two in agreement about which Office owns the work.'
                );
            }
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    protected function casts(): array
    {
        return [
            'requires_minuta' => 'boolean',
            'requires_register_entry' => 'boolean',
        ];
    }
}
