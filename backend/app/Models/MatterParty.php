<?php

namespace App\Models;

use Database\Factories\MatterPartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Party's participation in one Matter (M4.5, D-105, D-110).
 *
 * **Two fields are fillable and three are not.** `role_code` and `notes` may be
 * corrected; `matter_id`, `party_id`, and `office_id` may not, at any point,
 * through any caller. They identify the relationship rather than describe it, and
 * `office_id` in particular is the constraint carrier the composite foreign keys
 * check against — a `fill()` that could reach it would let a future caller route
 * around the same-Office invariant the database enforces. Re-pointing a
 * participation at a different Party is not an edit but a different
 * relationship: remove and add instead.
 *
 * **No `SoftDeletes` and no `deleted_at`.** Removal deletes the row. This table
 * is current working state, not a ledger (D-105), so there is nothing to
 * restore and nothing that filters queries.
 *
 * `$timestamps` stays **on**, unlike {@see ProjectParty}, because
 * `03_DATABASE_ERD.md` section 9 gives this table `updated_at` and section 7
 * gives that one none. Same shape, different canonical field list.
 *
 * No Party identity is carried here. `display_name` and `party_type` are read
 * through the `party` relation when a caller is authorized to read them at all
 * (D-082).
 */
#[Fillable([
    'role_code',
    'notes',
])]
class MatterParty extends Model
{
    /** @use HasFactory<MatterPartyFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'matter_parties';

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
