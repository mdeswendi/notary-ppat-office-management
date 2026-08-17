<?php

namespace App\Domains\Matter\Actions;

use App\Domains\Matter\AllocateMatterReference;
use App\Domains\Matter\Enums\MatterDomain;
use App\Domains\Matter\Enums\MatterStatus;
use App\Models\Matter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a Matter under a Project (M4.4, D-109).
 *
 * **Seven fields are decided here and cannot be requested.** The caller supplies
 * ordinary attributes; the system supplies everything that would otherwise be a
 * way around a boundary:
 *
 *   project_id      the parent the Policy already authorized
 *   office_id       inherited from that Project, never the actor's choice (D-099)
 *   domain          the route's domain, never the request body (D-101)
 *   matter_number   allocated by the M4.3 allocator, never supplied (D-103)
 *   status          OPEN
 *   pic_user_id     null — a new Matter is unassigned
 *   created_by      the actor
 *
 * **Office is inherited rather than taken from the actor**, which is where this
 * differs from `CreateProject`. A Project lands in the actor's own Office because
 * nothing else could decide it; a Matter lands in its **Project's** Office because
 * the parent already answers the question. The Policy additionally requires that
 * Project to be in the actor's own Office, so the two agree — but they agree by
 * argument, not by coincidence, and the composite foreign key refuses any row
 * where they would not.
 *
 * **One transaction, and the allocation is inside it.** If the insert fails, the
 * rollback takes the counter increment with it and the reference is not spent —
 * proven by test in M4.3. A reference is only permanently skipped when an
 * allocation **commits** and is then not used, which this path does not do.
 *
 * The Policy judged the actor before this ran. This action does not re-decide
 * authorization; it records who acted.
 */
class CreateMatter
{
    public function __construct(private readonly AllocateMatterReference $allocator) {}

    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(
        User $actor,
        Project $project,
        MatterDomain $domain,
        array $attributes,
    ): Matter {
        return DB::transaction(function () use ($actor, $project, $domain, $attributes): Matter {
            $matter = new Matter;

            // None of these is fillable, by design. Assigning them explicitly is
            // the point: a reader can see every system-controlled field in one
            // place rather than inferring it from what the Request omitted.
            $matter->project_id = $project->getKey();
            $matter->office_id = $project->office_id;
            $matter->domain = $domain;
            $matter->matter_number = $this->allocator->forOffice($project->office_id, $domain);
            $matter->status = MatterStatus::OPEN;
            $matter->pic_user_id = null;
            $matter->created_by = $actor->getKey();
            $matter->updated_by = $actor->getKey();

            $matter->fill($attributes);
            $matter->save();

            return $matter;
        });
    }
}
