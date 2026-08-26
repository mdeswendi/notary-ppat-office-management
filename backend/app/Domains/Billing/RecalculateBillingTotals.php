<?php

namespace App\Domains\Billing;

use App\Models\Invoice;
use App\Models\Quotation;

/**
 * Keep a document's totals equal to the sum of its lines (M8.2, D-124).
 *
 * ## What this does and does not compute
 *
 * `subtotal_amount` and `total_amount` are both the sum of the document's
 * `line_amount`s. They are equal today; they are separate columns because a
 * discount is a commercial fact somebody may add later, and it would belong
 * between them.
 *
 * **Nothing here applies a rate to anything.** No tax, no rounding rule, no
 * derived percentage — D-124 section 9.4 forbids all three, `CLAUDE.md`
 * section 62 names tax rules among the things not to invent, and O-040 is open.
 * An office that must show PPN adds a line it names and prices itself, and this
 * class then adds that line up like any other. Summing what somebody typed is
 * arithmetic; deriving it from a rate would be a tax rule.
 *
 * ## Called from inside the Action's transaction
 *
 * Never from a model event. A line and its parent's total must move together or
 * not at all, and an observer would fire outside whatever transaction the caller
 * opened — leaving a document whose lines disagree with its total if anything
 * later in the request failed.
 */
class RecalculateBillingTotals
{
    /**
     * Recompute and persist a quotation's totals from its lines.
     */
    public function forQuotation(Quotation $quotation): Quotation
    {
        $subtotal = (float) $quotation->items()->sum('line_amount');

        $quotation->subtotal_amount = $this->money($subtotal);
        $quotation->total_amount = $this->money($subtotal);
        $quotation->save();

        return $quotation;
    }

    /**
     * Recompute and persist an invoice's totals from its lines.
     */
    public function forInvoice(Invoice $invoice): Invoice
    {
        $subtotal = (float) $invoice->items()->sum('line_amount');

        $invoice->subtotal_amount = $this->money($subtotal);
        $invoice->total_amount = $this->money($subtotal);
        $invoice->save();

        return $invoice;
    }

    /**
     * One line's amount: quantity times unit price, and nothing else.
     */
    public function lineAmount(float $quantity, float $unitAmount): string
    {
        return $this->money($quantity * $unitAmount);
    }

    /**
     * Two decimal places, as a string.
     *
     * A string rather than a float, so the value handed to the database is the
     * one that was computed. Binary floats do not represent every two-decimal
     * value exactly, and a total that arrives as 1199.9999999999998 is a total
     * somebody will eventually see.
     */
    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
