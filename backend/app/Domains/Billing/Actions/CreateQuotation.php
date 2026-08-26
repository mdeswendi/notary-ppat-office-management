<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\AllocateBillingReference;
use App\Domains\Billing\BillingReference;
use App\Domains\Billing\Enums\QuotationStatus;
use App\Models\Matter;
use App\Models\Party;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raise a priced offer (M8.2, D-124).
 *
 * **The reference is allocated inside the transaction**, so a quotation and its
 * number commit together. An allocation that survived a rolled-back insert would
 * leave a permanent gap in the office's sequence — harmless for an internal
 * reference, but confusing enough that D-103 put the allocation here rather than
 * before the write.
 *
 * **Every parent is optional and every one is pre-authorized.** `$party`,
 * `$project` and `$matter` arrive already resolved through their own domain's
 * visibility by the caller — this class never re-reads an id from the request,
 * which is the rule M2 set for participation and every milestone since has kept.
 */
class CreateQuotation
{
    public function __construct(
        private readonly AllocateBillingReference $allocator,
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
    ): Quotation {
        return DB::transaction(function () use ($actor, $attributes, $party, $project, $matter): Quotation {
            $quotation = new Quotation;

            // None of these is fillable, by design: assigning them explicitly
            // means a reader sees every system-controlled field in one place.
            $quotation->office_id = $actor->office_id;
            $quotation->quotation_number = $this->allocator->forOffice(
                BillingReference::QUOTATION,
                $actor->office_id,
            );
            $quotation->status = QuotationStatus::DRAFT;
            $quotation->subtotal_amount = '0.00';
            $quotation->total_amount = '0.00';

            $quotation->client_party_id = $party?->getKey();
            $quotation->project_id = $project?->getKey();
            $quotation->matter_id = $matter?->getKey();

            // Attribution must survive the person who typed it (D-050).
            $quotation->created_by = $actor->getKey();
            $quotation->updated_by = $actor->getKey();

            $quotation->fill($attributes);
            $quotation->save();

            $this->events->created($quotation, $actor, ActivityType::QUOTATION_CREATED, [
                'reference' => $quotation->quotation_number,
                'title' => $quotation->title,
            ]);

            return $quotation;
        });
    }
}
