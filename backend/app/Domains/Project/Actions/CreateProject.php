<?php

namespace App\Domains\Project\Actions;

use App\Domains\Project\AllocateProjectReference;
use App\Domains\Project\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a Project in the actor's Office (M3.3, D-097).
 *
 * **Five fields are decided here and cannot be requested.** The caller supplies
 * ordinary attributes; the system supplies everything that would otherwise be a
 * way around a boundary:
 *
 *   office_id       the actor's own Office, always
 *   project_number  allocated by the accepted M3.2 allocator, never supplied
 *   status          OPEN
 *   pic_user_id     null — a new Project is unassigned
 *   created_by      the actor
 *
 * **Creation is Office-bound even for an `ALL` actor.** `ALL` is cross-office
 * *reach* over Projects that already exist (D-088); it is not authority to create
 * somewhere else. There is no `office_id` in the request shape, so this is
 * structural rather than a rule the Form Request has to remember — and the
 * Request additionally prohibits the field so an over-post is refused loudly
 * instead of ignored.
 *
 * **One transaction, and the allocation is inside it.** If the insert fails the
 * transaction rolls back, but the counter does not: the reference is spent and a
 * gap appears. That is the accepted trade (D-096) — the alternatives are reusing
 * references or serializing every create behind one lock — and it is why the
 * sequence is not a record count.
 *
 * The Policy judged the actor before this ran. This action does not re-decide
 * authorization; it records who acted.
 */
class CreateProject
{
    public function __construct(private readonly AllocateProjectReference $allocator) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, array $attributes): Project
    {
        return DB::transaction(function () use ($actor, $attributes): Project {
            $project = new Project;

            // None of these is fillable, by design. Assigning them explicitly is
            // the point: a reader can see every system-controlled field in one
            // place rather than inferring it from what the Request omitted.
            $project->office_id = $actor->office_id;
            $project->project_number = $this->allocator->forOffice($actor->office_id);
            $project->status = ProjectStatus::OPEN;
            $project->pic_user_id = null;
            $project->created_by = $actor->getKey();
            $project->updated_by = $actor->getKey();

            $project->fill($attributes);
            $project->save();

            return $project;
        });
    }
}
