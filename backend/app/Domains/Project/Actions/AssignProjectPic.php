<?php

namespace App\Domains\Project\Actions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Set or clear a Project's person in charge (M3.3, D-091, D-097).
 *
 * **`pic_user_id`, and nothing else.** Not the status, not the title. The
 * registry has always carried `projects.assign` separately from
 * `projects.update`, and this is where that separation becomes a fact rather
 * than an intention.
 *
 * **The PIC must belong to the Project's own Office — even when the actor holds
 * `ALL`.** That restriction is not tidiness; it closes a hole that would
 * otherwise open silently.
 *
 * `ASSIGNED` grants reach when `project.pic_user_id == actor.id` (D-088). If an
 * `ALL`-scoped administrator could name somebody from another Office as PIC, they
 * would be **granting that person cross-office reach** over a Project their own
 * Office scope never included — without an administrator editing a single role,
 * and without anything in the permission matrix changing. Assignment would
 * quietly become a second way to widen access. Confining the PIC to the Office
 * keeps `ASSIGNED` a predicate about work somebody actually does in their own
 * Office.
 *
 * The eligibility rule is deliberately structural and nothing more: an existing,
 * active, non-deleted User in the same Office. **No role name is read**, and no
 * required role is invented — no canonical document names one, and inventing a
 * "only a notary may be PIC" rule would be a legal-ish constraint from memory.
 *
 * Validation enforces eligibility as a 422 before this runs; the check is
 * repeated here because an Action must be safe to call from anywhere, not only
 * from behind the one Form Request that happens to guard it today.
 */
class AssignProjectPic
{
    public function handle(User $actor, Project $project, ?string $picUserId): Project
    {
        return DB::transaction(function () use ($actor, $project, $picUserId): Project {
            if ($picUserId !== null && ! $this->isEligible($project, $picUserId)) {
                throw new \InvalidArgumentException(
                    'A Project PIC must be an active user in the same Office as the Project.'
                );
            }

            $project->pic_user_id = $picUserId;
            $project->updated_by = $actor->getKey();
            $project->save();

            return $project->refresh();
        });
    }

    /**
     * An existing, active, non-deleted User in the Project's Office.
     *
     * `User` uses soft deletes, so the default query already excludes deleted
     * rows — stated rather than relied on silently.
     */
    private function isEligible(Project $project, string $picUserId): bool
    {
        return User::query()
            ->whereKey($picUserId)
            ->where('office_id', $project->office_id)
            ->where('is_active', true)
            ->exists();
    }
}
