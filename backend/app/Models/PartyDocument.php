<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Document attached to a Party (M5.1, D-116).
 *
 * **Nothing is fillable.** All three columns identify the relationship rather
 * than describe it, and `office_id` in particular is the constraint carrier the
 * composite foreign keys check against — a `fill()` that could reach it would let
 * a caller route around the same-Office invariant the database enforces.
 *
 * A relationship, not a history: `attached_at` and `attached_by`, no
 * `updated_at`, no `deleted_at`. Detaching removes the row.
 */
#[Fillable([])]
class PartyDocument extends Model
{
    use HasUlids;

    protected $table = 'party_documents';

    public $timestamps = false;

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected function casts(): array
    {
        return ['attached_at' => 'datetime'];
    }
}
