<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Authorization\Enums\DataScope;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Notary\NotaryDeedVisibility;
use App\Models\Matter;
use App\Models\NotaryDeed;
use App\Models\User;

/**
 * Who may work with Notarial Deeds (M6.1, D-120).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's rules
 * apply unchanged: canonical permissions only, a role grant with no Data Scope
 * grants nothing, an active DENY override wins, expired overrides are ignored, and
 * Spatie's direct user-permission grants never participate. **No role name is read
 * anywhere, and `SUPER_ADMIN` receives no bypass.**
 *
 * That last sentence is load-bearing for this class specifically. The M6 brief
 * specified that approval and finalization be *"default hanya PRINCIPAL dan
 * SUPER_ADMIN"*. Which roles hold `notary.deeds.approve` is **office configuration**;
 * expressing it as a role-name check in code is the mechanism D-032, D-041 and D-048
 * forbid outright — and would remain forbidden even after a domain source answers
 * *"which stages require Principal approval?"*
 *
 * ## Seven capabilities, and none implies another
 *
 * ```text
 * notary.deeds.view      viewAny, view
 * notary.deeds.create    create
 * notary.deeds.update    update
 * notary.deeds.review    review
 * notary.deeds.approve   approve
 * notary.deeds.finalize  finalize
 * notary.deeds.number    recordNumber
 * ```
 *
 * `update` does not reach `review`; `review` does not reach `approve`; `approve`
 * does not reach `finalize`; `finalize` does not reach `number`. The D-091
 * discipline, and here it is load-bearing rather than stylistic: an office that
 * separates preparing a deed from approving it is expressing something about who may
 * bind it legally.
 *
 * **`notary.deeds.number` is honoured as the separate capability the catalogue
 * already defined.** Folding numbering into finalization would assert that a deed is
 * numbered when it is finalized, which is half of *"who assigns the number, and
 * when?"* — open question one in `08_NOTARY_WORKFLOW.md` section 6.
 *
 * ## What this class does not contain
 *
 * There is **no `delete`, no `lock`, and no `void`**, because there is no
 * `notary.deeds.delete`, `notary.deeds.lock` or `notary.deeds.void` in the canonical
 * catalogue — verified against the registry at M6.0 — and no documented rule
 * describing any of the three acts. `CLAUDE.md` section 29 requires that correction
 * mechanisms *"follow documented business rules"*; none exist.
 *
 * ## What is decided here and what is decided in the Actions
 *
 * This class answers *who*. Whether the deed's current status permits the act is the
 * Action's, and it answers 422 rather than 403 — the caller is authorized and would
 * succeed on a deed in a different state.
 *
 * The one exception is `update`, where the read-only rule is *also* reflected here,
 * so no interface offers an edit control on a finalized deed. `CLAUDE.md` sections 29
 * and 64 make that a property of the record rather than of the request.
 */
class NotaryDeedPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly NotaryDeedVisibility $visibility,
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
            $this->resolver->resolve($actor, 'notary.deeds.view')
        );
    }

    public function view(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.deeds.view', $deed);
    }

    /**
     * May the actor record a deed against this Matter?
     *
     * **Three conditions, and the second is the one worth naming.**
     *
     * 1. `notary.deeds.create` at a scope that could describe the deed about to
     *    exist — which is every applicable scope, because a new deed inherits the
     *    Matter's Office and resolves `OWN` and `ASSIGNED` through the same Matter
     *    it is being attached to. *(Contrast Matter creation, where `ASSIGNED` is
     *    excluded because a new Matter has no PIC yet, D-107. A new deed's parent
     *    already has whatever PIC it has.)*
     *
     * 2. **The Matter must be reachable through `notary.matters.view`.**
     *    `notary.deeds.create` is authority to record a deed; it is never authority
     *    to discover which Matters exist. This is D-118's two-question rule for
     *    attaching a document, applied to the same shape of problem.
     *
     * 3. The Matter must be a **NOTARY** Matter. A PPAT Matter addressed through a
     *    Notary capability is refused rather than silently accepted — the route
     *    decides the namespace (D-101), and the record must agree with it.
     */
    public function create(User $actor, Matter $matter): bool
    {
        if ($matter->domain !== MatterDomain::NOTARY) {
            return false;
        }

        $access = $this->resolver->resolve($actor, 'notary.deeds.create');

        if (! $this->visibility->hasUsableScope($access)) {
            return false;
        }

        // The Office of the deed about to exist is the Matter's, so an actor whose
        // only usable scope is OFFICE must be in that Office.
        if ($matter->office_id !== $actor->office_id && ! $access->hasScope(DataScope::ALL)) {
            return false;
        }

        return $this->matterVisibility->permits(
            $actor,
            $this->resolver->resolve($actor, 'notary.matters.view'),
            $matter,
        );
    }

    /**
     * May the actor edit this deed's own fields?
     *
     * **A finalized deed is read-only**, and that is checked here rather than only in
     * the Action, so no interface offers a control that cannot work. `CLAUDE.md`
     * section 29: *"Once finalized/locked: normal update = denied."*
     */
    public function update(User $actor, NotaryDeed $deed): bool
    {
        if ($deed->isReadOnly()) {
            return false;
        }

        return $this->reaches($actor, 'notary.deeds.update', $deed);
    }

    public function review(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.deeds.review', $deed);
    }

    public function approve(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.deeds.approve', $deed);
    }

    public function finalize(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.deeds.finalize', $deed);
    }

    /**
     * May the actor record this deed's legal number?
     *
     * Its own canonical capability, which the catalogue defined before anything in
     * this repository implemented it. The *format* of the number and *when* it is
     * assigned are the office's, not the software's — see the `notary_deeds`
     * migration.
     */
    public function recordNumber(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.deeds.number', $deed);
    }

    /**
     * Minuta Akta (M6.3).
     *
     * **Three abilities, and all three take the Deed rather than the Minuta.** A
     * Minuta has no owner, no assignee and no Office of its own choosing: it is the
     * original record of exactly one deed, it lives at a nested address under that
     * deed, and it is reached exactly as the deed is. Taking the parent is therefore
     * honest rather than a shortcut — there is no Minuta-shaped predicate to
     * evaluate, and inventing one would be a second reach mechanism.
     *
     * What is *not* inherited is the capability. `notary.minuta.*` is its own family
     * of codes: an actor who may read a deed does not thereby read where its original
     * is filed, and an actor who may edit a deed does not thereby re-file it. That is
     * the D-091 discipline, one level down from where {@see update()} applies it.
     *
     * **There is no `deleteMinuta`, and it is not an omission.** The canonical
     * catalogue defines `notary.minuta.view`, `create`, `update`, `archive` and
     * `release` — verified against the live registry — and **no `delete`**. The M6.3
     * brief asked for a soft delete restricted to `DRAFT`, which would need both a
     * column the ERD omits and a code the catalogue does not have. A Minuta filed
     * against the wrong deed is corrected by replacing `document_id`; removing the
     * record itself is a correction mechanism, and those are an open domain question
     * (D-120).
     *
     * **There is no `archiveMinuta` or `releaseMinuta` either.** Both codes exist and
     * both stay unimplemented: *"What triggers Minuta Akta archiving, and what
     * release conditions apply?"* is open question four in `08_NOTARY_WORKFLOW.md`
     * section 6. Registering a permission is not shipping a feature (D-064), and the
     * reverse holds — shipping one before its rule is written would be inventing the
     * rule.
     */
    public function viewMinuta(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.minuta.view', $deed);
    }

    public function createMinuta(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.minuta.create', $deed);
    }

    public function updateMinuta(User $actor, NotaryDeed $deed): bool
    {
        return $this->reaches($actor, 'notary.minuta.update', $deed);
    }

    private function reaches(User $actor, string $permission, NotaryDeed $deed): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $deed,
        );
    }
}
