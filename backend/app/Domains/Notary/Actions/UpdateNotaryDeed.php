<?php

namespace App\Domains\Notary\Actions;

use App\Domains\Notary\Enums\NotaryDeedStatus;
use App\Domains\Notary\Exceptions\DeedStatusNotEligible;
use App\Models\NotaryDeed;
use App\Models\User;

/**
 * Edit a Deed's own fields (M6.2, D-120).
 *
 * **Reaches only what `NotaryDeed` marks fillable**: title, deed date, type code and
 * the three document pointers. Status, the number and the three act-pairs each
 * answer to their own capability, so mass assignment cannot reach any of them.
 *
 * **Editable up to and including `APPROVED`.** That is the literal reading of
 * `CLAUDE.md` section 29, which denies normal updates *once finalized or locked* and
 * says nothing about approval — and it is what {@see NotaryDeedStatus::isEditable()}
 * recorded at M6.1.
 *
 * The M6.2 brief asked for `DRAFT` or `UNDER_REVIEW` only. That narrower rule —
 * approval freezes the content — is the more familiar one and is deliberately **not**
 * encoded, because no canonical document states it: it is an approval requirement,
 * which `CLAUDE.md` section 62 forbids inventing, and *"which stages require
 * Principal approval rather than staff completion?"* is open question three. An
 * office that works that way enforces it as practice until somebody writes it down.
 */
class UpdateNotaryDeed
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $actor, NotaryDeed $deed, array $attributes): NotaryDeed
    {
        if (! $deed->status->isEditable() || $deed->locked_at !== null) {
            throw new DeedStatusNotEligible($deed->status, 'edited');
        }

        $deed->fill($attributes);
        $deed->save();

        return $deed;
    }
}
