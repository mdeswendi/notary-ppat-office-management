<?php

namespace App\Http\Resources;

use App\Models\Matter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Matter, as the list and detail endpoints return it (M4.4, D-109).
 *
 * **Note what has no field here.** No participant collection — `matter_parties`
 * is M4.5 (D-105), and a Matter payload will not carry one even then, because
 * participation is its own nested surface. No workflow, stage, or stage history:
 * none exists (D-104), and a key for it would invite a component to render
 * something the API never sends. No deed, Minuta, Warkah, property, or tax data —
 * all of it M6 and M7. And **no Party identity of any kind**: a Matter reaches
 * Parties only through `matter_parties` when that exists, and identity stays
 * behind the surfaces that already authorize it (D-082).
 *
 * `matter_number` is displayed and never accepted: it is system-generated,
 * immutable, and — because uniqueness is per Office (D-103) — it does **not**
 * identify a Matter on its own. It is never the route key for that reason, and
 * the Office travels with it rather than for decoration.
 *
 * The three related records appear as **stubs**, not embedded resources. A
 * Project stub carries what a Matter list needs to say which engagement the work
 * belongs to; a Service Type stub carries both names so the interface can render
 * either locale without a second request, and `is_active` so a retired
 * classification can be shown as such; a PIC stub carries a name. None of them is
 * a way to read a record the caller could not open directly — they are labels for
 * ids this Matter already legitimately holds.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, so
 * the interface asks the same question the backend will ask rather than
 * reimplementing Data Scope in TypeScript. They are not an authorization surface:
 * every endpoint authorizes again, and a client that lies to itself about these
 * gains nothing. Raw scope metadata is deliberately not exposed — that would be
 * handing the frontend the parts to build a second, divergent resolver.
 *
 * @mixin Matter
 */
class MatterResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(Matter $resource, private readonly array $capabilities = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'matter_number' => $this->matter_number,
            'domain' => $this->domain->value,
            'title' => $this->title,
            'status' => $this->status->value,
            'priority' => $this->priority?->value,
            'notes' => $this->notes,

            'opened_at' => $this->opened_at?->toDateString(),
            'target_completion_date' => $this->target_completion_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->id, 'code' => $this->office->code, 'name' => $this->office->name]
                : null,

            // Which engagement this work belongs to. Enough to label it, and no
            // more — a Project's own surface is where a Project is read.
            'project' => $this->relationLoaded('project') && $this->project !== null
                ? [
                    'id' => $this->project->id,
                    'project_number' => $this->project->project_number,
                    'title' => $this->project->title,
                ]
                : null,

            // Both names, so either locale renders without a second request, and
            // `is_active` so a retired classification is visibly retired rather
            // than silently ordinary.
            'service_type' => $this->relationLoaded('serviceType') && $this->serviceType !== null
                ? [
                    'id' => $this->serviceType->id,
                    'code' => $this->serviceType->code,
                    'name_id' => $this->serviceType->name_id,
                    'name_en' => $this->serviceType->name_en,
                    'is_active' => $this->serviceType->is_active,
                ]
                : null,

            // The ASSIGNED predicate made visible. Name only — a Matter surface
            // is not a user-administration surface.
            'pic' => $this->relationLoaded('picUser') && $this->picUser !== null
                ? ['id' => $this->picUser->id, 'name' => $this->picUser->name]
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }
}
