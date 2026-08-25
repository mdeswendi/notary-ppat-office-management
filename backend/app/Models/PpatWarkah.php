<?php

namespace App\Models;

use App\Domains\Ppat\Enums\PpatWarkahStatus;
use Database\Factories\PpatWarkahFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Warkah — the supporting documents bound with a PPAT Deed (M7.1, D-121).
 *
 * **The table name is singular and stays that way.** `03_DATABASE_ERD.md` section 19
 * names it `ppat_warkah`; *Warkah* is already the Indonesian legal term and
 * `05_I18N_LEGAL_TERMINOLOGY.md` fixes it as terminology to be used exactly as
 * written. Pluralising it to `ppat_warkahs` would invent a word — the same ruling
 * `notary_minuta` got at M6.3.
 *
 * **One per deed**, enforced by a unique index.
 *
 * ## Completeness counts what the office listed
 *
 * {@see recalculateCompleteness()} is the ruling this class exists to hold. A
 * percentage is meaningless without a denominator, and the denominator is the
 * mandatory Warkah composition per deed type that nobody has authored — open question
 * three in `09_PPAT_WORKFLOW.md` section 6.
 *
 * So the number counts **items with at least one document attached, over items this
 * office created**. It does not consult `ppat_warkah_items.status`, because that
 * column has no canonical vocabulary and inventing one would be inventing the
 * verification rule. It does not consult a requirement template, because none exists
 * (D-104).
 *
 * **100% does not mean legally complete.** It means every line this office wrote has
 * a file against it.
 *
 * **Status is never derived from the percentage, in either direction.** `COMPLETE`
 * does not follow from 100% and 100% does not require `COMPLETE` — which of the two
 * governs sufficiency is exactly what open question three does not answer.
 */
#[Fillable([
    'archive_location',
    'notes',
])]
class PpatWarkah extends Model
{
    /** @use HasFactory<PpatWarkahFactory> */
    use HasFactory;

    use HasUlids;

    /** The ERD name, singular. Eloquent would otherwise guess `ppat_warkahs`. */
    protected $table = 'ppat_warkah';

    protected static function booted(): void
    {
        static::updating(function (self $warkah): void {
            foreach (['office_id', 'ppat_deed_id'] as $attribute) {
                if ($warkah->isDirty($attribute)) {
                    throw new RuntimeException(
                        "ppat_warkah.{$attribute} is immutable (M7.1, D-121). "
                        .'A Warkah is the supporting bundle of one deed; re-pointing it would file '
                        .'one transaction evidence under another.'
                    );
                }
            }
        });

        static::saving(function (self $warkah): void {
            foreach (['verified', 'finalized'] as $act) {
                $hasWhen = $warkah->{"{$act}_at"} !== null;
                $hasWho = $warkah->{"{$act}_by"} !== null;

                if ($hasWhen !== $hasWho) {
                    throw new RuntimeException(
                        "ppat_warkah {$act} is recorded as a pair (M7.1). "
                        ."{$act}_at and {$act}_by are written together and cleared together."
                    );
                }
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function deed(): BelongsTo
    {
        return $this->belongsTo(PpatDeed::class, 'ppat_deed_id');
    }

    /**
     * The lines of this Warkah, in the order the office arranged them.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PpatWarkahItem::class, 'warkah_id')->orderBy('sequence_no');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Canonical column, written by nothing in M7.
     */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * Recount, and return the percentage without saving.
     *
     * **Collected means a document is attached**, which is observable and needs no
     * status vocabulary — see the class docblock. An empty Warkah is 0%, not 100%: a
     * bundle nobody has listed anything for has collected nothing, and returning 100
     * for it would be the most misleading answer available.
     */
    public function computeCompleteness(): int
    {
        $total = $this->items()->count();

        if ($total === 0) {
            return 0;
        }

        $collected = $this->items()->whereHas('documents')->count();

        return (int) round(($collected / $total) * 100);
    }

    /**
     * Recount and store.
     *
     * Called by whichever M7.4 surface changes items or their documents. It writes
     * only the percentage — **never the status**, which is somebody decision.
     */
    public function recalculateCompleteness(): int
    {
        $percentage = $this->computeCompleteness();

        $this->forceFill(['completeness_percentage' => $percentage])->save();

        return $percentage;
    }

    protected function casts(): array
    {
        return [
            'status' => PpatWarkahStatus::class,
            'completeness_percentage' => 'integer',
            'verified_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }
}
