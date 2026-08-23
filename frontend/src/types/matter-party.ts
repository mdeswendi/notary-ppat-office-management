/**
 * A Party as a Matter participation shows it — a minimal, safe stub.
 *
 * **Nothing sensitive has a type here because the backend sends none of it**: no
 * NIK, no NPWP, no `tax_id`, and no masks either, since a mask is still a
 * statement about a sensitive value (D-082). D-105 states it as a rule about the
 * whole Matter domain: sensitive identity never enters `matters`,
 * `matter_parties`, browser storage, URLs, query keys, or logs. Adding a field
 * would invite a component to render something the API never sends, and would
 * make the participation permission look like an identity permission.
 *
 * `can_view_party` is backend-computed from real Party visibility, per subtype —
 * `parties.view` for an Individual, `companies.view` for a Company, each at its
 * own Data Scope. It says whether following a link to the Party would actually
 * work, so the interface offers one exactly when it would.
 *
 * `is_archived` is stated rather than left blank: a retired Party stays listed
 * on a Matter it took part in, and the interface says so instead of showing a
 * gap the reader has to interpret.
 */
export type MatterPartyStub = {
  id: string;
  display_name: string;
  party_type: "INDIVIDUAL" | "COMPANY";
  is_archived: boolean;
  can_view_party: boolean;
};

/**
 * One Party's involvement in one Matter.
 *
 * **`role_code` is an opaque classification, not a legal role.** No canonical
 * participant-role vocabulary exists — `03_DATABASE_ERD.md` offers `SELLER`,
 * `BUYER`, `SELLER_SPOUSE`, `DIRECTOR`, `WITNESS` and others and labels them
 * *examples* — so it is a free string the office chooses, never a value the
 * interface validates against a catalogue or attaches meaning to (D-105). There
 * is deliberately no dropdown.
 *
 * **This is not Project participation and is never derived from it** (D-105).
 * A Matter's participants are not inherited, copied, or kept in step with its
 * parent Project's, and no component may present them as though they were.
 *
 * There is no end date and no soft delete: participation is current working
 * state rather than a historical ledger. `updated_at` exists because the table
 * has one, and records *when* a correction happened, never who made it.
 *
 * `sequence_no` and `represented_by_party_id` are absent because they are
 * deferred pending domain validation — not optional, not null: not built.
 */
export type MatterParty = {
  id: string;
  role_code: string | null;
  notes: string | null;
  created_at: string | null;
  updated_at: string | null;
  party: MatterPartyStub | null;
  can_manage: boolean;
};

export type MatterPartyListPage = {
  data: MatterParty[];
  meta: {
    total: number;
    can_manage: boolean;
  };
};

/** A Party that may be linked: same Office as the Matter, active, and visible. */
export type MatterPartyCandidate = {
  id: string;
  display_name: string;
  party_type: "INDIVIDUAL" | "COMPANY";
};

export type MatterPartyCandidates = {
  parties: MatterPartyCandidate[];
};

/**
 * What linking accepts. The Matter comes from the address and the Office is
 * copied from it — neither is an input, and sending either is a 422.
 */
export type MatterPartyCreateInput = {
  party_id: string;
  role_code?: string | null;
  notes?: string | null;
};

/**
 * What correcting accepts — the two relationship fields and nothing else.
 *
 * `party_id` is absent deliberately: re-pointing a participation at a different
 * Party is a different relationship, not an edit of this one, so the interface
 * removes and adds rather than offering a swap the backend would refuse.
 */
export type MatterPartyUpdateInput = {
  role_code?: string | null;
  notes?: string | null;
};
