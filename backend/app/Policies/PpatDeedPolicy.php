<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Models\Matter;
use App\Models\PpatDeed;
use App\Models\User;

/**
 * Who may work with PPAT Deeds (M7.1, D-121).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1 rules
 * apply unchanged: canonical permissions only, a role grant with no Data Scope grants
 * nothing, an active DENY override wins, expired overrides are ignored, and Spatie
 * direct user-permission grants never participate. **No role name is read anywhere,
 * and `SUPER_ADMIN` receives no bypass.**
 *
 * The structural twin of {@see NotaryDeedPolicy}. Where the two differ is noted; where
 * they agree, they agree because the same canonical rule governs both.
 *
 * ## Seven capabilities, and none implies another
 *
 * ```text
 * ppat.deeds.view      viewAny, view
 * ppat.deeds.create    create        + ppat.matters.view on the parent
 * ppat.deeds.update    update
 * ppat.deeds.review    review
 * ppat.deeds.approve   approve
 * ppat.deeds.finalize  finalize
 * ppat.deeds.number    recordNumber
 * ```
 *
 * `update` does not reach `review`; `review` does not reach `approve`; `approve` does
 * not reach `finalize`; `finalize` does not reach `number`. The D-091 discipline, and
 * here it is load-bearing rather than stylistic: an office that separates preparing a
 * deed from approving it is expressing who may bind it legally.
 *
 * **`ppat.deeds.number` is honoured as the separate capability the catalogue already
 * defined.** Folding numbering into finalization would assert that a deed is numbered
 * when it is finalized, which is half of *"what are the deed numbering rules, and who
 * assigns the number?"* — open question five in `09_PPAT_WORKFLOW.md` section 6.
 *
 * ## What this class does not contain
 *
 * There is **no `delete`, no `lock`, and no `void`**, because the canonical catalogue
 * has no `ppat.deeds.delete`, `ppat.deeds.lock` or `ppat.deeds.void` — verified
 * against the live registry at M7.0 — and no documented rule describing any of the
 * three acts. `CLAUDE.md` section 29 requires that correction mechanisms *"follow
 * documented business rules"*; none exist (O-039).
 *
 * **There is no Warkah ability here either.** `ppat.warkah.*` is its own family with
 * its own six codes, and M7.4 owns the surface. Reading a deed does not read its
 * supporting bundle, and the reverse holds too.
 *
 * ## What is decided here and what is decided in the Actions
 *
 * This class answers *who*. Whether the deed current status permits the act is the
 * Action question, and it answers 422 rather than 403 — the caller is authorized and
 * would succeed on a deed in a different state.
 *
 * The one exception is `update`, where the read-only rule is also reflected here, so
 * no interface offers an edit control on a finalized deed. `CLAUDE.md` sections 29 and
 * 64 make that a property of the record rather than of the request.
 */
class PpatDeedPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PpatDeedVisibility $visibility,
        private readonly MatterVisibility $matterVisibility,
    ) {}

    /**
     * May the actor open the Deed list?
     *
     * A grant carrying only `TEAM` reaches nothing, so it is refused outright rather
     * than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'ppat.deeds.view')
        );
    }

    public function view(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.deeds.view', $deed);
    }

    /**
     * May the actor record a deed against this Matter?
     *
     * Three conditions, and the second is the one worth naming.
     *
     * 1. `ppat.deeds.create` at a scope that could describe the deed about to exist.
     *
     * 2. **The Matter must be reachable through `ppat.matters.view`.**
     *    `ppat.deeds.create` is authority to record a deed; it is never authority to
     *    discover which Matters exist. The D-118 two-question rule.
     *
     * 3. The Matter must be a **PPAT** Matter. A Notary Matter addressed through a
     *    PPAT capability is refused rather than silently accepted — the route decides
     *    the namespace (D-101), and the record must agree with it.
     */
    public function create(User $actor, Matter $matter): bool
    {
        if ($matter->domain !== MatterDomain::PPAT) {
            return false;
        }

        $access = $this->resolver->resolve($actor, 'ppat.deeds.create');

        if (! $this->visibility->hasUsableScope($access)) {
            return false;
        }

        // The Office of the deed about to exist is the Matter, so an actor whose only
        // usable scope is OFFICE must be in that Office.
        if ($matter->office_id !== $actor->office_id && ! $access->hasScope(DataScope::ALL)) {
            return false;
        }

        return $this->matterVisibility->permits(
            $actor,
            $this->resolver->resolve($actor, 'ppat.matters.view'),
            $matter,
        );
    }

    /**
     * May the actor edit this deed own fields?
     *
     * **A finalized deed is read-only**, checked here rather than only in the Action,
     * so no interface offers a control that cannot work. `CLAUDE.md` section 29:
     * *"Once finalized/locked: normal update = denied."*
     */
    public function update(User $actor, PpatDeed $deed): bool
    {
        if ($deed->isReadOnly()) {
            return false;
        }

        return $this->reaches($actor, 'ppat.deeds.update', $deed);
    }

    public function review(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.deeds.review', $deed);
    }

    public function approve(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.deeds.approve', $deed);
    }

    public function finalize(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.deeds.finalize', $deed);
    }

    /**
     * May the actor record this deed legal number?
     *
     * Its own canonical capability. The *format* of the number and *when* it is
     * assigned are the office decisions, not the software — see the `ppat_deeds`
     * migration.
     */
    public function recordNumber(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.deeds.number', $deed);
    }

    private function reaches(User $actor, string $permission, PpatDeed $deed): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $deed,
        );
    }
}
