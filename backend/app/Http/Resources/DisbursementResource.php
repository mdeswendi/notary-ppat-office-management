<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\MasksBillingAmounts;
use App\Models\Disbursement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cost the office carried for a client (M8.2, D-124, D-125).
 *
 * No `status` key, because the table has no such column: `disbursements.*` has no
 * lifecycle verb, so there is no state to report. See the migration for why
 * adding one would be vocabulary nothing could reach.
 *
 * @mixin Disbursement
 */
class DisbursementResource extends JsonResource
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
            'description' => $this->description,
            'currency' => $this->currency,
            'incurred_on' => $this->incurred_on?->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,

            'amounts_visible' => $this->amountsVisible($request),

            ...$this->withAmounts($request, [
                'amount' => $this->amount,
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

            // Records that the cost is meant to be re-billed there. Nothing is
            // copied onto the invoice — see CreateDisbursement.
            'invoice' => $this->whenLoaded('invoice', fn (): ?array => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'reference' => $this->invoice->invoice_number,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...($this->capabilities === null ? [] : ['capabilities' => $this->capabilities]),
        ];
    }
}
