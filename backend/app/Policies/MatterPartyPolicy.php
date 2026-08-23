<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\MatterVisibility;
use App\Domains\Party\ParticipantVisibility;
use App\Models\Matter;
use App\Models\User;

/**
 * Who may read and maintain a Matter's participants (M4.5, D-105, D-110).
 *
 * **Every ability is judged against the parent Matter**, never against the
 * participation row and never against the Party. A participation belongs to the
 * Matter the way a title does: the Matter is the record whose Office owns it,
 * whose PIC is assigned to it, and whose creator opened it, so the four D-100
 * predicates are exactly the right question to ask. Judging against the Party
 * instead would put Matter work behind Party permissions, which govern a
 * different thing.
 *
 * ## The domain context
 *
 * As with {@see MatterPolicy}, there is **one Policy, not two**, and it takes the
 * domain as an **explicit argument supplied by the caller's route** (D-101):
 *
 * ```php
 * $this->authorize('viewAny', [MatterParty::class, $matter, MatterDomain::NOTARY]);
 * ```
 *
 * The domain decides the permission namespace — `notary.matters.parties.*` or
 * `ppat.matters.parties.*` — and **it never comes from `$matter->domain`**.
 * Reading the row would be the obvious shortcut and it is exactly the shape
 * D-101 forbids: a Policy choosing which permission to resolve from the record it
 * is being asked about.
 *
 * A **second, separate rule keeps the row honest**: the supplied domain must
 * equal the persisted `domain`, or every ability refuses. The route binding turns
 * that mismatch into a 404 before it gets here; the Policy check exists so the
 * invariant does not depend on the router being written correctly.
 *
 * ## Independence
 *
 * **`view` and `manage` are independent capabilities and neither implies the
 * other.** That is deliberate and worth stating because the opposite feels
 * natural: surely someone who may edit participants may read them. The registry
 * says two codes per domain, so two codes is what this class honours — the same
 * discipline D-091 applies to `projects.update` / `assign` / `change_status` and
 * D-098 to Project participation. An administrator who wants both grants both;
 * the software does not decide that on their behalf, because a silently implied
 * capability is one nobody configured and nobody can revoke.
 *
 * `notary.matters.update` and `ppat.matters.update` reach none of this. Renaming
 * a Matter and deciding who is involved in it are different acts, and if ordinary
 * update carried participation authority the dedicated codes would be decoration.
 *
 * **Four codes rather than two**, and the split is real: `02_MENU_AND_PERMISSIONS.md`
 * section 5 gives Notary Staff full access to Notary Matters and view-only on
 * PPAT Matters, and the reverse for PPAT Staff. One pair spanning both domains
 * would hand each of them the other's participation.
 *
 * There is deliberately **no `*.matters.parties.view_all`** to consult. Reach is
 * Data Scope `ALL` against the parent Matter; a second reach mechanism is what
 * D-090 refuses, and two answers to one question do not stay equal.
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048): canonical
 * codes only, a role grant with no Data Scope grants nothing, an active DENY
 * wins, Spatie's direct user grants never participate. No role name is read
 * anywhere and `SUPER_ADMIN` receives no bypass.
 *
 * Party visibility is **not** decided here — see {@see ParticipantVisibility}.
 * Holding `manage` is authority over this Matter's participation, never
 * authority to discover Parties.
 */
class MatterPartyPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly MatterVisibility $visibility,
    ) {}

    /**
     * Read the participation list of this Matter.
     */
    public function viewAny(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'parties.view', $matter, $domain);
    }

    /**
     * Add, correct, or remove a participation on this Matter.
     *
     * One capability for all three rather than three codes: the registry defines
     * `*.matters.parties.manage` and inventing finer grades here would be
     * authorization the canonical catalogue never described.
     */
    public function manage(User $actor, Matter $matter, MatterDomain $domain): bool
    {
        return $this->reaches($actor, 'parties.manage', $matter, $domain);
    }

    /**
     * The parent Matter must be reachable under the participation permission's
     * own Data Scope, and must actually belong to the addressed domain.
     *
     * The domain equality check is not redundant with the route binding. The
     * router answers "does this address resolve"; this answers "is the capability
     * I resolved the one that governs this row". Both must hold, and collapsing
     * them would reinstate the row-derived namespace by the back door.
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
