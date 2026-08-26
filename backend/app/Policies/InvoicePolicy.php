<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\Invoice;
use App\Models\User;

/**
 * Who may work with Invoices (M8.2, D-124).
 *
 * Five abilities for five canonical codes — `invoices.view`, `.create`,
 * `.update`, `.issue`, `.cancel`. **There is no `delete` ability and no route
 * for one**: `invoices.delete` does not exist in the catalogue, so a draft
 * raised in error is cancelled rather than removed (O-051).
 *
 * There is no `send` either. The catalogue's verb is `issue`, and issuing *is*
 * sending: an invoice that has been issued has gone to a client.
 */
class InvoicePolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'invoices.view')
        );
    }

    public function view(User $actor, Invoice $invoice): bool
    {
        return $this->reaches($actor, 'invoices.view', $invoice);
    }

    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'invoices.create'),
            $officeId,
        );
    }

    /**
     * May the actor correct this Invoice, or its lines?
     *
     * **`DRAFT` only** — the ruling D-124 section 9.2 makes explicit. Issuing is
     * the finalization act: the invoice has been sent to a client, so
     * `CLAUDE.md` section 64 applies, its values are preserved, and the only
     * remaining act is `cancel`.
     *
     * The same answer governs the line items. Editing what an issued invoice
     * charges for is editing the invoice, whatever route it arrives on.
     */
    public function update(User $actor, Invoice $invoice): bool
    {
        return $invoice->status->isEditable()
            && $this->reaches($actor, 'invoices.update', $invoice);
    }

    /**
     * May the actor issue this Invoice to the client?
     *
     * Its own capability, never implied by `update` (D-091).
     */
    public function issue(User $actor, Invoice $invoice): bool
    {
        return $this->reaches($actor, 'invoices.issue', $invoice);
    }

    /**
     * May the actor cancel this Invoice?
     *
     * **Available before and after issue.** A draft raised in error is cancelled
     * because nothing may delete it; an issued invoice that should not have gone
     * out is cancelled because nothing may edit it. Cancelling twice is refused.
     */
    public function cancel(User $actor, Invoice $invoice): bool
    {
        return $this->reaches($actor, 'invoices.cancel', $invoice);
    }

    private function reaches(User $actor, string $permission, Invoice $invoice): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $invoice,
        );
    }
}
