<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Models\NotaryDeed;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Notarial Deed, as the list and detail endpoints return it (M6.2, D-120).
 *
 * ## What has no field here, and why
 *
 * **No parties.** The M6.2 brief asked for the Matter's participants in this payload.
 * Participation answers to `notary.matters.parties.view`, its own capability since
 * M4.5 (D-105) — embedding it would make `notary.deeds.view` a way to read who the
 * parties to a transaction are, which is exactly the escalation `Matter::documents()`
 * carries a warning about. The deed page asks the participation endpoint separately,
 * and a caller without that capability sees the deed and not the parties.
 *
 * **No tasks.** Same argument: tasks answer to `tasks.view` with their own Data
 * Scope, and `GET /api/v1/tasks?matter_id=…` already answers the question inside a
 * visibility-scoped query (M5.4). A second address for one question is two surfaces
 * that must be kept in step.
 *
 * **No attached document list.** The three pointers below are the deed's own fields
 * and it holds those ids legitimately; the Matter's wider document collection is
 * `GET /api/v1/documents?matter_id=…`, authorized by `documents.view` (M5.2).
 *
 * **No Party identity of any kind** — no NIK, no NPWP, nothing that could carry one.
 * Identity stays behind the surfaces that already authorize it (D-082).
 *
 * **No register entry and no protocol.** Neither table exists; a key for one would
 * invite a component to render something the API never sends.
 *
 * ## What is here
 *
 * The Matter appears as a **stub**, not an embedded resource: enough to say which
 * work the deed came out of, and not a way to read a Matter the caller could not open
 * directly. The three documents likewise.
 *
 * `deed_number` is displayed and never accepted on this resource's write paths — it
 * answers to `notary.deeds.number`. Because uniqueness is per Office, it does **not**
 * identify a deed on its own, which is why it is never the route key and why the
 * Office travels with it rather than for decoration.
 *
 * `is_read_only` is computed by the server rather than derived in the browser from
 * `status`, so the interface and the backend cannot disagree about what
 * `CLAUDE.md` section 29 means.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, with
 * status eligibility folded in, so no control is offered that the endpoint would
 * answer 422 to. They are not an authorization surface: every endpoint authorizes
 * again (D-113).
 *
 * @mixin NotaryDeed
 */
class NotaryDeedResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(NotaryDeed $resource, private readonly array $capabilities = [])
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
            'deed_number' => $this->deed_number,
            'deed_date' => $this->deed_date?->toDateString(),
            'deed_type_code' => $this->deed_type_code,
            'title' => $this->title,
            'status' => $this->status->value,
            'is_read_only' => $this->isReadOnly(),

            'office' => $this->whenLoaded('office', fn (): array => [
                'id' => $this->office->id,
                'code' => $this->office->code,
                'name' => $this->office->name,
            ]),

            'matter' => $this->whenLoaded('matter', fn (): array => [
                'id' => $this->matter->id,
                'matter_number' => $this->matter->matter_number,
                'title' => $this->matter->title,
                'domain' => $this->matter->domain->value,
                'project_id' => $this->matter->project_id,
            ]),

            // The deed's own pointers. Absent rather than null when not loaded, so a
            // list payload does not carry three empty keys per row.
            'draft_document' => $this->documentStub('draftDocument'),
            'final_document' => $this->documentStub('finalDocument'),
            'minuta_document' => $this->documentStub('minutaDocument'),

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->userStub('reviewer'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->userStub('approver'),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'finalized_by' => $this->userStub('finalizer'),

            // Canonical column, written by nothing in M6 (D-120). Exposed so the
            // interface can render a locked record if a later milestone ever writes
            // it, rather than needing a payload change then.
            'locked_at' => $this->locked_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            ...$this->capabilities,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentStub(string $relation): ?array
    {
        $document = $this->whenLoaded($relation);

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

    /**
     * @return array<string, mixed>|null
     */
    private function userStub(string $relation): ?array
    {
        $user = $this->whenLoaded($relation);

        if (! $user instanceof User) {
            return null;
        }

        return ['id' => $user->id, 'name' => $user->name];
    }
}
