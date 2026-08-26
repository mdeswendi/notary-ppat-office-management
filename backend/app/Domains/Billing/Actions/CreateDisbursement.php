<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Models\Disbursement;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record money the office spent for a client (M8.2, D-124).
 *
 * **No reference is allocated.** Quotations and invoices are documents the office
 * sends out and later has to find by number; a disbursement is an internal note
 * that the office paid something. Giving it a sequence would be a third
 * allocator serving no question anybody asks.
 *
 * **Attaching it to an invoice copies nothing.** `invoice_id` records that a cost
 * is meant to be re-billed on that invoice; the amount does not appear on the
 * bill until somebody adds a line under `invoices.update`. An invoice total that
 * moved because a cost was recorded elsewhere would change a document nobody
 * edited — and after issue, nothing may change it at all.
 *
 * **This is not a tax record.** It does not know whether the money was a tax, a
 * fee or a courier charge, it computes no rate, and it gates no stage. That is
 * what keeps O-040 intact.
 */
class CreateDisbursement
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(
        User $actor,
        array $attributes,
        ?Party $party = null,
        ?Project $project = null,
        ?Matter $matter = null,
        ?Invoice $invoice = null,
    ): Disbursement {
        return DB::transaction(function () use (
            $actor, $attributes, $party, $project, $matter, $invoice
        ): Disbursement {
            $disbursement = new Disbursement;

            $disbursement->office_id = $actor->office_id;

            // Every parent arrives already resolved and authorized by the
            // caller; no id is read from the request here.
            $disbursement->client_party_id = $party?->getKey();
            $disbursement->project_id = $project?->getKey();
            $disbursement->matter_id = $matter?->getKey();
            $disbursement->invoice_id = $invoice?->getKey();

            $disbursement->created_by = $actor->getKey();
            $disbursement->updated_by = $actor->getKey();

            $disbursement->fill($attributes);
            $disbursement->save();

            // No amount in the metadata (D-125).
            $this->events->created($disbursement, $actor, ActivityType::DISBURSEMENT_RECORDED, [
                'title' => $disbursement->description,
            ]);

            return $disbursement;
        });
    }
}
