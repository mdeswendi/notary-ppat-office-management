<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One archived Project, as the restore surface sees it (M3.3, D-093).
 *
 * **Deliberately narrower than {@see ProjectResource}.** This surface is reached
 * through `projects.restore`, not `projects.view`, and those are different
 * capabilities — a person who may put a record back is not thereby a person who
 * may read everything in it. So this carries enough to recognise the record and
 * decide whether to restore it, and stops: reference, title, status, Office, when
 * it was archived.
 *
 * No description, no dates beyond the archive moment, no PIC. If somebody needs
 * the full record they can restore it and then read it under `projects.view`,
 * which is the capability that governs reading.
 *
 * `status` is included precisely because it is **not** what archiving changed.
 * Business status `ARCHIVED` and a soft-deleted record are different states with
 * similar names, and showing the status here is what lets a reader see that an
 * archived record can still read `IN_PROGRESS`.
 *
 * @mixin Project
 */
class ArchivedProjectResource extends JsonResource
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
            'project_number' => $this->project_number,
            'title' => $this->title,

            // The business status the record still carries, untouched by
            // archiving.
            'status' => $this->status?->value,

            'office' => $this->relationLoaded('office') && $this->office !== null
                ? [
                    'id' => $this->office->id,
                    'code' => $this->office->code,
                    'name' => $this->office->name,
                ]
                : null,

            'archived_at' => $this->deleted_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }
}
