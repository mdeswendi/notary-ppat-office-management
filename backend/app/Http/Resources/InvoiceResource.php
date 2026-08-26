<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Invoice as the API returns it (M8.2, D-124, D-125).
 *
 * ## Money is absent unless `billing.amount.view` is held
 *
 * Every monetary key — the totals, the paid and outstanding figures, and every
 * line's amounts — is merged in only when the grant allows. A masked amount is
 * **not in the payload**, not null and not a placeholder: see
 * {@see MasksBillingAmounts}.
 *
 * What survives masking is everything that is not money: the number, the client,
 * the status, the dates, and whether it is overdue. Somebody who may see that an
 * invoice exists and is late, without seeing what it is for, is exactly the
 * separation the two codes describe.
 *
 * ## `is_overdue` and `is_settled` are computed, and ship anyway
 *
 * Neither is a stored status (see `InvoiceStatus`), and neither discloses an
 * amount — "this is overdue" is a fact about a date, not about a sum. They are
 * the two questions the office asks a billing list, so withholding them along
 * with the money would leave a page that cannot be triaged at all.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    use MasksBillingAmounts;

    /**
     * What this actor may do to this record, when a caller has computed it.
     *
     * **Set fluently, never through the constructor.** `Resource::collection()`
     * maps with `mapInto()`, which calls `new Resource($item, $key)` — a second
     * constructor parameter silently receives the collection index instead, and
     * the TypeError only appears on a list endpoint.
     *
     * @var array<string, bool>|null
     */
    private ?array $capabilities = null;

    /**
     * @param  array<string, bool>  $capabilities
     */
    public function withCapabilities(array $capabilities): static
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'currency' => $this->currency,

            'due_date' => $this->due_date?->toDateString(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,

            // Facts about dates and state, not about sums — so they are not
            // masked. See the class docblock.
            'is_overdue' => $this->isOverdue(),
            'is_settled' => $this->isSettled(),

            // Tells the interface to render a deliberate placeholder rather
            // than inferring one from a missing key.
            'amounts_visible' => $this->amountsVisible($request),

            ...$this->withAmounts($request, [
                'subtotal_amount' => $this->subtotal_amount,
                'total_amount' => $this->total_amount,
                'paid_amount' => $this->paidAmount(),
                'outstanding_amount' => $this->outstandingAmount(),
            ]),

            'client_party' => $this->whenLoaded('clientParty', fn (): ?array => $this->clientParty === null ? null : [
                'id' => $this->clientParty->id,
                'display_name' => $this->clientParty->display_name,
            ]),

            'project' => $this->whenLoaded('project', fn (): ?array => $this->project === null ? null : [
                'id' => $this->project->id,
                'reference' => $this->project->project_number,
                'title' => $this->project->title,
            ]),

            'matter' => $this->whenLoaded('matter', fn (): ?array => $this->matter === null ? null : [
                'id' => $this->matter->id,
                'reference' => $this->matter->matter_number,
                'title' => $this->matter->title,
            ]),

            'quotation' => $this->whenLoaded('quotation', fn (): ?array => $this->quotation === null ? null : [
                'id' => $this->quotation->id,
                'reference' => $this->quotation->quotation_number,
            ]),

            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),

            'items_count' => $this->whenCounted('items'),
            'payments_count' => $this->whenCounted('payments'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...($this->capabilities === null ? [] : ['capabilities' => $this->capabilities]),
        ];
    }
}
