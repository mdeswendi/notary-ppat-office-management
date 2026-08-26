<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charged line on an invoice (M8.2, D-124).
 *
 * **`line_amount` is not fillable**: it is `quantity * unit_amount` computed by
 * the Action, and a caller who could submit it directly could make an invoice
 * whose lines do not add up to its total.
 *
 * **This is where tax goes, if an office needs one.** D-124 section 9.4 forbids a
 * `tax` column and any calculation deriving a figure by a rate; an office showing
 * PPN adds a line it names and prices itself. A typed line is a fact the office
 * asserted, where a computed one would be a tax rule this software encoded —
 * and O-040 is still open.
 */
#[Fillable([
    'description',
    'quantity',
    'unit_amount',
    'line_number',
])]
class InvoiceItem extends Model
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
