<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded payment (M8.2, D-124, D-125, O-050).
 *
 * The amount is masked; **the status is not**. Whether money has been verified is
 * an operational fact somebody may need without being entitled to the figure —
 * and it is the fact that decides whether the payment counts toward anything.
 *
 * **`can_verify` is reported per row**, because verifying is refused both by
 * capability and by state: a payment already verified cannot be verified again,
 * and there is no way back (O-050). The interface must not offer a button that
 * would either fail or be irreversible without saying so.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
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
            'invoice_id' => $this->invoice_id,
            'status' => $this->status->value,
            'method_code' => $this->method_code->value,
            'reference' => $this->reference,
            'currency' => $this->currency,
            'notes' => $this->notes,

            // When the office says the money moved, which is not `created_at`.
            'paid_at' => $this->paid_at?->toDateString(),
            'verified_at' => $this->verified_at?->toIso8601String(),

            'amounts_visible' => $this->amountsVisible($request),

            ...$this->withAmounts($request, [
                'amount' => $this->amount,
            ]),

            'verified_by' => $this->whenLoaded('verifiedBy', fn (): ?array => $this->verifiedBy === null ? null : [
                'id' => $this->verifiedBy->id,
                'name' => $this->verifiedBy->name,
            ]),

            'invoice' => $this->whenLoaded('invoice', fn (): ?array => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'reference' => $this->invoice->invoice_number,
                'title' => $this->invoice->title,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),

            ...($this->capabilities === null ? [] : ['capabilities' => $this->capabilities]),
        ];
    }
}
