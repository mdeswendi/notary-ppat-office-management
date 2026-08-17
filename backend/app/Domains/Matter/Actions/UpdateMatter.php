<?php

namespace App\Domains\Matter\Actions;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct a Matter's ordinary attributes (M4.4, D-109).
 *
 * **Ordinary attributes, and nothing else.** Not the parent Project, not the
 * Office, not the domain, not the internal reference, not the PIC, and not the
 * business status.
 *
 * Each exclusion has its own reason and its own guard, so this action does not
 * have to be trusted to remember them: the model refuses the identity fields and
 * the reference outright, and the rest are simply not fillable. `pic_user_id`
 * answers to `*.matters.assign` and status to `*.matters.complete` /
 * `*.matters.cancel`, so reaching either from here would make ordinary update a
 * silent superset of a capability an administrator granted separately (D-091's
 * discipline, D-107's independence).
 *
 * `service_type_id` **is** editable here, and that is deliberate rather than an
 * oversight: reclassifying work is an ordinary correction, not a lifecycle event,
 * and the database still refuses any Service Type from another Office or the
 * other domain (D-107).
 */
class UpdateMatter
{
    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Matter $matter, array $attributes): Matter
    {
        return DB::transaction(function () use ($actor, $matter, $attributes): Matter {
            $matter->fill($attributes);

            // `service_type_id` is not fillable — it is classification rather
            // than free content, so it is assigned explicitly where a reader can
            // see it, exactly as the create path assigns its system fields.
            if (array_key_exists('service_type_id', $attributes)) {
                $matter->service_type_id = $attributes['service_type_id'];
            }

            $matter->updated_by = $actor->getKey();
            $matter->save();

            return $matter->refresh();
        });
    }
}
