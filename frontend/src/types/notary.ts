/**
 * A Notarial Deed's lifecycle state (M6.2, D-120).
 *
 * Six stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 17. The interface translates them for display; the
 * code is what travels and what is stored (`CLAUDE.md` section 12).
 */
export const NOTARY_DEED_STATUSES = [
  "DRAFT",
  "UNDER_REVIEW",
  "APPROVED",
  "FINALIZED",
  "VOID",
  "SUPERSEDED",
] as const;

export type NotaryDeedStatus = (typeof NOTARY_DEED_STATUSES)[number];

/**
 * The four statuses some code path can actually produce.
 *
 * **`VOID` and `SUPERSEDED` are canonical vocabulary nothing reaches.** The
 * correction mechanisms that would produce them are an open domain question
 * (`08_NOTARY_WORKFLOW.md` section 6), and no `notary.deeds.void` capability exists.
 * They stay in the union above because a deed written directly to the database could
 * carry one and the badge must still render it — but no filter offers them and no
 * control sets them, because a control that always fails is worse than none.
 */
export const REACHABLE_DEED_STATUSES = [
  "DRAFT",
  "UNDER_REVIEW",
  "APPROVED",
  "FINALIZED",
] as const satisfies readonly NotaryDeedStatus[];

export type NotaryDeedMatterStub = {
  id: string;
  matter_number: string;
  title: string;
  domain: "NOTARY" | "PPAT";
  project_id: string | null;
};

export type NotaryDeedDocumentStub = {
  id: string;
  document_number: string | null;
  title: string;
  status: string;
  is_sensitive: boolean;
};

export type NotaryDeedUserStub = {
  id: string;
  name: string;
};

/**
 * One Notarial Deed as the API returns it.
 *
 * **No parties, no tasks, no document collection.** Participation answers to
 * `notary.matters.parties.view`, tasks to `tasks.view`, and the Matter's wider
 * document list to `documents.view` — each with its own Data Scope. The detail page
 * asks those endpoints separately, so a caller without one of those capabilities
 * sees the deed and not that section.
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
export type NotaryDeed = {
  id: string;
  deed_number: string | null;
  deed_date: string | null;
  deed_type_code: string | null;
  title: string;
  status: NotaryDeedStatus;
  is_read_only: boolean;

  office: { id: string; code: string; name: string } | null;
  matter: NotaryDeedMatterStub | null;

  draft_document: NotaryDeedDocumentStub | null;
  final_document: NotaryDeedDocumentStub | null;
  minuta_document: NotaryDeedDocumentStub | null;

  reviewed_at: string | null;
  reviewed_by: NotaryDeedUserStub | null;
  approved_at: string | null;
  approved_by: NotaryDeedUserStub | null;
  finalized_at: string | null;
  finalized_by: NotaryDeedUserStub | null;

  /** Written by nothing in M6 — see D-120. Rendered if a later milestone writes it. */
  locked_at: string | null;

  created_at: string | null;
  updated_at: string | null;

  can_update: boolean;
  can_review: boolean;
  can_approve: boolean;
  can_finalize: boolean;
  can_record_number: boolean;
};

export type NotaryDeedListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: NotaryDeedStatus | "";
  matter_id?: string;
  deed_type_code?: string;
  deed_date_from?: string;
  deed_date_to?: string;
};

export type NotaryDeedListPage = {
  data: NotaryDeed[];
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
 * Office, status and the deed number are all decided elsewhere — Office by the
 * parent Matter, status by the backend, and the number by its own capability — and
 * sending any of them is a 422.
 */
export type NotaryDeedCreateInput = {
  matter_id: string;
  title: string;
  deed_date?: string | null;
  deed_type_code?: string | null;
};

export type NotaryDeedUpdateInput = {
  title?: string;
  deed_date?: string | null;
  deed_type_code?: string | null;
  draft_document_id?: string | null;
  final_document_id?: string | null;
  minuta_document_id?: string | null;
};

export type NotaryDeedOptions = {
  data: {
    /** Only the four reachable statuses; see `REACHABLE_DEED_STATUSES`. */
    statuses: NotaryDeedStatus[];
    /** Notary Matters in the actor's own Office — the only ones creation accepts. */
    matters: { id: string; matter_number: string; title: string }[];
  };
};
