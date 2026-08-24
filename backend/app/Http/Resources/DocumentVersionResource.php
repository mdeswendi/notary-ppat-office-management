<?php

namespace App\Http\Resources;

use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One version of a Document (M5.2, D-117).
 *
 * **Three fields are absent and their absence is the point**: `storage_path`,
 * `stored_filename` and `checksum_sha256`. The model hides the first two, and this
 * resource omits all three.
 *
 * A path invites a client to try it, and the next person to add a disk with
 * `serve => true` would turn a leaked path into a live download (D-114). The
 * checksum is the opposite problem: it is not secret, it is *evidence*, and an API
 * that hands it out invites a client to treat a digest it computed itself as proof
 * the server agreed. Integrity checking belongs to the office, on the server,
 * against the disk — which is what `DocumentStorage::matchesChecksum()` is for.
 *
 * `is_current` is computed from the parent Document's pointer rather than stored,
 * because M5.1 replaced the boolean with `documents.current_version_id` (D-116).
 * It is presentation: the interface needs to mark one row in a list.
 *
 * @mixin DocumentVersion
 */
class DocumentVersionResource extends JsonResource
{
    public function __construct(DocumentVersion $resource, private readonly bool $isCurrent = false)
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
            'version_number' => $this->version_number,

            // The name the uploader knew. Kept so a download can restore it, and
            // shown so a person can tell two versions apart.
            'original_filename' => $this->original_filename,

            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,

            'uploaded_at' => $this->uploaded_at?->toIso8601String(),

            // Name only — a document surface is not a user-administration surface.
            'uploaded_by' => $this->relationLoaded('uploader') && $this->uploader !== null
                ? ['id' => $this->uploader->id, 'name' => $this->uploader->name]
                : null,

            'is_current' => $this->isCurrent,
        ];
    }
}
