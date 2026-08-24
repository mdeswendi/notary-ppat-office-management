<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\NotaryMinuta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Minuta Akta filing record (M6.3, D-120).
 *
 * The Document appears as a **stub**, not an embedded resource: enough to name the
 * file and say whether it is sensitive, and not a way to read a Document the caller
 * could not open directly. Following it to its versions or its bytes goes through
 * `documents.view` and `documents.download` at their own addresses — a Minuta
 * capability is not a way to read what is filed.
 *
 * **`release_status`, `archived_at` and `archived_by` are exposed and always empty.**
 * They are canonical columns the ERD names; nothing in M6 writes any of them, because
 * the ERD gives `release_status` no vocabulary and the archive trigger is open
 * question four. They are serialized rather than hidden so the milestone that
 * eventually fills them needs no payload change — and so a reader can see the fields
 * exist and are unused rather than wondering whether they were dropped.
 *
 * The `can_*` flags are presentation hints computed from the real Policy. They are
 * not an authorization surface: every endpoint authorizes again (D-113).
 *
 * @mixin NotaryMinuta
 */
class NotaryMinutaResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(NotaryMinuta $resource, private readonly array $capabilities = [])
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
            'notary_deed_id' => $this->notary_deed_id,

            'document' => $this->documentStub(),

            'archive_location' => $this->archive_location,
            'volume_number' => $this->volume_number,
            'bundle_number' => $this->bundle_number,
            'notes' => $this->notes,

            // Canonical, unwritten. See the class docblock.
            'release_status' => $this->release_status,
            'archived_at' => $this->archived_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentStub(): ?array
    {
        $document = $this->whenLoaded('document');

        if (! $document instanceof Document) {
            return null;
        }

        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'title' => $document->title,
            'status' => $document->status->value,
            'is_sensitive' => $document->is_sensitive,
        ];
    }
}
