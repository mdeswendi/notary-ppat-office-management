/**
 * A PPAT Deed's lifecycle state (M7.2, D-121).
 *
 * Six stable codes mirroring the backend enum exactly. `03_DATABASE_ERD.md` gives
 * `ppat_deeds` **no status vocabulary of its own** — section 21 lists the column and
 * stops — so the M7 lock ruled that PPAT reuses the Notary lifecycle rather than
 * inventing a shorter one, so that the two domains' deed records answer the same
 * question the same way. The interface translates them for display; the code is what
 * travels and what is stored (`CLAUDE.md` section 12).
 */
export const PPAT_DEED_STATUSES = [
  "DRAFT",
  "UNDER_REVIEW",
  "APPROVED",
  "FINALIZED",
  "VOID",
  "SUPERSEDED",
] as const;

export type PpatDeedStatus = (typeof PPAT_DEED_STATUSES)[number];

/**
 * The four statuses some code path can actually produce.
 *
 * **`VOID` and `SUPERSEDED` are canonical vocabulary nothing reaches.** *"What
 * correction mechanisms are permitted after finalization?"* is open question nine in
 * `09_PPAT_WORKFLOW.md` section 6, and no `ppat.deeds.void` capability exists. They
 * stay in the union above because a deed written directly to the database could carry
 * one and the badge must still render it — but no filter offers them and no control
 * sets them, because a control that always fails is worse than none.
 */
export const REACHABLE_PPAT_DEED_STATUSES = [
  "DRAFT",
  "UNDER_REVIEW",
  "APPROVED",
  "FINALIZED",
] as const satisfies readonly PpatDeedStatus[];

export type PpatDeedMatterStub = {
  id: string;
  matter_number: string;
  title: string;
  domain: "NOTARY" | "PPAT";
  project_id: string | null;
};

export type PpatDeedDocumentStub = {
  id: string;
  document_number: string | null;
  title: string;
  status: string;
  is_sensitive: boolean;
};

export type PpatDeedUserStub = {
  id: string;
  name: string;
};

/**
 * One PPAT Deed as the API returns it.
 *
 * **One document pointer, not three.** A Notarial Deed carries a draft and a Minuta
 * beside its final file; `ppat_deeds` carries `final_document_id` alone (M7.1). The
 * PPAT deed's supporting material is the **Warkah**, which is its own aggregate with
 * its own family of `ppat.warkah.*` capabilities — M7.4 builds that surface, and a
 * deed capability is deliberately not a way to read which supporting legal documents
 * an office does or does not hold.
 *
 * **No parties, no tasks, no document collection.** Participation answers to
 * `ppat.matters.parties.view`, tasks to `tasks.view`, and the Matter's wider document
 * list to `documents.view` — each with its own Data Scope. The detail page asks those
 * endpoints separately, so a caller without one of those capabilities sees the deed
 * and not that section.
 *
 * **`deed_number` does not identify a deed.** It is unique only within an Office, so
 * `id` is the route key and the number is a displayed field.
 *
 * **`is_read_only` comes from the server**, not derived here from `status`, so the
 * interface and the backend cannot disagree about what `CLAUDE.md` section 29 means.
 *
 * The `can_*` flags are presentation hints computed from the real Policy, with status
 * eligibility folded in — so no control is offered that the endpoint would answer 422
 * to. They are not an authorization surface: every endpoint authorizes again (D-113).
 */
export type PpatDeed = {
  id: string;
  deed_number: string | null;
  deed_date: string | null;
  deed_type_code: string | null;
  title: string;
  status: PpatDeedStatus;
  is_read_only: boolean;

  office: { id: string; code: string; name: string } | null;
  matter: PpatDeedMatterStub | null;

  final_document: PpatDeedDocumentStub | null;

  reviewed_at: string | null;
  reviewed_by: PpatDeedUserStub | null;
  approved_at: string | null;
  approved_by: PpatDeedUserStub | null;
  finalized_at: string | null;
  finalized_by: PpatDeedUserStub | null;

  /** Written by nothing in M7 — see D-121. Rendered if a later milestone writes it. */
  locked_at: string | null;

  created_at: string | null;
  updated_at: string | null;

  can_update: boolean;
  can_review: boolean;
  can_approve: boolean;
  can_finalize: boolean;
  can_record_number: boolean;
};

export type PpatDeedListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: PpatDeedStatus | "";
  matter_id?: string;
  /**
   * Resolves through the Matter — a deed has no `project_id` of its own (O-037).
   *
   * A filter rather than a nested route, for the reason D-118 gave when it refused
   * `GET /{entity}/{id}/documents`: one question deserves one surface, and Documents,
   * Tasks and Notarial Deeds all answer the Project page the same way.
   */
  project_id?: string;
  deed_type_code?: string;
  deed_date_from?: string;
  deed_date_to?: string;
};

export type PpatDeedListPage = {
  data: PpatDeed[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

/**
 * What the create form sends.
 *
 * Office, status and the deed number are all decided elsewhere — Office by the parent
 * Matter, status by the backend, and the number by its own capability — and sending
 * any of them is a 422.
 */
export type PpatDeedCreateInput = {
  matter_id: string;
  title: string;
  deed_date?: string | null;
  deed_type_code?: string | null;
};

export type PpatDeedUpdateInput = {
  title?: string;
  deed_date?: string | null;
  deed_type_code?: string | null;
  final_document_id?: string | null;
};

export type PpatDeedOptions = {
  data: {
    /** Only the four reachable statuses; see `REACHABLE_PPAT_DEED_STATUSES`. */
    statuses: PpatDeedStatus[];
    /** PPAT Matters in the actor's own Office — the only ones creation accepts. */
    matters: { id: string; matter_number: string; title: string }[];
  };
};
