<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Models\Matter;
use App\Models\Project;
use App\Models\User;

/**
 * Who may work with Matter records (M4.2, D-100, D-101, D-107).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * ## The domain context, which is the design decision this class exists to carry
 *
 * There is **one Matter Policy, not two**, and it takes the domain as an
 * **explicit argument supplied by the caller**:
 *
 * ```php
 * $this->authorize('view', [$matter, MatterDomain::NOTARY]);   // from the route
 * ```
 *
 * The domain decides the permission namespace — `notary.matters.*` or
 * `ppat.matters.*` — and **it never comes from `$matter->domain`** (D-101).
 * That is the rule worth stating plainly, because reading the row would be the
 * obvious shortcut and it is exactly what the M3 lock flagged as "a genuinely new
 * authorization shape": a Policy choosing which permission to resolve from the
 * record it is being asked about. Route-derived namespacing keeps the question
 * ordinary — each caller knows its capability before touching the database.
 *
 * A **second, separate rule keeps the row honest**: the supplied domain must
 * equal the persisted `domain`, or every ability refuses. At M4.4 the
 * domain-specific route binding turns that mismatch into the canonical **404**
 * (D-101, the D-098 nested-binding convention); here it is a Policy-level refusal
 * so the invariant exists before the route does. The two mechanisms answer
 * different questions — *which capability governs this call* and *does this record
 * belong to the surface that was addressed* — and collapsing them would reinstate
 * the row-derived namespace by the back door.
 *
 * M4.2 ships no route, so the tests pass the domain explicitly, which is exactly
 * what M4.4's routes will do from the path segment.
 *
 * ## Independence
 *
 * The registry defines seven actionable capabilities per domain, and **none
 * implies another**. `update` does not reach assignment; `assign` does not reach
 * ordinary update; `change_stage` does not imply `complete`; `complete` does not
 * imply `cancel`. There is no umbrella `manage` code and none may be invented —
 * the discipline D-091 applies to Project and D-098 to participation.
 *
 * **`view_all` appears nowhere in this class, deliberately** (D-090). Both
 * `notary.matters.view_all` and `ppat.matters.view_all` are registered for
 * compatibility and are superseded by Data Scope `ALL` for reach. Consulting
 * either here would be the second reach mechanism the decision forbids, and two
 * answers to one question do not stay equal — the looser one wins by accident.
 *
 * ## Archive, restore, delete
 *
 * There are none, and there is no code that could authorize them (D-102).
 * `matters.deleted_at` is reserved schema capability with no lifecycle.
 *
 * M4.2 exposes no HTTP endpoint. These abilities exist and are tested directly so
 * that M4.4 has only to call them — the way M2.1 prepared Party, M3.1 prepared
 * Project, and M4.1 prepared Service Type.
 */
class MatterPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly MatterVisibility $visibility,
        private readonly ProjectVisibility $projects,
    ) {}

    /**
     * May the actor open the Matter list for this domain?
     *
     * A grant carrying only `TEAM` reaches nothing, so it is refused outright
     * rather than serving a reliably empty page.
     */
    public function viewAny(User $actor, MatterDomain $domain): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, $domain->permission('view'))
        );
    }

    public function view(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'view', $matter, $domain);
    }

    /**
     * May the actor create a Matter of this domain under this Project?
     *
     * Four conditions, and each is load-bearing:
     *
     * 1. **the domain's own create capability**, at a scope that can describe a
     *    record about to exist — `OWN`, `OFFICE`, or `ALL`. `ASSIGNED` alone
     *    cannot, because a new Matter has no PIC (D-107);
     * 2. **the parent Project must be reachable under `projects.view`**, through
     *    ordinary Project authorization. Reading a Project is the minimum
     *    coherent proof that somebody may open work beneath it; requiring
     *    `projects.update` would demand the right to edit a Project in order to
     *    add work to it, and inventing a new code would change the canonical
     *    count. This is the **one** place Matter authorization consults the
     *    parent — D-100 keeps them independent everywhere else;
     * 3. **the Project must be in the actor's own Office**, and this is the
     *    condition an `ALL` grant does not satisfy. `ALL` is cross-office reach
     *    over *existing* Matters; it is not authority to file new work into
     *    another Office. D-097 reached the same conclusion for Project creation,
     *    and the reasoning is unchanged;
     * 4. **the Project must not be archived.** `ProjectVisibility::permits()`
     *    excludes soft-deleted Projects by default, so this falls out of using
     *    the canonical reach check rather than a separate lookup — a
     *    soft-deleted Project is not a place to be opening new work.
     *
     * The resulting Matter's Office is **inherited from the Project** (D-099) and
     * is never caller-selected. Because condition 3 pins the Project to the
     * actor's Office, the inherited Office is the actor's own.
     */
    public function create(User $actor, MatterDomain $domain, Project $project): bool
    {
        $access = $this->resolver->resolve($actor, $domain->permission('create'));

        if (! $this->visibility->permitsCreation($access)) {
            return false;
        }

        // Cross-office creation is refused even for `ALL` (D-097's ruling, one
        // domain across). Checked before the Project reach question because it is
        // the cheaper and more absolute of the two.
        if ($project->office_id !== $actor->office_id) {
            return false;
        }

        // The parent must be canonically reachable, by the ordinary Project
        // mechanism and no other. Archived Projects are excluded here.
        return $this->projects->permits(
            $actor,
            $this->resolver->resolve($actor, 'projects.view'),
            $project,
        );
    }

    public function update(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'update', $matter, $domain);
    }

    /**
     * Change who is in charge. Never reached by ordinary update (D-091's shape).
     *
     * **Same-Office PIC is a locked rule enforced at M4.4**, where the assignment
     * surface lives: `ASSIGNED` grants reach when `pic_user_id == actor.id`, so a
     * cross-office assignment would hand somebody reach over a Matter their own
     * scope never included — a privilege grant performed through a work
     * allocation, with nothing in the authorization surfaces to show for it
     * (D-097). This ability answers *who may assign*, not *who may be assigned*.
     */
    public function assign(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'assign', $matter, $domain);
    }

    /**
     * Move the Matter's workflow stage.
     *
     * The capability exists in the canonical registry today; the workflow it
     * would move belongs to M4.7. Authorizing *who* may change a stage is
     * separate from encoding *which* stage may follow which — no transition
     * matrix exists, and none may be invented (D-104).
     */
    public function changeStage(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'change_stage', $matter, $domain);
    }

    public function complete(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'complete', $matter, $domain);
    }

    public function cancel(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'cancel', $matter, $domain);
    }

    /**
     * The shared shape: the record must belong to the addressed domain, and the
     * actor must reach it under that domain's own capability.
     *
     * The domain check comes first and is exact. A Notary grant must not
     * authorize a PPAT Matter no matter how wide its Data Scope, and the
     * comparison is against the **supplied** domain rather than the persisted one
     * being trusted to pick the permission.
     */
    private function reaches(User $actor, string $capability, Matter $matter, MatterDomain $domain): bool
    {
        if ($matter->domain !== $domain) {
            return false;
        }

        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $domain->permission($capability)),
            $matter,
        );
    }
}
