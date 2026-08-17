<?php

namespace App\Domains\Project\Actions;

use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectParty;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Link a Party to a Project (M3.4, D-098).
 *
 * **`office_id` is copied from the Project, never from input.** It is the
 * constraint carrier the composite foreign keys check against, so this Action
 * supplies the value PostgreSQL will then verify against both endpoints. The
 * database is what makes a cross-office participation unrepresentable; the
 * candidate resolution upstream refuses one earlier so an ordinary mistake
 * becomes a 422 rather than a 500.
 *
 * The guard below is defence in depth against the case the database would catch
 * anyway. It exists because a foreign-key violation surfaces as a 500 with a
 * constraint name in it, and a programming error deserves a message that says
 * what went wrong.
 *
 * **Nothing here counts, requires, or infers anything.** No rule demands a
 * participant, caps them, requires exactly one primary, or attaches meaning to a
 * `role_code`. Those would be participant semantics, and M3 has no authority to
 * invent them (D-092).
 *
 * Adding the same Party twice is permitted and creates a second row. No
 * uniqueness rule exists because none is canonical — a Party legitimately
 * appearing under two classifications is a shape the office may need, and
 * refusing it would be a cardinality rule invented by an index.
 */
class AddProjectParty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, Project $project, Party $party, array $attributes): ProjectParty
    {
        if ($party->office_id !== $project->office_id) {
            throw new RuntimeException(
                'A Project participation cannot bridge Offices (D-098). '
                .'The candidate must be resolved through ProjectParticipantVisibility.'
            );
        }

        return DB::transaction(function () use ($actor, $project, $party, $attributes): ProjectParty {
            $participation = new ProjectParty;

            // Not fillable, by design: the endpoints and the Office carrier
            // identify the relationship, and none may arrive through fill().
            $participation->project_id = $project->getKey();
            $participation->party_id = $party->getKey();
            $participation->office_id = $project->office_id;
            $participation->created_by = $actor->getKey();

            // Stamped here because the model has no `updated_at` counterpart and
            // therefore keeps Eloquent's automatic timestamps switched off.
            $participation->created_at = Date::now();

            $participation->fill($attributes);
            $participation->save();

            return $participation;
        });
    }
}
