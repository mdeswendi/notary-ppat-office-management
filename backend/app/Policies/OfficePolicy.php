<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function __construct(private readonly EffectiveAccessResolver $resolver) {}

    public function view(User $actor, Office $office): bool
    {
        return $this->reaches($actor, 'offices.view', $office);
    }

    public function update(User $actor, Office $office): bool
    {
        return $this->reaches($actor, 'offices.update', $office);
    }

    private function reaches(User $actor, string $permission, Office $office): bool
    {
        $access = $this->resolver->resolve($actor, $permission);

        if (! $access->granted) {
            return false;
        }

        return in_array(DataScope::ALL, $access->scopes, true)
            || (in_array(DataScope::OFFICE, $access->scopes, true) && $office->is($actor->office));
    }
}
