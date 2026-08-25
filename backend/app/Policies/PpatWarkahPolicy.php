<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Ppat\Enums\PpatWarkahStatus;
use App\Domains\Ppat\PpatDeedVisibility;
use App\Models\PpatDeed;
use App\Models\User;

/**
 * Who may work with a Warkah — the supporting documents bound with a PPAT Deed
 * (M7.4, D-121).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048). No role name is
 * read and `SUPER_ADMIN` receives no bypass.
 *
 * ## Its own Policy, because it is its own capability family
 *
 * {@see PpatDeedPolicy} says so in its own words: *"There is no Warkah ability here
 * either. `ppat.warkah.*` is its own family with its own six codes, and M7.4 owns the
 * surface. Reading a deed does not read its supporting bundle, and the reverse holds
 * too."* This class is that surface, and the split is real — an office may let a clerk
 * assemble evidence without letting them read the deed, or the reverse.
 *
 * ## The subject is the Deed, and that is deliberate
 *
 * Every method takes a `PpatDeed` rather than a `PpatWarkah`, for two reasons that
 * both matter:
 *
 * **A Warkah's reach *is* its deed's reach.** The composite foreign key
 * `(ppat_deed_id, office_id)` makes a cross-deed or cross-Office bundle
 * unrepresentable, so there is no separate visibility question to answer — and
 * {@see PpatDeedVisibility} already resolves `OWN` and `ASSIGNED` through the parent
 * Matter, which a Warkah inherits unchanged.
 *
 * **The bundle may not exist yet.** A deed has one Warkah or none; the row appears
 * when somebody first composes it. Authorizing against a record that does not exist
 * would mean either creating it to ask the question or answering 404 to a caller who
 * is perfectly entitled to start one.
 *
 * So calls take the class-level form — `authorize('manage', [PpatWarkah::class,
 * $deed])` — the same shape {@see MatterPartyPolicy} uses for participation.
 *
 * ## Four capabilities are implemented, and two are deliberately not
 *
 * ```text
 * ppat.warkah.view      view          read the bundle, its lines and their documents
 * ppat.warkah.update    manage        compose it: lines, order, and status
 * ppat.warkah.verify    verify        mark it COMPLETE, stamping verified_at/_by
 * ppat.warkah.upload    upload        attach and detach the documents on a line
 *
 * ppat.warkah.finalize  ABSENT HERE   registered, unimplemented
 * ppat.warkah.archive   ABSENT HERE   registered, unimplemented
 * ```
 *
 * **`finalize` and `archive` have no ability on this class and no route behind them.**
 * Both codes are canonical and both stay unimplemented, because their *trigger* is
 * open question eight — *"what are the binding/archiving requirements for deeds and
 * supporting Warkah?"* — and `09_PPAT_WORKFLOW.md` section 2 says of exactly these
 * obligations that they are *"precisely the kind of rule that must not be
 * reconstructed from memory."*
 *
 * That leaves `FINALIZED` and `ARCHIVED` as stored vocabulary no code path reaches
 * (D-121 section 12), which is where `notary.minuta.archive` and `.release` also sit
 * (D-064, O-041). `PpatWarkahStatus::unreachable()` names the pair so a test can
 * assert it.
 *
 * ## Nothing here is gated on completeness or on status
 *
 * The M7 lock section 8.2 is explicit: *"Status is settable and not gated"*, and *"no
 * completeness percentage gates any deed act."* Which Warkah must be complete before
 * what is open questions three and eight together, so this class answers **who** and
 * nothing else. There is no transition matrix — the capability *is* the gate, exactly
 * as D-102 ruled for `MatterStatus`.
 */
class PpatWarkahPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly PpatDeedVisibility $visibility,
    ) {}

    /**
     * May the actor open the Warkah list?
     *
     * A grant carrying no usable scope reaches nothing, so it is refused outright
     * rather than serving a reliably empty page.
     */
    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope(
            $this->resolver->resolve($actor, 'ppat.warkah.view')
        );
    }

    /**
     * May the actor read this deed's supporting bundle?
     *
     * **Not the same question as reading the deed.** A caller holding
     * `ppat.deeds.view` and not this code sees the deed and no Warkah section; one
     * holding this and not `ppat.deeds.view` cannot reach the deed at all, because the
     * scope predicate runs over `ppat_deeds` either way.
     */
    public function view(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.warkah.view', $deed);
    }

    /**
     * May the actor compose the bundle — its lines, their order, and its status?
     *
     * This is also what **starts** a Warkah: there is no `ppat.warkah.create` in the
     * catalogue, so the row materialises on the first act of composing it. The same
     * reading M7.3 applied to `properties.ownership.update`, which has no `create`
     * beside it either.
     */
    public function manage(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.warkah.update', $deed);
    }

    /**
     * May the actor mark the bundle verified?
     *
     * Its own capability, and the one act that stamps `verified_at` / `verified_by`.
     * Accepting `COMPLETE` through {@see manage()} would let `ppat.warkah.update`
     * perform an act a separate code was granted to control (D-091) — an office that
     * separates assembling evidence from checking it is saying something real.
     */
    public function verify(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.warkah.verify', $deed);
    }

    /**
     * May the actor attach or detach the documents satisfying a line?
     *
     * **Both directions, on the one code the catalogue offers.** `ppat.warkah.upload`
     * names putting evidence in; there is no `ppat.warkah.detach` or
     * `ppat.warkah.delete`, and removing a document that was filed against the wrong
     * line is the correction of the same act rather than a different one.
     *
     * It is separate from `manage` on purpose: writing down *which* documents a
     * transaction needs is a different job from producing them.
     *
     * **This is not authority to read what is attached.** Opening a Document answers
     * to `documents.view` and downloading to `documents.download`, each with its own
     * Data Scope — a Warkah capability is never a way past those (D-115).
     */
    public function upload(User $actor, PpatDeed $deed): bool
    {
        return $this->reaches($actor, 'ppat.warkah.upload', $deed);
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
