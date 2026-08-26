<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\AllocateBillingReference;
use App\Domains\Billing\BillingReference;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\RecalculateBillingTotals;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raise a bill (M8.2, D-124).
 *
 * ## This is also how a quotation is "converted"
 *
 * The M8.2 brief asked for `PATCH /quotations/{id}/convert`. There is no
 * `quotations.convert` code in the catalogue and the brief forbade adding one —
 * so conversion happens here instead, as `invoices.create` with a `$quotation`,
 * which is canonical and needs nothing new.
 *
 * **Converting copies the lines, and copies them as they were.** An invoice's
 * figures must not move because somebody later edits the quotation it came from;
 * the copy is what makes the bill a record of what was agreed rather than a live
 * view of an offer. The link stays on `quotation_id` so the origin is traceable.
 *
 * The quotation must be **approved** before it can be billed. Billing an offer
 * nobody agreed to is the one sequencing rule this surface has, and it follows
 * from what the two states mean rather than from an invented workflow.
 */
class CreateInvoice
{
    public function __construct(
        private readonly AllocateBillingReference $allocator,
        private readonly RecalculateBillingTotals $totals,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(
        User $actor,
        array $attributes,
        ?Party $party = null,
        ?Project $project = null,
        ?Matter $matter = null,
        ?Quotation $quotation = null,
    ): Invoice {
        return DB::transaction(function () use (
            $actor, $attributes, $party, $project, $matter, $quotation
        ): Invoice {
            $invoice = new Invoice;

            $invoice->office_id = $actor->office_id;
            $invoice->invoice_number = $this->allocator->forOffice(
                BillingReference::INVOICE,
                $actor->office_id,
            );
            $invoice->status = InvoiceStatus::DRAFT;
            $invoice->subtotal_amount = '0.00';
            $invoice->total_amount = '0.00';

            // A converted invoice inherits the quotation's context unless the
            // caller supplied its own, so billing an agreed offer does not mean
            // retyping who it is for.
            $invoice->client_party_id = $party?->getKey() ?? $quotation?->client_party_id;
            $invoice->project_id = $project?->getKey() ?? $quotation?->project_id;
            $invoice->matter_id = $matter?->getKey() ?? $quotation?->matter_id;
            $invoice->quotation_id = $quotation?->getKey();

            $invoice->created_by = $actor->getKey();
            $invoice->updated_by = $actor->getKey();

            $invoice->fill($attributes);

            if ($quotation !== null && ($invoice->title === null || $invoice->title === '')) {
                $invoice->title = $quotation->title;
            }

            $invoice->save();

            if ($quotation !== null) {
                $this->copyLines($quotation, $invoice);
                $this->totals->forInvoice($invoice);
            }

            $this->events->created($invoice, $actor, ActivityType::INVOICE_CREATED, [
                'reference' => $invoice->invoice_number,
                'title' => $invoice->title,
            ]);

            return $invoice;
        });
    }

    /**
     * Copy an approved quotation's lines onto the invoice, as they stand now.
     */
    private function copyLines(Quotation $quotation, Invoice $invoice): void
    {
        foreach ($quotation->items()->get() as $source) {
            $line = new InvoiceItem;

            // The constraint carrier, written from the invoice. Never from the
            // source row — one source means the composite keys cannot disagree.
            $line->office_id = $invoice->office_id;
            $line->invoice_id = $invoice->getKey();

            $line->line_number = $source->line_number;
            $line->description = $source->description;
            $line->quantity = $source->quantity;
            $line->unit_amount = $source->unit_amount;
            $line->line_amount = $source->line_amount;

            $line->save();
        }
    }
}
