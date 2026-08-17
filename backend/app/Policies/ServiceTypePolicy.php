<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\MasterData\ServiceTypeVisibility;
use App\Models\ServiceType;
use App\Models\User;

/**
 * Who may read and maintain an Office's Service Type catalogue (M4.1).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * **Two capabilities, and neither implies the other.** The canonical registry
 * defines exactly `master.services.view` and `master.services.manage`, so this
 * class honours exactly those two. An actor holding only `manage` cannot read the
 * catalogue, and an actor holding only `view` cannot change it. That the first
 * half feels wrong is the same deliberate answer M3.4 gave for participation
 * (D-098): a silently implied capability is one nobody configured and nobody can
 * revoke.
 *
 * **Data Scope is `OFFICE` or `ALL`, and the other three fail closed** — see
 * {@see ServiceTypeVisibility}. This is the Party answer (D-080), not the Project
 * one: a Service Type is a shared reference record with no `created_by` for `OWN`
 * to mean and no assignee for `ASSIGNED` to match.
 *
 * **`create` is the ability worth reading carefully.** A Service Type is always
 * created into the actor's own Office, so `ALL` buys nothing here. It grants
 * reach over records that already exist, never the right to decide which Office a
 * new one belongs to — the line D-098 drew for participation, restated because
 * the temptation is identical.
 *
 * **M4.1 exposes no HTTP endpoint.** These abilities exist and are tested
 * directly so that whichever milestone builds the master-data surface has only to
 * call them — the same way M2.1 prepared Party and M3.1 prepared Project. No
 * route, controller, request, or resource accompanies them.
 */
class ServiceTypePolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ServiceTypeVisibility $visibility,
    ) {}

    /**
     * May the actor open the catalogue at all?
     *
     * A grant carrying only `OWN`, `ASSIGNED`, or `TEAM` reaches no Service Type,
     * so it is refused outright rather than serving a reliably empty catalogue.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'master.services.view')
        );
    }

    public function view(User $actor, ServiceType $serviceType): bool
    {
        return $this->reaches($actor, 'master.services.view', $serviceType);
    }

    /**
     * May the actor create a Service Type?
     *
     * `$officeId` is only meaningful to a caller that names a destination. M4.1
     * ships no such caller — there is no create surface — but the question is
     * answered in one place so a future one cannot invent a looser rule.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'master.services.manage'),
            $officeId,
        );
    }

    public function update(User $actor, ServiceType $serviceType): bool
    {
        return $this->reaches($actor, 'master.services.manage', $serviceType);
    }

    /**
     * May the actor retire or reinstate a Service Type?
     *
     * The same capability as {@see update()} — the registry defines no separate
     * code, and inventing one is out of M4.1's scope. It is a **separate ability**
     * rather than a synonym because activation is a different act from correcting
     * a description: it withdraws the service from every future selection. When a
     * write surface exists it gets its own endpoint on the D-091 pattern, and this
     * ability is what that endpoint will authorize against.
     *
     * Retirement never deletes and never hides history: a record already
     * referencing this Service Type keeps its reference and stays readable.
     */
    public function setActivation(User $actor, ServiceType $serviceType): bool
    {
        return $this->reaches($actor, 'master.services.manage', $serviceType);
    }

    private function reaches(User $actor, string $permission, ServiceType $serviceType): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $serviceType,
        );
    }
}
