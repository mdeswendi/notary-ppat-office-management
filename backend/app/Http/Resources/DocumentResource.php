<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Document, as the list and detail endpoints return it (M5.2, D-117).
 *
 * **No file, no path, no checksum, ever.** The bytes travel through the download
 * endpoint after it authorizes; nothing here hands a client a way to reach them,
 * and no field of this payload changes what a download is allowed to do (D-114).
 *
 * **No Party identity of any kind.** A Document reaches Parties through
 * `party_documents`, and a Party stub here carries a display name and nothing
 * else — never NIK, NPWP or any other identifier, which stay behind the surfaces
 * that already authorize them (D-082, D-105).
 *
 * The related records appear as **stubs**, not embedded resources: enough to say
 * what a document is attached to and to link there, and no more. A stub is not a
 * way to read a record the caller could not open directly — it is a label for an
 * id this Document already legitimately holds.
 *
 * `versions` is present only on the detail endpoint, where the relation is loaded.
 * A list of documents does not carry every version of every row: that is an N+1
 * waiting to happen and a payload nobody reads.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, so
 * the interface asks the same question the backend will ask rather than
 * reimplementing Data Scope in TypeScript. They are not an authorization surface:
 * every endpoint authorizes again (D-113).
 *
 * **`can_download` is false for every sensitive document**, whatever the actor
 * holds, because D-115 keeps that surface closed until an audit store exists. The
 * flag reports the Policy's real answer rather than the capability, so the
 * interface offers exactly what the endpoint will allow — one truth, not two.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(Document $resource, private readonly array $capabilities = [])
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
            'document_number' => $this->document_number,
            'title' => $this->title,
            'document_type_code' => $this->document_type_code,
            'status' => $this->status->value,
            'is_sensitive' => $this->is_sensitive,

            'document_date' => $this->document_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'notes' => $this->notes,

            'office' => $this->relationLoaded('office') && $this->office !== null
                ? ['id' => $this->office->id, 'code' => $this->office->code, 'name' => $this->office->name]
                : null,

            'created_by' => $this->relationLoaded('creator') && $this->creator !== null
                ? ['id' => $this->creator->id, 'name' => $this->creator->name]
                : null,

            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_by' => $this->relationLoaded('archiver') && $this->archiver !== null
                ? ['id' => $this->archiver->id, 'name' => $this->archiver->name]
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Detail only. `whenLoaded` rather than a null default, so the key is
            // absent on a list instead of present-and-empty — a client cannot then
            // read "no versions" from a payload that simply did not carry them.
            'current_version' => $this->whenLoaded(
                'currentVersion',
                fn (): ?array => $this->currentVersion === null
                    ? null
                    : (new DocumentVersionResource($this->currentVersion, true))->toArray($request),
            ),

            'versions' => $this->whenLoaded(
                'versions',
                fn (): array => $this->versions
                    ->map(fn (DocumentVersion $version): array => (new DocumentVersionResource(
                        $version,
                        $version->getKey() === $this->current_version_id,
                    ))->toArray($request))
                    ->all(),
            ),

            'related' => [
                'parties' => $this->whenLoaded('parties', fn (): array => $this->parties
                    ->map(fn ($party): array => [
                        'id' => $party->id,
                        'party_type' => $party->party_type->value,
                        'display_name' => $party->display_name,
                    ])->all()),

                'projects' => $this->whenLoaded('projects', fn (): array => $this->projects
                    ->map(fn ($project): array => [
                        'id' => $project->id,
                        'project_number' => $project->project_number,
                        'title' => $project->title,
                    ])->all()),

                'matters' => $this->whenLoaded('matters', fn (): array => $this->matters
                    ->map(fn ($matter): array => [
                        'id' => $matter->id,
                        'matter_number' => $matter->matter_number,
                        'title' => $matter->title,
                        'domain' => $matter->domain->value,
                    ])->all()),
            ],

            ...$this->capabilities,
        ];
    }
}
