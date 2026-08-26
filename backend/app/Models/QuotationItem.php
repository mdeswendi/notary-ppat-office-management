<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line on a quotation (M8.2, D-124).
 *
 * **`line_amount` is not fillable**: it is `quantity * unit_amount` computed by
 * the Action, and a caller who could submit it directly could make a quotation
 * whose lines do not add up to its total.
 */
#[Fillable([
    'description',
    'quantity',
    'unit_amount',
    'line_number',
])]
class QuotationItem extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_amount' => 'decimal:2',
            'line_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
