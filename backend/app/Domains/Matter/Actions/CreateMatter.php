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
    public function __construct(
        private readonly AllocateMatterReference $allocator,
        private readonly InstantiateMatterWorkflow $workflow,
    ) {}

    /**
     * @param  string|null  $serviceTypeId  already resolved and authorized by the caller
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(
        User $actor,
        Project $project,
        MatterDomain $domain,
        ?string $serviceTypeId,
        array $attributes,
    ): Matter {
        return DB::transaction(function () use ($actor, $project, $domain, $serviceTypeId, $attributes): Matter {
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

            // **An explicit parameter rather than a fillable attribute, and set
            // here rather than afterwards** *(corrected at M4.7)*. It is not
            // fillable — classification is not an ordinary field — so M4.4
            // assigned it in the controller *after* this action returned, which
            // was a second write outside this transaction and left the Matter
            // briefly unclassified.
            //
            // M4.7 made that a defect rather than an untidiness: workflow
            // instantiation reads `service_type_id` to prefer a template
            // configured for that service, and running before the value was set
            // meant the preference could never fire in production, while passing
            // in a directly-constructed test.
            $matter->service_type_id = $serviceTypeId;

            $matter->fill($attributes);
            $matter->save();

            // **Called explicitly rather than through a model observer** (M4.7).
            // The repository registers none, and one here would make creating a
            // Matter silently do two things — including inside every factory
            // call in the suite. It also has to join *this* transaction: a
            // workflow that committed while its Matter rolled back would be an
            // orphan the `UNIQUE (matter_id)` key then blocks forever.
            //
            // Returns null when the office has configured no template, which on
            // a fresh deployment is every time: D-104 seeds no workflow content,
            // so the Matter is created without one rather than refused (D-112).
            $this->workflow->handle($actor, $matter);

            return $matter;
        });
    }
}
