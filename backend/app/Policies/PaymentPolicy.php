<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

/**
 * Who may work with Payments (M8.2, D-124, O-050).
 *
 * Three abilities for three canonical codes — `payments.view`, `.create`,
 * `.verify`. **There is no `update`, no `delete` and no `reject`**, because the
 * catalogue defines none of them. This is the one billing surface with no
 * correction path at all, and M8.2 ships that gap rather than closing it with an
 * uncatalogued verb.
 *
 * ## `create` takes the parent Invoice
 *
 * A payment is always recorded against an invoice, so the authority question is
 * *"may this actor record money against this invoice"* — which is judged against
 * the invoice's own reach, not against a Payment row that does not exist yet.
 * The same shape `ProjectPartyPolicy` (D-098) and `PpatWarkahPolicy` use.
 *
 * **Reaching the invoice is not enough on its own.** `payments.create` is still
 * required: `invoices.view` never becomes authority to record money (D-091).
 */
class PaymentPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'payments.view')
        );
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, 'payments.view'),
            $payment,
        );
    }

    /**
     * May the actor record a payment against this Invoice?
     *
     * **A cancelled invoice takes no payments.** It asks for nothing, so money
     * recorded against it would settle a debt that does not exist. A draft does
     * take them: an office is sometimes paid before it bills, and refusing to
     * record that would lose a fact rather than prevent one.
     */
    public function create(User $actor, Invoice $invoice): bool
    {
        // Capability only. Whether the invoice's *state* accepts a payment — a
        // cancelled one does not — is the Action's 422, not this method's 403.
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, 'payments.create'),
            $invoice,
        );
    }

    /**
     * May the actor confirm that this payment really arrived?
     *
     * Its own capability, never implied by `create` (D-091) — and the separation
     * is the point: **only verified payments count toward an invoice's paid
     * total**, so the person who records money and the person who confirms it can
     * be required to differ by grant.
     *
     * Verifying twice is refused rather than silently re-stamping who confirmed
     * it and when.
     */
    public function verify(User $actor, Payment $payment): bool
    {
        // No status check: a lifecycle act asks only about capability, and
        // {@see \App\Domains\Billing\Actions\VerifyPayment} refuses an already
        // verified payment with 422 (the M6/M7 convention).
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, 'payments.verify'),
            $payment,
        );
    }
}
