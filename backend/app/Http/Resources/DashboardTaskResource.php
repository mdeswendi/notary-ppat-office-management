<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One task as the Dashboard needs it (M8.1).
 *
 * **Deliberately narrower than {@see TaskResource}.** A dashboard row is read at
 * a glance and linked from; it needs a title, when it is due, how urgent it is,
 * and what it belongs to. It does not need the description, the assignment
 * history, or the capability flags, and sending them would put three panels'
 * worth of payload on a page that renders five fields.
 *
 * `matter` and `project` are flattened to a reference and a title rather than
 * nested resources, because the widget shows one line of context and following
 * it is a link, not an expansion.
 *
 * @mixin Task
 */
class DashboardTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_at' => $this->due_at?->toIso8601String(),

            // Whether it is late is computed at read time, never stored — the
            // position `Task::isOverdue()` has taken since M5.4.
            'is_overdue' => $this->isOverdue(),

            'matter' => $this->whenLoaded('matter', fn (): ?array => $this->matter === null ? null : [
                'id' => $this->matter->id,
                'reference' => $this->matter->matter_number,
                'title' => $this->matter->title,
            ]),

            'project' => $this->whenLoaded('project', fn (): ?array => $this->project === null ? null : [
                'id' => $this->project->id,
                'reference' => $this->project->project_number,
                'title' => $this->project->title,
            ]),
        ];
    }
}
