<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Activity\Enums\ActivityType;
use App\Domains\Audit\Services\EventRecorder;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Exceptions\BillingStatusNotEligible;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Record that money arrived (M8.2, D-124, O-050).
 *
 * **A recorded payment counts toward nothing until it is verified.** That is the
 * whole of the correction story on this surface: the catalogue gives payments no
 * update, no delete and no reject, so the gap between recording and verifying is
 * the only place a mistake can be caught. A wrong `PENDING` payment stays visible
 * and moves no figure anywhere.
 *
 * **Nothing on the invoice changes here.** No status, no stored total — an
 * invoice's paid amount is computed from its verified payments
 * ({@see Invoice::scopeWithSettlement()}), so there is no denormalised copy to
 * keep in step and no invoice row to write. Recording a payment is also not an
 * act any `invoices.*` verb authorizes, so writing to the invoice would be a
 * change no capability permits.
 *
 * **The amount is not checked against what is outstanding.** An office may be
 * overpaid, may be paid for two invoices at once, or may be recording a deposit;
 * refusing any of those would lose a fact rather than prevent one. What the
 * invoice then shows is `outstanding_amount` floored at zero.
 */
class RecordPayment
{
    public function __construct(private readonly EventRecorder $events) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Invoice $invoice, array $attributes): Payment
    {
        return DB::transaction(function () use ($actor, $invoice, $attributes): Payment {
            // **A cancelled invoice takes no payments.** It asks for nothing, so
            // money recorded against it would settle a debt that does not exist.
            // A draft does take them: an office is sometimes paid before it
            // bills, and refusing that would lose a fact rather than prevent one.
            //
            // Refused here rather than in the Policy, so the answer is 422 — a
            // problem with the record's state, not with the actor's authority.
            if (! $invoice->status->isCancellable()) {
                throw BillingStatusNotEligible::for('invoice', $invoice->status->value, 'paid');
            }

            $payment = new Payment;

            // The constraint carrier, written from the invoice. Never from the
            // request — one source means the composite key cannot disagree.
            $payment->office_id = $invoice->office_id;
            $payment->invoice_id = $invoice->getKey();

            $payment->status = PaymentStatus::PENDING;

            // Not fillable, because the model refuses to change any of them
            // later: a payment has no correction path, so the values it is born
            // with are the values it keeps.
            $payment->amount = $attributes['amount'];
            $payment->currency = $attributes['currency'] ?? $invoice->currency;
            $payment->method_code = $attributes['method_code'];
            $payment->paid_at = $attributes['paid_at'];

            $payment->created_by = $actor->getKey();

            $payment->fill(array_intersect_key($attributes, array_flip(['reference', 'notes'])));
            $payment->save();

            // No amount in the metadata (D-125): the feed consults no billing
            // capability, so an amount here would disclose what the masking rule
            // withholds.
            $this->events->created($payment, $actor, ActivityType::PAYMENT_RECORDED, [
                'reference' => $invoice->invoice_number,
            ]);

            return $payment;
        });
    }
}
