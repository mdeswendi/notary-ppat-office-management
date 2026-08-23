<?php

namespace App\Domains\Matter\Actions;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Link a Party to a Matter (M4.5, D-105).
 *
 * **`office_id` is copied from the Matter, never from input.** It is the
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
 * **Nothing here reads `project_parties`.** A Matter's participants are not
 * inherited from its parent Project, not copied from it, and not kept in step
 * with it (D-105). The parent Project's participants may one day be *offered* as
 * candidates by an interface; that would be a convenience for whoever is typing,
 * and it would still pass through this Action one explicit row at a time.
 *
 * **Nothing here counts, requires, or infers anything.** No rule demands a
 * participant, caps them, requires a seller, or attaches meaning to a
 * `role_code`. Those would be participant semantics, and M4 has no authority to
 * invent them (D-105, CLAUDE.md section 62).
 *
 * Adding the same Party twice is permitted and creates a second row. No
 * uniqueness rule exists because none is canonical — a Party legitimately
 * appearing under two classifications, say as a seller in their own right and as
 * another party's authorized representative, is a shape the office may need, and
 * refusing it would be a cardinality rule invented by an index.
 */
class AddMatterParty
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, Matter $matter, Party $party, array $attributes): MatterParty
    {
        if ($party->office_id !== $matter->office_id) {
            throw new RuntimeException(
                'A Matter participation cannot bridge Offices (D-105). '
                .'The candidate must be resolved through ParticipantVisibility.'
            );
        }

        return DB::transaction(function () use ($actor, $matter, $party, $attributes): MatterParty {
            $participation = new MatterParty;

            // Not fillable, by design: the endpoints and the Office carrier
            // identify the relationship, and none may arrive through fill().
            $participation->matter_id = $matter->getKey();
            $participation->party_id = $party->getKey();
            $participation->office_id = $matter->office_id;
            $participation->created_by = $actor->getKey();

            $participation->fill($attributes);
            $participation->save();

            return $participation;
        });
    }
}
