<?php

namespace App\Policies;

use App\Domains\Authorization\EffectiveAccessResolver;
use App\Domains\Project\ProjectVisibility;
use App\Models\Project;
use App\Models\User;

/**
 * Who may work with Project records (D-088, D-091).
 *
 * Every decision runs through {@see EffectiveAccessResolver} (D-048), so M1's
 * rules apply unchanged: canonical permissions only, a role grant with no Data
 * Scope grants nothing, an active DENY override wins, expired overrides are
 * ignored, and Spatie's direct user-permission grants never participate. No role
 * name is read anywhere, and `SUPER_ADMIN` receives no bypass.
 *
 * **`projects.view_all` appears nowhere in this class, deliberately** (D-090). It
 * is registered for compatibility and superseded by Data Scope `ALL` for reach.
 * Consulting it here would be the second reach mechanism the decision forbids —
 * and two answers to one question do not stay equal, with the looser one winning
 * by accident.
 *
 * **The mutation abilities are the half worth reading carefully.** `update`,
 * `assign`, and `changeStatus` are three separate capabilities over one record,
 * and none implies another. Holding `projects.update` does not let a person
 * reassign a Project or move its status — the registry has always carried
 * separate codes, and the model additionally withholds both fields from mass
 * assignment so a careless Action cannot route around this (D-091).
 *
 * M3.1 exposes no HTTP endpoint. These abilities exist and are tested directly so
 * that M3.3 has only to call them — the same way M2.1 prepared Party.
 */
class ProjectPolicy
{
    public function __construct(
        private readonly EffectiveAccessResolver $resolver,
        private readonly ProjectVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->visibility->hasUsableScope($this->resolver->resolve($actor, 'projects.view'));
    }

    public function view(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.view', $project);
    }

    /**
     * May the actor create a Project?
     *
     * Judged against the record about to exist, which narrows the usable scopes
     * (D-097). `OWN` qualifies because the actor becomes `created_by`, `OFFICE`
     * because it lands in their Office, and `ALL` because it matches everything.
     *
     * **`ASSIGNED` does not qualify**, and that is the interesting case: a new
     * Project starts with no PIC, so `pic_user_id == actor.id` is false for the
     * very record being asked about. Accepting it would read as "may create work
     * they will be assigned to", which describes nothing the product does — and
     * would hand create authority to a grant that was only ever meant to expose
     * work already routed to somebody.
     *
     * The Project always lands in the actor's Office, so `$officeId` is only
     * meaningful to a caller that names a destination; M3.3's surface does not,
     * because there is no Office selector.
     */
    public function create(User $actor, ?string $officeId = null): bool
    {
        $access = $this->resolver->resolve($actor, 'projects.create');

        if ($officeId === null) {
            return $this->visibility->permitsCreation($access);
        }

        return $this->visibility->permitsCreationIn($actor, $access, $officeId);
    }

    /**
     * Ordinary attributes only. Not the PIC, and not the status.
     */
    public function update(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.update', $project);
    }

    /**
     * Mutating `pic_user_id`, and nothing else (D-091).
     *
     * Deliberately not implied by `update`: reassigning work is a different act
     * from correcting a title, and the canonical registry has always said so.
     */
    public function assign(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.assign', $project);
    }

    /**
     * Mutating business `status`, and nothing else (D-091).
     *
     * This answers *who may change status*. It does not answer *which* changes
     * are legal — no transition matrix exists, because no canonical document
     * defines one, and inventing one would be the failure CLAUDE.md section 62
     * prohibits one domain removed.
     */
    public function changeStatus(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.change_status', $project);
    }

    /**
     * Soft-delete the record. Not the same act as moving business status to
     * `ARCHIVED`, which answers to `changeStatus` (D-093).
     */
    public function archive(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.archive', $project);
    }

    /**
     * Restore a soft-deleted Project record — and nothing beyond that: no
     * business status reversal, no workflow, no undoing a completion (D-093).
     *
     * The only ability that looks at an archived row. The ordinary predicate
     * excludes soft-deleted records, so without `includeArchived` this
     * permission would answer false for every record it exists to govern.
     */
    public function restore(User $actor, Project $project): bool
    {
        return $this->reaches($actor, 'projects.restore', $project, includeArchived: true);
    }

    /**
     * Open the archived-Project list (M3.3).
     *
     * Answers to **`projects.restore`, not `projects.view`**, and that is the
     * whole reason the surface exists separately. Restoring needs a way to find
     * the record, but widening ordinary view to include soft-deleted rows would
     * expose archived work to everyone who can read Projects at all — a much
     * larger group, and one nobody granted archive-visibility to.
     *
     * Holding `projects.view` therefore reaches no archived record anywhere, and
     * holding `projects.restore` reaches archived records **within its own Data
     * Scope** and nothing live.
     */
    public function viewArchived(User $actor): bool
    {
        return $this->visibility->hasUsableScope($this->resolver->resolve($actor, 'projects.restore'));
    }

    private function reaches(
        User $actor,
        string $permission,
        Project $project,
        bool $includeArchived = false,
    ): bool {
        return $this->visibility->permits(
            $actor,
            $this->resolver->resolve($actor, $permission),
            $project,
            $includeArchived,
        );
    }
}
