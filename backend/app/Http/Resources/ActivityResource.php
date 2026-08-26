<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry on a timeline (M8.1, D-123).
 *
 * ## `description_key` ships, the sentence does not
 *
 * The row carries a translation key and the values that key interpolates.
 * Rendering it is the frontend's job, because `CLAUDE.md` section 6 puts static
 * user-facing text in `messages/{id,en}.json` — a server that returned
 * *"Rina menyetujui akta"* would have picked a language for a bilingual product,
 * and would put Indonesian legal wording in a PHP string where nobody looking for
 * it would find it.
 *
 * ## `subject_type` is shortened to a domain word
 *
 * `App\Models\NotaryDeed` is an implementation detail and a small disclosure
 * about how the server is built. The client gets `NotaryDeed`, which is what it
 * needs to choose an icon and build a link.
 *
 * ## What is not here
 *
 * No `office_id` — the reader already knows which Office they are looking at, and
 * the value is a scoping key rather than something a timeline shows. No
 * `metadata` key that was not put there deliberately by the recorder, which
 * strips identity values before they are stored at all.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_type' => $this->activity_type->value,
            'description_key' => $this->description_key,
            'metadata' => $this->metadata ?? [],

            'subject_type' => class_basename($this->subject_type),
            'subject_id' => $this->subject_id,

            'project_id' => $this->project_id,
            'matter_id' => $this->matter_id,

            // Null where the event had no actor, or where the actor's record has
            // since gone. The row survives either way — that is the point of a
            // timeline.
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
