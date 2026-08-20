<?php

namespace App\Models;

use Database\Factories\MatterStageHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One recorded stage transition (M4.7, D-104, D-112).
 *
 * **Append-only, and enforced rather than intended.** The model refuses `update`
 * and `delete` outright. D-104 records that whether a stage transition carries
 * legal state is undecided, and treats this table as append-only from the
 * outset — the safe direction to be wrong in. `CLAUDE.md` section 31 says the
 * same of audit records generally: never implement `audit.update` or
 * `audit.delete`.
 *
 * The schema agrees with the model: `changed_at` and no `updated_at`, no
 * `deleted_at`, and no code path anywhere that writes either.
 *
 * **Codes, not foreign keys.** `from_stage_code` and `to_stage_code` are strings
 * copied at the moment of the transition. Resolving them through live stage rows
 * would let a later template edit rewrite what the record says happened — the
 * exact failure snapshotting exists to prevent.
 *
 * **`reason` is free text and therefore a leak surface.** D-105 is explicit that
 * Party identity — NIK, NPWP, tax identifiers — must never be persisted in a
 * field like this, and that the audit-adjacent free-text fields are where it
 * tends to end up. Nothing automated can enforce it; the interface warns and this
 * docblock states it.
 *
 * `from_stage_code` is null for the first transition, which has no origin.
 */
#[Fillable([])]
class MatterStageHistory extends Model
{
    /** @use HasFactory<MatterStageHistoryFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'matter_stage_history';

    /**
     * Only `changed_at`, which the writer stamps. There is no `updated_at` to
     * maintain, so Eloquent's automatic timestamps stay off.
     */
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'matter_stage_history is append-only (M4.7, D-104). '
                .'A transition record states what happened at a moment; editing one would '
                .'rewrite that. Record a further transition instead.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'matter_stage_history is append-only (M4.7, D-104). '
                .'Deleting a transition record would erase evidence of a change that occurred. '
                .'CLAUDE.md section 31 forbids it for audit records generally.'
            );
        });
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }
}
