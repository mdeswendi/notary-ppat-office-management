<?php

namespace App\Http\Resources;

use App\Models\Party;
use App\Models\PpatWarkahItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a Warkah (M7.4, D-121).
 *
 * ## `has_document` is what replaces the status the ERD never defined
 *
 * `ppat_warkah_items.status` has **no canonical vocabulary** — the ERD names the column
 * and gives it no values, which is why M7.1 built no enum and why completeness counts
 * documents rather than statuses (O-041). The M7.4 brief proposed six values; an
 * item-status vocabulary *is* the verification rule, and that is open question three.
 *
 * So the payload carries the fact that is observable and needs no vocabulary: **whether
 * anything has been filed against this line.** It is the same fact
 * `PpatWarkah::computeCompleteness()` counts, so the list and the percentage cannot
 * disagree.
 *
 * The column is exposed as `status` anyway and is always null. Emitting it keeps the
 * concept visible for whoever answers question three, and a permanently-null field is
 * honest where a fabricated `MISSING` would not be.
 *
 * ## The documents are stubs, and reading one is a separate capability
 *
 * Enough to say which file satisfies the line, never a way to read a Document the
 * caller could not open directly. Opening answers to `documents.view` and downloading
 * to `documents.download`, each with its own Data Scope; a sensitive one additionally
 * answers to `documents.sensitive.download`, which has authorized a real download since
 * M8.1 built the audit store and closed D-115.
 *
 * `is_sensitive` travels so the interface can mark the row without the reader having to
 * open it to find out.
 *
 * ## The Party is a stub too, and carries no identity
 *
 * A display name and a type. **No NIK and no NPWP** — a Warkah line names a person; it
 * does not carry their identity documents (D-082). `can_view_party` is computed from
 * real Party visibility and is presentation only: a name the reader cannot open renders
 * as text rather than a link that answers 403, and the line itself always renders,
 * because it is the office's own checklist.
 *
 * @mixin PpatWarkahItem
 */
class PpatWarkahItemResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        PpatWarkahItem $resource,
        private readonly bool $canViewParty = false,
        private readonly array $capabilities = [],
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $party = $this->resource->party;

        return [
            'id' => $this->id,
            'warkah_id' => $this->warkah_id,

            // Stored, matched against nothing: what it would match is a requirement
            // template, and D-104 keeps those unbuilt.
            'requirement_code' => $this->requirement_code,

            // Bilingual database fields, not UI strings (`CLAUDE.md` section 10).
            'title_id' => $this->title_id,
            'title_en' => $this->title_en,

            // Canonical column with no vocabulary. Always null — see the class
            // docblock.
            'status' => $this->status,

            // What the interface actually shows, and what completeness counts.
            'has_document' => $this->resource->relationLoaded('documents')
                ? $this->resource->documents->isNotEmpty()
                : $this->resource->hasDocument(),

            'sequence_no' => (int) $this->sequence_no,
            'notes' => $this->notes,

            'party' => $party instanceof Party ? [
                'id' => $party->getKey(),
                'display_name' => $party->display_name,
                'party_type' => $party->party_type->value,
                'is_archived' => $party->deleted_at !== null,
                'can_view_party' => $this->canViewParty,
            ] : null,

            'documents' => $this->resource->relationLoaded('documents')
                ? $this->resource->documents->map(fn ($document): array => [
                    'id' => $document->getKey(),
                    'document_number' => $document->document_number,
                    'title' => $document->title,
                    'status' => $document->status->value,
                    'is_sensitive' => (bool) $document->is_sensitive,
                    'attached_at' => $document->pivot?->attached_at,
                ])->values()->all()
                : [],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }
}
