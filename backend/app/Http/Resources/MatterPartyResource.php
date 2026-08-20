<?php

namespace App\Http\Resources;

use App\Models\MatterParty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Matter participation (M4.5, D-105).
 *
 * **The Party appears as a minimal stub, and that is the whole of it.** No NIK,
 * no NPWP, no `tax_id`, no addresses, no contact details — and **no masks
 * specifically**, because a mask is still a statement about a sensitive value and
 * this surface has no business making one (D-082, the same reasoning
 * `CompanyManagementResource` and `ProjectPartyResource` follow). D-105 puts it
 * as a rule about the whole Matter domain: sensitive identity never enters
 * `matters`, `matter_parties`, workflow templates, stages, stage instances,
 * stage history, browser storage, URLs, query keys, or logs.
 *
 * `*.matters.parties.view` says who may know which Parties are involved in a
 * piece of legal work. It says nothing whatever about those Parties' identity
 * documents, and a participation resource that reached into the Individual or
 * Company for them would make the two permissions equivalent by accident.
 *
 * **`can_view_party` is computed from real Party visibility**, per subtype:
 * `parties.view` for an Individual, `companies.view` for a Company, each with
 * its own Data Scope, and **evaluated in bulk for the whole list** rather than
 * per row (D-105). It is presentation only — the Party endpoints authorize
 * again — and it exists so the interface offers a link exactly when following it
 * would work, instead of a link that answers 403.
 *
 * **A Party the actor cannot open still appears.** The participation is Matter
 * data recorded by the office; hiding the row would misreport the Matter's
 * composition to somebody authorized to read it, which is a worse failure than
 * declining to link onward. The same holds for an archived Party: it stays
 * listed, marked archived, and is simply not offered as a candidate for new
 * links.
 *
 * **`sequence_no` and `represented_by_party_id` are absent**, not null. Both are
 * deferred pending domain validation (D-105), and emitting them as nulls would
 * advertise concepts the product does not have.
 *
 * @mixin MatterParty
 */
class MatterPartyResource extends JsonResource
{
    /**
     * @param  array<string, bool>  $capabilities
     */
    public function __construct(
        MatterParty $resource,
        private readonly array $capabilities = [],
        private readonly bool $canViewParty = false,
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

            // Opaque relationship classification. Never interpreted, never
            // validated against a catalogue, and null where the office has not
            // classified the involvement (D-105).
            'role_code' => $this->role_code,

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'party' => $party === null ? null : [
                'id' => $party->getKey(),
                'display_name' => $party->display_name,
                'party_type' => $party->party_type->value,

                // Stated plainly rather than left as a blank the interface would
                // have to guess about.
                'is_archived' => $party->deleted_at !== null,

                // Whether the Party surfaces would actually open this record for
                // this actor. Presentation only.
                'can_view_party' => $this->canViewParty,
            ],

            ...$this->capabilities,
        ];
    }
}
