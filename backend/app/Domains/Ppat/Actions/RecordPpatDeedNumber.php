<?php

namespace App\Domains\Ppat\Actions;

use App\Models\PpatDeed;
use App\Models\User;

/**
 * Record the legal number the office assigned to a Deed (M7.2, D-121).
 *
 * **Its own act, answering to `ppat.deeds.number`** — the mirror of the Notary
 * capability M6.2 gave a surface to, and defined by the canonical catalogue for the
 * same reason: assigning a deed number is a distinct decision from preparing,
 * approving or finalizing one.
 *
 * **The office supplies the number. The software validates no format and generates
 * nothing.** *"What are the deed numbering rules, and who assigns the number?"* is
 * open question five, and `CLAUDE.md` section 62 names deed numbering rules explicitly
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
class RecordPpatDeedNumber
{
    public function handle(User $actor, PpatDeed $deed, string $number): PpatDeed
    {
        $deed->deed_number = $number;
        $deed->save();

        return $deed;
    }
}
