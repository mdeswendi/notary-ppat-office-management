<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\Disbursement;
use App\Models\User;

/**
 * Who may work with Disbursements (M8.2, D-124).
 *
 * Three abilities for three canonical codes — `disbursements.view`, `.create`,
 * `.update`. **No `delete`**, because `disbursements.delete` does not exist
 * (O-051), and **no lifecycle ability**, because the catalogue gives the surface
 * no lifecycle verb — which is also why the table has no `status` column.
 *
 * **Update has no status gate**, unlike Quotation and Invoice. There is no state
 * in which a disbursement becomes read-only, because there is no act that would
 * put it in one. Correcting a recorded cost is an ordinary edit.
 */
class DisbursementPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'disbursements.view')
        );
    }

    public function view(User $actor, Disbursement $disbursement): bool
    {
        return $this->reaches($actor, 'disbursements.view', $disbursement);
    }

    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'disbursements.create'),
            $officeId,
        );
    }

    public function update(User $actor, Disbursement $disbursement): bool
    {
        return $this->reaches($actor, 'disbursements.update', $disbursement);
    }

    private function reaches(User $actor, string $permission, Disbursement $disbursement): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $disbursement,
        );
    }
}
