<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Task, as the list and detail endpoints return it (M5.4, D-119).
 *
 * **Note what has no field here.** No comment collection on a list — that is an
 * N+1 waiting to happen and a payload nobody reads; comments arrive with the
 * detail endpoint, where the relation is loaded. **No Party identity of any kind**
 * (D-082), and no workflow: `workflow_stage_instance_id` is not a column, because
 * nothing raises tasks from stages yet (D-104).
 *
 * The related records appear as **stubs**, not embedded resources: enough to say
 * which engagement the work belongs to and to link there. A stub is not a way to
 * read a record the caller could not open directly — reaching a Task confers no
 * Project or Matter access, the symmetric statement of D-100.
 *
 * **`is_overdue` is computed on the server and sent, not derived in the browser.**
 * A client comparing `due_at` to its own clock would disagree with the server for
 * anybody whose machine is off, and two people looking at the same task would see
 * different answers. It is deliberately not a status — the ERD names five, and
 * overdue is a fact about a date rather than a state somebody set.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, so
 * the interface asks the same question the backend will ask rather than
 * reimplementing Data Scope in TypeScript. They are not an authorization surface:
 * every endpoint authorizes again (D-113).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(Task $resource, private readonly array $capabilities = [])
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority?->value,

            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),

            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->stub('completer'),

            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->id, 'code' => $this->office->code, 'name' => $this->office->name]
                : null,

            // Which engagement the work belongs to. Enough to label it, and no
            // more — a Project's own surface is where a Project is read.
            'project' => $this->relationLoaded('project') && $this->project !== null
                ? [
                    'id' => $this->project->id,
                    'project_number' => $this->project->project_number,
                    'title' => $this->project->title,
                ]
                : null,

            'matter' => $this->relationLoaded('matter') && $this->matter !== null
                ? [
                    'id' => $this->matter->id,
                    'matter_number' => $this->matter->matter_number,
                    'title' => $this->matter->title,
                    // The Matter's own domain, so the interface links to the right
                    // surface without ever guessing or sending one back (D-101).
                    'domain' => $this->matter->domain->value,
                ]
                : null,

            // The two predicates, made visible and kept apart: `created_by` is
            // OWN and never changes, `assigned_to` is ASSIGNED and moves with the
            // work.
            'created_by' => $this->stub('creator'),
            'assigned_to' => $this->stub('assignee'),
            'assigned_by' => $this->stub('assigner'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Detail only. `whenLoaded` rather than a null default, so the key is
            // absent on a list instead of present-and-empty — a client cannot then
            // read "no comments" from a payload that simply did not carry them.
            'comments' => $this->whenLoaded(
                'comments',
                fn (): array => TaskCommentResource::collection($this->comments)
                    ->toArray($request),
            ),

            ...$this->capabilities,
        ];
    }

    /**
     * A user stub: a name and an id, never a user-administration payload.
     *
     * @return array<string, string>|null
     */
    private function stub(string $relation): ?array
    {
        if (! $this->relationLoaded($relation) || $this->{$relation} === null) {
            return null;
        }

        return ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name];
    }
}
