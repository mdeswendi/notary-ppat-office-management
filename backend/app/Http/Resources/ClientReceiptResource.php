<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\ClientReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientReceipt */
class ClientReceiptResource extends JsonResource
{
    use MasksBillingAmounts;

    private ?array $capabilities = null;

    /** @param array<string, bool> $capabilities */
    public function withCapabilities(array $capabilities): static
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'received_on' => $this->received_on?->toDateString(),
            'method_code' => $this->method_code->value,
            'currency' => $this->currency,
            'amounts_visible' => $this->amountsVisible($request),
            ...$this->withAmounts($request, ['amount' => $this->amount]),
            'client_party' => $this->whenLoaded('clientParty', fn (): ?array => $this->clientParty === null ? null : [
                'id' => $this->clientParty->id,
                'display_name' => $this->clientParty->display_name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy === null ? null : [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'capabilities' => $this->capabilities ?? [
                'can_update' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
