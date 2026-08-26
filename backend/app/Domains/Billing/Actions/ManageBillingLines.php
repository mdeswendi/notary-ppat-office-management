<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\RecalculateBillingTotals;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Add, correct and remove the lines of a quotation or an invoice (M8.2, D-124).
 *
 * ## One class for both, and no capability of its own
 *
 * A line is not a thing the catalogue authorizes separately: there is no
 * `invoices.items.*` family, and editing what an invoice charges for **is**
 * editing the invoice. So every method here is reached under the parent's
 * `update` ability, which is `DRAFT`-only — an issued invoice's lines are as
 * fixed as its total.
 *
 * The two parents share this class for the same reason their tables share a
 * migration: the logic is identical, and two copies would be two places for one
 * rule to drift.
 *
 * ## `line_amount` is computed here, never submitted
 *
 * `quantity * unit_amount`, and nothing else — no rate, no tax, no rounding rule
 * beyond two decimal places (D-124 section 9.4). A caller that could submit the
 * amount directly could produce a document whose lines do not add up to its
 * total, which is why the column is not fillable on either model.
 *
 * ## Totals move in the same transaction as the line
 *
 * Never through a model observer. A line and its parent's total must commit
 * together or not at all; an observer fires outside whatever transaction the
 * caller opened, and a failure later in the request would leave a document whose
 * lines disagree with what it asks for.
 *
 * ## Audited as an update to the parent
 *
 * A line change is a change to the document, so the audit row names the invoice
 * or quotation rather than the line. **No activity row**: a timeline reporting
 * every edited line is a timeline nobody reads, which is the D-128 rule for
 * field corrections.
 */
class ManageBillingLines
{
    public function __construct(
        private readonly RecalculateBillingTotals $totals,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addToQuotation(User $actor, Quotation $quotation, array $attributes): QuotationItem
    {
        return DB::transaction(function () use ($actor, $quotation, $attributes): QuotationItem {
            $line = new QuotationItem;
            $line->office_id = $quotation->office_id;
            $line->quotation_id = $quotation->getKey();

            $this->writeLine($line, $attributes, $this->nextLineNumber($quotation->items()->max('line_number')));

            $this->afterQuotationChange($actor, $quotation);

            return $line;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addToInvoice(User $actor, Invoice $invoice, array $attributes): InvoiceItem
    {
        return DB::transaction(function () use ($actor, $invoice, $attributes): InvoiceItem {
            $line = new InvoiceItem;
            $line->office_id = $invoice->office_id;
            $line->invoice_id = $invoice->getKey();

            $this->writeLine($line, $attributes, $this->nextLineNumber($invoice->items()->max('line_number')));

            $this->afterInvoiceChange($actor, $invoice);

            return $line;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateQuotationLine(
        User $actor,
        Quotation $quotation,
        QuotationItem $line,
        array $attributes,
    ): QuotationItem {
        return DB::transaction(function () use ($actor, $quotation, $line, $attributes): QuotationItem {
            $this->writeLine($line, $attributes, $line->line_number);

            $this->afterQuotationChange($actor, $quotation);

            return $line;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateInvoiceLine(
        User $actor,
        Invoice $invoice,
        InvoiceItem $line,
        array $attributes,
    ): InvoiceItem {
        return DB::transaction(function () use ($actor, $invoice, $line, $attributes): InvoiceItem {
            $this->writeLine($line, $attributes, $line->line_number);

            $this->afterInvoiceChange($actor, $invoice);

            return $line;
        });
    }

    public function removeQuotationLine(User $actor, Quotation $quotation, QuotationItem $line): void
    {
        DB::transaction(function () use ($actor, $quotation, $line): void {
            // A hard delete, deliberately. A line on a draft document is
            // working material rather than a record of anything: nobody outside
            // the office has seen it, and a soft-deleted line would still have
            // to be excluded from every sum by hand.
            $line->delete();

            $this->afterQuotationChange($actor, $quotation);
        });
    }

    public function removeInvoiceLine(User $actor, Invoice $invoice, InvoiceItem $line): void
    {
        DB::transaction(function () use ($actor, $invoice, $line): void {
            $line->delete();

            $this->afterInvoiceChange($actor, $invoice);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeLine(Model $line, array $attributes, int $lineNumber): void
    {
        $line->fill(array_intersect_key(
            $attributes,
            array_flip(['description', 'quantity', 'unit_amount']),
        ));

        $line->line_number = $attributes['line_number'] ?? $lineNumber;

        // Computed, never accepted from the caller.
        $line->line_amount = $this->totals->lineAmount(
            (float) $line->quantity,
            (float) $line->unit_amount,
        );

        $line->save();
    }

    private function nextLineNumber(mixed $current): int
    {
        return ((int) $current) + 1;
    }

    private function afterQuotationChange(User $actor, Quotation $quotation): void
    {
        $quotation->updated_by = $actor->getKey();

        $this->totals->forQuotation($quotation);

        $this->events->updated($quotation, $actor);
    }

    private function afterInvoiceChange(User $actor, Invoice $invoice): void
    {
        $invoice->updated_by = $actor->getKey();

        $this->totals->forInvoice($invoice);

        $this->events->updated($invoice, $actor);
    }
}
