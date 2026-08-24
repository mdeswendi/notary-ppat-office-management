<?php

namespace App\Http\Resources;

use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One remark on a Task (M5.4, D-119).
 *
 * **No `updated_at`, deliberately.** A comment is written once and the model
 * refuses an edit; sending a field that records when it changed would advertise a
 * mutation that cannot happen — the same reasoning that kept `updated_at` off
 * `document_versions` (D-116). The column exists because the ERD lists it and
 * Eloquent maintains it; the payload does not carry it.
 *
 * `author` is a **stub**: a name and an id. A task's conversation is not a
 * user-administration surface.
 *
 * @mixin TaskComment
 */
class TaskCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),

            'author' => $this->relationLoaded('author') && $this->author !== null
                ? ['id' => $this->author->id, 'name' => $this->author->name]
                : null,
        ];
    }
}
