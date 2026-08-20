<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Party\ParticipantVisibility;
use App\Domains\Project\ProjectVisibility;
use App\Models\Project;
use App\Models\User;

/**
 * Who may read and maintain a Project's participants (M3.4, D-098).
 *
 * **Every ability is judged against the parent Project**, never against the
 * participation row and never against the Party. A participation belongs to the
 * Project the way a title does: the Project is the record whose Office owns it,
 * whose PIC is assigned to it, and whose creator opened it, so the four D-088
 * predicates are exactly the right question to ask. Judging against the Party
 * instead would put Project work behind Party permissions, which govern a
 * different thing.
 *
 * **`view` and `manage` are independent capabilities and neither implies the
 * other.** That is deliberate and worth stating because the opposite feels
 * natural: surely someone who may edit participants may read them. The registry
 * says two codes, so two codes is what this class honours — the same discipline
 * D-091 applies to `projects.update`, `projects.assign`, and
 * `projects.change_status`, none of which implies another. An administrator who
 * wants both grants both; the software does not decide that on their behalf,
 * because a silently implied capability is one nobody configured and nobody can
 * revoke.
 *
 * `projects.update` reaches none of this. Renaming a Project and deciding who is
 * involved in it are different acts, and if ordinary update carried
 * participation authority the dedicated code would be decoration.
 *
 * There is deliberately **no `projects.parties.view_all`** to consult. Reach is
 * Data Scope `ALL` against the parent Project; a second reach mechanism is what
 * D-090 refuses, and two answers to one question do not stay equal.
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048): canonical
 * codes only, a role grant with no Data Scope grants nothing, an active DENY
 * wins, Spatie's direct user grants never participate. No role name is read
 * anywhere and `SUPER_ADMIN` receives no bypass.
 *
 * Party visibility is **not** decided here — see
 * {@see ParticipantVisibility}. Holding `manage` is
 * authority over this Project's participation, never authority to discover
 * Parties.
 */
class ProjectPartyPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ProjectVisibility $visibility,
    ) {}

    /**
     * Read the participation list of this Project.
     */
    public function viewAny(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.parties.view', $project);
    }

    /**
     * Add, correct, or remove a participation on this Project.
     *
     * One capability for all three rather than three codes: the registry defines
     * `projects.parties.manage` and inventing finer grades here would be
     * authorization the canonical catalogue never described.
     */
    public function manage(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.parties.manage', $project);
    }

    /**
     * The parent Project must be reachable under the participation permission's
     * own Data Scope.
     *
     * Archived Projects are excluded, as they are for every ability except
     * `restore`: a soft-deleted Project is not a place to be organising
     * participants.
     */
    private function reaches(User $actor, string $permission, Project $project): bool
    {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $project,
        );
    }
}
