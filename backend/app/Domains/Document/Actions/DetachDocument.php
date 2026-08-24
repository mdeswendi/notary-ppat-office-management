<?php

namespace App\Domains\Document\Actions;

use App\Domains\Document\Enums\DocumentRelationType;
use App\Domains\Document\Exceptions\DocumentNotAttached;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Remove a Document's attachment to a Party, Project or Matter (M5.3, D-118).
 *
 * **Detaching removes the junction row and touches nothing else.** The Document
 * survives untouched — its file, its versions, its checksum, its status. That is
 * the whole reason attachment lives in a junction rather than as a column: a
 * document filed against the wrong Matter is corrected by moving the
 * relationship, not by deleting and re-uploading a legal record.
 *
 * **The junction is a relationship, not a history** (D-098's position, one domain
 * across). There is no `deleted_at` on these tables and no soft delete here:
 * detaching removes the row. When an audit store exists it records the event; a
 * tombstone column would be a second, weaker answer to the same question.
 *
 * `$actor` is taken and deliberately unused for persistence — there is no
 * `detached_by` column to write it to, for the reason above. It stays in the
 * signature because the audit milestone needs it and because an action that
 * cannot name who acted is one somebody will later have to re-plumb.
 *
 * The Policy judged the actor before this ran.
 */
class DetachDocument
{
    /**
     * @param  Model  $target  already resolved and authorized by the caller
     */
    public function handle(
        User $actor,
        Document $document,
        DocumentRelationType $type,
        Model $target,
    ): void {
        $junction = $type->junction();
        $foreignKey = $type->foreignKey();

        DB::transaction(function () use ($document, $type, $target, $junction, $foreignKey): void {
            $rows = $junction::query()
                ->where($foreignKey, $target->getKey())
                ->where('document_id', $document->getKey())
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                throw new DocumentNotAttached($type);
            }

            // **Every matching row, not the first.** The schema permits duplicates
            // (D-116 declined to forbid them) even though the attach surface
            // refuses to create one, so a row pair could exist from a direct
            // database write or from a future milestone that allows them
            // deliberately. Detaching once should leave nothing behind rather than
            // require the caller to click until the list empties.
            foreach ($rows as $row) {
                $row->delete();
            }
        });
    }
}
