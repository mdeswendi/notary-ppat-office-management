<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Exceptions\BillingStatusNotEligible;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Confirm that money really arrived (M8.2, D-124, O-050).
 *
 * **The one-way door.** Before this, a mis-entered payment affects no figure and
 * can be left uncounted; after it, the payment counts toward the invoice's paid
 * total and **there is no way back** — `payments.update`, `.delete` and
 * `.reject` are all absent from the catalogue.
 *
 * M8.2 ships that gap rather than closing it with an uncatalogued verb, the same
 * disposition M7.3 gave one-way property archiving (O-045). What mitigates it is
 * exactly this act existing as a separate capability: an office that cares can
 * grant `payments.create` and `payments.verify` to different people, so the money
 * is counted only once somebody else has looked at it.
 *
 * **Nothing on the invoice is written.** Its paid total is computed from verified
 * payments, so verifying one changes what the invoice reports without changing
 * the invoice — which is correct, because no `invoices.*` verb authorizes a
 * change here.
 */
class VerifyPayment
{
    public function __construct(private readonly EventRecorder $events) {}

    public function handle(User $actor, Payment $payment): Payment
    {
        return DB::transaction(function () use ($actor, $payment): Payment {
            if (! $payment->status->isVerifiable()) {
                throw BillingStatusNotEligible::for('payment', $payment->status->value, 'verified');
            }

            $from = $payment->status->value;

            $payment->status = PaymentStatus::VERIFIED;
            $payment->verified_at = Date::now();
            $payment->verified_by = $actor->getKey();
            $payment->save();

            $this->events->statusChanged(
                $payment,
                $actor,
                $from,
                $payment->status->value,
                ActivityType::PAYMENT_VERIFIED,
                ['reference' => $payment->invoice?->invoice_number],
            );

            return $payment;
        });
    }
}
