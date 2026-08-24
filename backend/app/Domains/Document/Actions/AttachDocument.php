<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\Enums\DocumentRelationType;
use App\Domains\Document\Exceptions\DocumentAlreadyAttached;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Attach a Document to a Party, Project or Matter (M5.3, D-118).
 *
 * **`office_id` is written from the Document and never from the request.** It is
 * a constraint carrier rather than data: the two composite foreign keys on each
 * junction resolve through it, so one source means they cannot disagree with each
 * other. A cross-office attachment is refused by the database even if every check
 * above this were wrong — which is the point of writing it structurally rather
 * than validating it (D-116).
 *
 * **The duplicate check is a surface rule, not a schema one.** The junctions carry
 * no `UNIQUE (owner_id, document_id)`, because M5.1 declined to invent a
 * cardinality rule no canonical document states (D-105, D-110, D-116). D-110 also
 * said what to do if an office decides duplicates are wrong — *"a rule to state
 * and validate"* — so it is stated here and the schema stays open.
 *
 * It runs **inside the transaction**, so two concurrent attaches cannot both pass
 * the check and both insert. The row is locked for the duration, which is a
 * moment.
 *
 * **Nothing is audited, and that is not an oversight.** `audit_logs` does not
 * exist, and D-115 rules that no half-measure ships: an application log is not
 * append-only in the sense `CLAUDE.md` section 31 means, is not queryable by
 * resource, and is the stopgap that becomes permanent. `attached_by` and
 * `attached_at` record who and when on the row itself; the event record waits for
 * the store built to hold it.
 *
 * The Policy judged the actor before this ran, and the caller resolved the target
 * through its own domain's visibility. This action does not re-decide
 * authorization; it records who acted.
 */
class AttachDocument
{
    /**
     * @param  Model  $target  already resolved and authorized by the caller
     */
    public function handle(
        User $actor,
        Document $document,
        DocumentRelationType $type,
        Model $target,
    ): Model {
        $junction = $type->junction();
        $foreignKey = $type->foreignKey();

        return DB::transaction(function () use ($actor, $document, $type, $target, $junction, $foreignKey): Model {
            $existing = $junction::query()
                ->where($foreignKey, $target->getKey())
                ->where('document_id', $document->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new DocumentAlreadyAttached($type);
            }

            $row = new $junction;

            $row->{$foreignKey} = $target->getKey();
            $row->document_id = $document->getKey();

            // From the Document. Never the request, never the target — one source,
            // so the two composite keys cannot disagree.
            $row->office_id = $document->office_id;

            $row->attached_by = $actor->getKey();
            $row->attached_at = Date::now();
            $row->save();

            return $row;
        });
    }
}
