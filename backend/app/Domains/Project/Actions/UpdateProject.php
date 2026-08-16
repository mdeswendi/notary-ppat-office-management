<?php

namespace App\Domains\Project\Actions;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a Project's ordinary attributes (M3.3, D-091).
 *
 * **Ordinary means ordinary.** Title, description, priority, and the two planning
 * dates. Not the Office, not the reference, not the status, not the PIC — each of
 * those answers to its own capability or is immutable outright, and each is
 * refused three times over:
 *
 *   the Form Request        prohibits the field, so an over-post is a 422
 *   the model               does not make it fillable, so `fill()` cannot reach it
 *   the model / Policy      guards or separates it independently of the request
 *
 * Three layers is not paranoia here. Each one fails differently: validation
 * catches an honest client, `$fillable` catches a careless Action, and the
 * `updating` guard catches direct attribute assignment. A single layer would be
 * one refactor away from silently permitting what the milestone forbids.
 */
class UpdateProject
{
    /**
     * @param  array<string, mixed>  $attributes  ordinary fields only
     */
    public function handle(User $actor, Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($actor, $project, $attributes): Project {
            $project->fill($attributes);
            $project->updated_by = $actor->getKey();
            $project->save();

            return $project->refresh();
        });
    }
}
