<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Quotation as the API returns it (M8.2, D-124, D-125).
 *
 * Money is absent unless `billing.amount.view` is held — see
 * {@see MasksBillingAmounts} for why absent rather than hidden.
 *
 * **`invoices_count` ships and discloses nothing about value.** Whether an
 * agreed offer has been billed yet is the question the list is actually scanned
 * for, and it is a count of rows rather than a sum of money.
 *
 * @mixin Quotation
 */
class QuotationResource extends JsonResource
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
            'quotation_number' => $this->quotation_number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'currency' => $this->currency,

            'valid_until' => $this->valid_until?->toDateString(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,

            'amounts_visible' => $this->amountsVisible($request),

            ...$this->withAmounts($request, [
                'subtotal_amount' => $this->subtotal_amount,
                'total_amount' => $this->total_amount,
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

            'approved_by' => $this->whenLoaded('approvedBy', fn (): ?array => $this->approvedBy === null ? null : [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ]),

            'items' => QuotationItemResource::collection($this->whenLoaded('items')),

            'items_count' => $this->whenCounted('items'),
            'invoices_count' => $this->whenCounted('invoices'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...($this->capabilities === null ? [] : ['capabilities' => $this->capabilities]),
        ];
    }
}
