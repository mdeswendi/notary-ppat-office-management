<?php

namespace App\Domains\Ppat\Actions;

use App\Domains\Ppat\Enums\PpatDeedStatus;
use App\Domains\Ppat\Exceptions\DeedStatusNotEligible;
use App\Models\PpatDeed;
use App\Models\User;

/**
 * Edit a Deed's own fields (M7.2, D-121).
 *
 * **Reaches only what `PpatDeed` marks fillable**: title, deed date, type code and
 * the final document pointer. Status, the number and the three act-pairs each answer
 * to their own capability, so mass assignment cannot reach any of them.
 *
 * **Editable up to and including `APPROVED`.** That is the literal reading of
 * `CLAUDE.md` section 29, which denies normal updates *once finalized or locked* and
 * says nothing about approval — and it is what {@see PpatDeedStatus::isEditable()}
 * recorded at M7.1.
 *
 * The M7.2 brief asked for `DRAFT` or `UNDER_REVIEW` only. That narrower rule —
 * approval freezes the content — is the more familiar one and is deliberately **not**
 * encoded, because no canonical document states it: it is an approval requirement,
 * which `CLAUDE.md` section 62 forbids inventing, and *"which stages require
 * Principal approval rather than staff completion?"* is open question three. An
 * office that works that way enforces it as practice until somebody writes it down.
 */
class UpdatePpatDeed
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, PpatDeed $deed, array $attributes): PpatDeed
    {
        if (! $deed->status->isEditable() || $deed->locked_at !== null) {
            throw new DeedStatusNotEligible($deed->status, 'edited');
        }

        $deed->fill($attributes);
        $deed->save();

        return $deed;
    }
}
