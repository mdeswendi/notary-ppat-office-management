<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Audit\Services\EventRecorder;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct a recorded cost (M8.2, D-124).
 *
 * **No status gate**, unlike Quotation and Invoice. There is no state in which a
 * disbursement becomes read-only, because `disbursements.*` has no lifecycle verb
 * that would put it in one — which is also why the table has no `status` column.
 *
 * The amount **is** editable here, unlike a payment's. The difference is who
 * else has seen it: a payment is a claim about money that arrived from outside
 * the office and the catalogue deliberately gives it no correction path (O-050),
 * where a disbursement is the office's own note about its own spending.
 *
 * Audited with no activity row — the D-128 rule for field corrections.
 */
class UpdateDisbursement
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Disbursement $disbursement, array $attributes): Disbursement
    {
        return DB::transaction(function () use ($actor, $disbursement, $attributes): Disbursement {
            $disbursement->fill($attributes);
            $disbursement->updated_by = $actor->getKey();
            $disbursement->save();

            $this->events->updated($disbursement, $actor);

            return $disbursement;
        });
    }
}
