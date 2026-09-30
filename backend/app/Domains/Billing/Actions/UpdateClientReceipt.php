<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Models\ClientReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Correct a receipt with an audit trail; no delete or silent rewrite. */
class UpdateClientReceipt
{
    public function __construct(private readonly EventRecorder $events) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, ClientReceipt $receipt, array $attributes): ClientReceipt
    {
        return DB::transaction(function () use ($actor, $receipt, $attributes): ClientReceipt {
            $receipt->fill($attributes);
            $receipt->updated_by = $actor->getKey();
            $receipt->save();
            $this->events->updated($receipt, $actor);

            return $receipt;
        });
    }
}
