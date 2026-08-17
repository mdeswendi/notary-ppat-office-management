/**
 * A Party as a Project participation shows it — a minimal, safe stub.
 *
 * **Nothing sensitive has a type here because the backend sends none of it**: no
 * NIK, no NPWP, no `tax_id`, and no masks either, since a mask is still a
 * statement about a sensitive value (D-082). Adding a field would invite a
 * component to render something the API never sends, and would make the
 * participation permission look like an identity permission.
 *
 * `can_view_party` is backend-computed from real Party visibility, per subtype —
 * `parties.view` for an Individual, `companies.view` for a Company, each at its
 * own Data Scope (D-098). It says whether following a link to the Party would
 * actually work, so the interface offers one exactly when it would.
 *
 * `is_archived` is stated rather than left blank: a retired Party stays listed
 * on a Project it took part in, and the interface says so instead of showing a
 * gap the reader has to interpret.
 */
export type ProjectPartyStub = {
  id: string;
  display_name: string;
  party_type: "INDIVIDUAL" | "COMPANY";
  is_archived: boolean;
  can_view_party: boolean;
};

/**
 * One Party's involvement in one Project.
 *
 * **`role_code` is an opaque classification, not a legal role.** No canonical
 * participant-role vocabulary exists — the ERD's example codes are labelled
 * examples — so it is a free string the office chooses, never a value the
 * interface validates against a catalogue or attaches meaning to (D-092).
 *
 * **`is_primary` is a designation and carries no cardinality rule.** Several
 * participants may be primary at once, and none has to be. The interface does
 * not enforce otherwise, because no domain authority has said otherwise.
 *
 * There is no `updated_at` and no end date: participation is current working
 * state rather than a historical ledger (D-098).
 */
export type ProjectParty = {
  id: string;
  role_code: string | null;
  is_primary: boolean;
  notes: string | null;
  created_at: string | null;
  party: ProjectPartyStub | null;
  can_manage: boolean;
};

export type ProjectPartyListPage = {
  data: ProjectParty[];
  meta: {
    total: number;
    can_manage: boolean;
  };
};

/** A Party that may be linked: same Office as the Project, active, and visible. */
export type ProjectPartyCandidate = {
  id: string;
  display_name: string;
  party_type: "INDIVIDUAL" | "COMPANY";
};

export type ProjectPartyCandidates = {
  parties: ProjectPartyCandidate[];
};

/**
 * What linking accepts. The Project comes from the address and the Office is
 * copied from it — neither is an input, and sending either is a 422.
 */
export type ProjectPartyCreateInput = {
  party_id: string;
  role_code?: string | null;
  is_primary?: boolean;
  notes?: string | null;
};

/**
 * What correcting accepts — the three relationship fields and nothing else.
 *
 * `party_id` is absent deliberately: re-pointing a participation at a different
 * Party is a different relationship, not an edit of this one, so the interface
 * removes and adds rather than offering a swap the backend would refuse.
 */
export type ProjectPartyUpdateInput = {
  role_code?: string | null;
  is_primary?: boolean;
  notes?: string | null;
};
