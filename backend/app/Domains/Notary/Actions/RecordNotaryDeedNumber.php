<?php

namespace App\Domains\Notary\Actions;

use App\Models\NotaryDeed;
use App\Models\User;

/**
 * Record the legal number the office assigned to a Deed (M6.2, D-120).
 *
 * **Its own act, answering to `notary.deeds.number`** — the capability the canonical
 * catalogue defined and nothing in this repository had used before M6. It exists
 * because assigning a deed number is a distinct decision from preparing, approving or
 * finalizing one, and the catalogue said so before anybody here noticed.
 *
 * **The office supplies the number. The software validates no format and generates
 * nothing.** *"What are the deed numbering rules, and who assigns the number?"* is
 * open question one, and `CLAUDE.md` section 62 names deed numbering rules explicitly
 * among the things not to invent. D-103 separately ruled that the Matter allocator's
 * `N-YYYY-NNNNNN` is *"an operational identifier, never a legal deed number"*.
 *
 * All the software enforces is that two deeds in one Office do not share a number,
 * which is a uniqueness property rather than a numbering rule — the Form Request
 * turns the database's refusal into a field error.
 *
 * **No status is required and none is changed.** Tying numbering to a lifecycle
 * position would answer the other half of the open question.
 */
class RecordNotaryDeedNumber
{
    public function handle(User $actor, NotaryDeed $deed, string $number): NotaryDeed
    {
        $deed->deed_number = $number;
        $deed->save();

        return $deed;
    }
}
