<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Billing\BillingVisibility;
use App\Models\ClientReceipt;
use App\Models\User;

/** Office-scoped access to actual client receipts (D-139). */
class ClientReceiptPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly BillingVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope($this->resolver->resolve($actor, 'client_receipts.view'));
    }

    public function view(User $actor, ClientReceipt $receipt): bool
    {
        return $this->reaches($actor, 'client_receipts.view', $receipt);
    }

    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'client_receipts.create'),
            $officeId,
        );
    }

    public function update(User $actor, ClientReceipt $receipt): bool
    {
        return $this->reaches($actor, 'client_receipts.update', $receipt);
    }

    private function reaches(User $actor, string $permission, ClientReceipt $receipt): bool
    {
        return $this->visibility->permits($actor, $this->resolver->resolve($actor, $permission), $receipt);
    }
}
