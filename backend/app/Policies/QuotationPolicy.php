<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\Quotation;
use App\Models\User;

/**
 * Who may work with Quotations (M8.2, D-124).
 *
 * Four abilities for four canonical codes — `quotations.view`, `.create`,
 * `.update`, `.approve` — and **no fifth**. The M8.2 brief asked for `send`,
 * `reject`, `convert` and `delete`; not one of those codes exists in the
 * catalogue, and the brief also forbade adding permissions, so its own constraint
 * rules them out. There is no ability here for an act nothing authorizes, and no
 * route reaches one.
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
     * May the actor correct this Quotation, or its lines?
     *
     * **`DRAFT` only.** Approving is the finalization act: the figures have been
     * agreed with a client, so `CLAUDE.md` section 64's discipline applies and
     * the row displays read-only from then on.
     *
     * This governs the line items too — editing what a quotation offers is
     * editing the quotation.
     */
    public function update(User $actor, Quotation $quotation): bool
    {
        return $quotation->status->isEditable()
            && $this->reaches($actor, 'quotations.update', $quotation);
    }

    /**
     * May the actor approve this Quotation?
     *
     * Its own capability, never implied by `update` (D-091).
     *
     * **No status check here, unlike `update`.** The M6 and M7 policies draw the
     * same line: a lifecycle act asks only whether the actor holds the
     * capability, and the Action refuses an ineligible state with **422**.
     * Approving an already-approved quotation is a problem with the record's
     * state, not with the actor's authority, and answering 403 would tell them
     * they may not do this when they may.
     *
     * `update` gates on state because "this row is frozen" makes editing
     * meaningless and `can_update` has to say so honestly.
     */
    public function approve(User $actor, Quotation $quotation): bool
    {
        return $this->reaches($actor, 'quotations.approve', $quotation);
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
