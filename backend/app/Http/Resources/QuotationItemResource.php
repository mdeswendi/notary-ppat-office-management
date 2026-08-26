<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\QuotationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line on a quotation (M8.2, D-125).
 *
 * **The description survives masking; the figures do not.** Somebody without
 * `billing.amount.view` can see *what* is being charged for — which is often the
 * operationally useful half — without seeing what it costs. `quantity` is
 * withheld along with the money, because quantity and a known rate reconstruct
 * the amount.
 *
 * @mixin QuotationItem
 */
class QuotationItemResource extends JsonResource
{
    use MasksBillingAmounts;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'description' => $this->description,

            'amounts_visible' => $this->amountsVisible($request),

            ...$this->withAmounts($request, [
                'quantity' => $this->quantity,
                'unit_amount' => $this->unit_amount,
                'line_amount' => $this->line_amount,
            ]),
        ];
    }
}
