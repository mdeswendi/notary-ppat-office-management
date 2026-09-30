<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\Quotation;
use App\Models\User;

/**
 * Who may work with Quotations (M8.2, D-124).
 *
 * Three abilities for the quotation record — `quotations.view`, `.create`,
 * `.update`. A quotation is an office price record, not a client-approval
 * workflow. Client acceptance happens outside the application.
 *
 * **`billing.amount.view` is not consulted here.** Masking money is a
 * serialization concern (D-125): it decides what a reachable record discloses,
 * never which records are reachable. Folding it into the Policy would hide whole
 * quotations from somebody entitled to know one exists.
 */
class QuotationPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    /**
     * May the actor open the Quotation list?
     *
     * A grant carrying only `OWN`, `ASSIGNED` or `TEAM` reaches nothing here, so
     * it is refused outright rather than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'quotations.view')
        );
    }

    public function view(User $actor, Quotation $quotation): bool
    {
        return $this->reaches($actor, 'quotations.view', $quotation);
    }

    /**
     * May the actor raise a Quotation in this Office?
     *
     * **Always their own Office**, even for an actor holding `ALL`.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'quotations.create'),
            $officeId,
        );
    }

    /**
     * May the actor correct this Quotation, or add/correct/remove its lines?
     *
     * This governs the line items too — editing what a quotation offers is
     * editing the quotation. Historical status values do not freeze the office's
     * internal price record; unexpected process costs may be added later.
     */
    public function update(User $actor, Quotation $quotation): bool
    {
        return $this->reaches($actor, 'quotations.update', $quotation);
    }

    private function reaches(User $actor, string $permission, Quotation $quotation): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $quotation,
        );
    }
}
