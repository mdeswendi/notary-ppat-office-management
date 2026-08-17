<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Project, as the list and detail endpoints return it.
 *
 * **Note what has no field here.** No participant collection: `project_parties`
 * exists since M3.4, but it is its own nested surface with its own Resource
 * (D-098), so a Project payload never carries one. No Matter, workflow,
 * document, or deed data — none of it exists, and a key for it would invite a
 * component to render something the API never sends. No Party identity of any
 * kind: Project reaches a Party only through `project_parties`, and identity
 * stays behind the surfaces that already authorize it (D-082).
 *
 * `project_number` is displayed and never accepted: it is system-generated,
 * immutable once allocated, and — because uniqueness is per Office (D-096) — it
 * does **not** identify a Project on its own. The Office travels with it for that
 * reason, not for decoration.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, so
 * the interface asks the same question the backend will ask rather than
 * reimplementing Data Scope in TypeScript. They are not an authorization surface:
 * every endpoint authorizes again, and a client that lies to itself about these
 * gains nothing. Raw scope metadata is deliberately not exposed — that would be
 * handing the frontend the parts to build a second, divergent resolver.
 *
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(Project $resource, private readonly array $capabilities = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),

            // System-generated, immutable, and only unique within its Office.
            'project_number' => $this->project_number,

            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status?->value,
            'priority' => $this->priority?->value,

            'opened_at' => $this->opened_at?->toDateString(),
            'target_completion_date' => $this->target_completion_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            'office' => $this->relationLoaded('office') && $this->office !== null
                ? [
                    'id' => $this->office->id,
                    'code' => $this->office->code,
                    'name' => $this->office->name,
                ]
                : null,

            // The ASSIGNED predicate made visible. Name only — a Project surface
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
