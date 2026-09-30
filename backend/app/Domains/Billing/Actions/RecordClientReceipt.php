<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Models\ClientReceipt;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Record one actual client payment, independent of quotations and invoices. */
class RecordClientReceipt
{
    public function __construct(private readonly EventRecorder $events) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, Party $client, array $attributes): ClientReceipt
    {
        return DB::transaction(function () use ($actor, $client, $attributes): ClientReceipt {
            $receipt = new ClientReceipt;
            $receipt->office_id = $actor->office_id;
            $receipt->client_party_id = $client->getKey();
            $receipt->created_by = $actor->getKey();
            $receipt->fill($attributes);
            $receipt->save();

            $this->events->created($receipt, $actor, ActivityType::CLIENT_RECEIPT_RECORDED, [
                'title' => $client->display_name,
            ]);

            return $receipt;
        });
    }
}
