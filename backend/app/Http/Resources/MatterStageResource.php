<?php

namespace App\Http\Resources;

use App\Models\MatterStageInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stage of a running Matter workflow (M4.7, D-112).
 *
 * **Names come from the snapshot, never from the template.** `stage_name_id` and
 * `stage_name_en` are read off the instance's own copied columns, so a Matter
 * started last month still displays the names its template carried then — which
 * is the requirement of `CLAUDE.md` section 18 and the whole point of the
 * snapshot (D-104). Reading through the `stage` relation here would quietly undo
 * it.
 *
 * The wire names drop `_snapshot_`: the client has no other source for a stage
 * name, so the distinction is one the backend has to keep and the interface does
 * not. **The underlying columns keep it**, because there a reader must not mistake
 * `stage_name_snapshot_id` for a foreign key.
 *
 * **`assigned_user_id` is exposed as a name, not an authorization signal.** A
 * stage assignee has no Matter reach (D-100), so nothing in the interface may
 * treat this as permission to do anything.
 *
 * @mixin MatterStageInstance
 */
class MatterStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignee = $this->resource->relationLoaded('assignee') ? $this->resource->assignee : null;

        return [
            'id' => $this->id,
            'stage_code' => $this->stage_code,

            // The snapshot, both locales, so either renders without a second
            // request and neither depends on the template still saying so.
            'stage_name_id' => $this->stage_name_snapshot_id,
            'stage_name_en' => $this->stage_name_snapshot_en,

            'sequence_no' => $this->sequence_no,
            'status' => $this->status->value,

            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            // Operational information. Never a capability.
            'assignee' => $assignee === null
                ? null
                : ['id' => $assignee->getKey(), 'name' => $assignee->name],

            // Recorded, never yet written: M4.7 ships no approval act (D-112).
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
