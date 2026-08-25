<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Ppat\PropertyVisibility;
use App\Models\Property;
use App\Models\User;

/**
 * Who may work with Properties (M7.1, D-121).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048). No role name is
 * read, and `SUPER_ADMIN` receives no bypass.
 *
 * ## Six capabilities, and the ownership pair is the interesting one
 *
 * ```text
 * properties.view              viewAny, view
 * properties.create            create
 * properties.update            update
 * properties.archive           archive
 * properties.ownership.view    viewOwnership
 * properties.ownership.update  updateOwnership
 * ```
 *
 * **Ownership is its own capability pair, and the catalogue drew that line before
 * anything here implemented it.** An actor who may read a Property does not thereby
 * read its chain of title, and an actor who may correct an address does not thereby
 * rewrite who owns the land. That is the D-091 discipline, and here it protects
 * something a land office would genuinely separate — a clerk maintaining addresses is
 * not the person who records a transfer.
 *
 * **There is no `properties.ownership.create`** — verified absent. Adding a link to
 * the chain is an `update` to the chain, which is what the two codes support.
 *
 * ## Two scopes, not four
 *
 * A Property is office-owned reference data, so `OWN` and `ASSIGNED` are withheld —
 * the Party (D-080) and Service Type (D-106) answer. See {@see PropertyVisibility}
 * for the full argument and for what must never be added to it.
 *
 * ## What this class does not contain
 *
 * **There is no `delete`.** `properties.delete` is absent from the canonical
 * catalogue; `properties.archive` is the retirement path the catalogue does define.
 * What archiving a land object *means* is undecided — `properties.status` has no
 * canonical vocabulary — so M7.1 authorizes the act and M7.3 decides what it does.
 */
class PropertyPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PropertyVisibility $visibility,
    ) {}

    /**
     * May the actor open the Property list?
     *
     * A grant carrying only `OWN`, `ASSIGNED` or `TEAM` reaches nothing here, so it is
     * refused outright rather than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'properties.view')
        );
    }

    public function view(User $actor, Property $property): bool
    {
        return $this->reaches($actor, 'properties.view', $property);
    }

    /**
     * May the actor record a Property in this Office?
     *
     * **Always their own Office**, even for an actor holding `ALL`: `ALL` is reach
     * over records that already exist, never authority to decide which Office a new
     * one belongs to (D-097, D-098, D-107, D-119).
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        return $this->visibility->permitsCreationIn(
            $actor,
            $this->resolver->resolve($actor, 'properties.create'),
            $officeId,
        );
    }

    public function update(User $actor, Property $property): bool
    {
        return $this->reaches($actor, 'properties.update', $property);
    }

    /**
     * May the actor retire this Property?
     *
     * The capability is canonical and authorized here; **what archiving does is M7.3
     * question**, because `properties.status` has no canonical vocabulary and
     * inventing `ACTIVE`/`ARCHIVED` would be inventing a lifecycle (D-121).
     */
    public function archive(User $actor, Property $property): bool
    {
        return $this->reaches($actor, 'properties.archive', $property);
    }

    /**
     * May the actor read the chain of title?
     *
     * **Its own capability.** Reading a Property does not read who owns it — the
     * catalogue separates the two, and so does this.
     */
    public function viewOwnership(User $actor, Property $property): bool
    {
        return $this->reaches($actor, 'properties.ownership.view', $property);
    }

    /**
     * May the actor record a transfer?
     *
     * Also its own capability. Correcting an address is `properties.update`; changing
     * who owns the land is this, and an office may reasonably grant one without the
     * other in either direction.
     */
    public function updateOwnership(User $actor, Property $property): bool
    {
        return $this->reaches($actor, 'properties.ownership.update', $property);
    }

    private function reaches(User $actor, string $permission, Property $property): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $property,
        );
    }
}
