/**
 * Warkah — the supporting documents bound with a PPAT Deed (M7.4, D-121).
 *
 * **Its own module**, like `property.ts`: the aggregate answers to the
 * `ppat.warkah.*` capability family rather than `ppat.deeds.*`, and the repository
 * gives each aggregate its own types file.
 *
 * *Warkah* is Indonesian legal terminology and stays exactly as written
 * (`05_I18N_LEGAL_TERMINOLOGY.md`). It is never pluralised and never translated —
 * "Supporting Legal Documents" is a gloss for a reader, not a replacement.
 */

/**
 * The state of a bundle.
 *
 * **Five values, and two of them are unreachable.** `03_DATABASE_ERD.md` section 19
 * gives all five explicitly, so the database CHECKs them and a row could carry any —
 * but `FINALIZED` and `ARCHIVED` have no code path. `ppat.warkah.finalize` and
 * `ppat.warkah.archive` are canonical capabilities that stay registered and
 * unimplemented, because their *trigger* is open question eight: *"what are the
 * binding/archiving requirements for deeds and supporting Warkah?"* (D-064, O-041).
 *
 * They stay in the union because a bundle written directly to the database could carry
 * one and the badge must still render it — but no control sets either, because a
 * control that always fails is worse than none.
 */
export const WARKAH_STATUSES = [
  "INCOMPLETE",
  "UNDER_REVIEW",
  "COMPLETE",
  "FINALIZED",
  "ARCHIVED",
] as const;

export type WarkahStatus = (typeof WARKAH_STATUSES)[number];

/** The three a code path can produce. */
export const REACHABLE_WARKAH_STATUSES = [
  "INCOMPLETE",
  "UNDER_REVIEW",
  "COMPLETE",
] as const satisfies readonly WarkahStatus[];

/**
 * The two `PATCH .../warkah/status` accepts.
 *
 * `COMPLETE` is absent because it stamps `verified_at` / `verified_by` and answers to
 * `ppat.warkah.verify` on its own endpoint — accepting it through the update code
 * would let one capability perform an act a separate one was granted to control
 * (D-091).
 */
export const SETTABLE_WARKAH_STATUSES = [
  "INCOMPLETE",
  "UNDER_REVIEW",
] as const satisfies readonly WarkahStatus[];

export type WarkahPartyStub = {
  id: string;
  display_name: string;
  party_type: string;
  is_archived: boolean;
  /** Whether the Party surfaces would open this record for this actor. Presentation only. */
  can_view_party: boolean;
};

export type WarkahDocumentStub = {
  id: string;
  document_number: string | null;
  title: string;
  status: string;
  is_sensitive: boolean;
  attached_at: string | null;
};

/**
 * One line of a Warkah — a requirement the office wrote down.
 *
 * ## `has_document` is what replaces the status the ERD never defined
 *
 * `ppat_warkah_items.status` has **no canonical vocabulary**: the ERD names the column
 * and gives it no values, which is why M7.1 built no enum for it. The M7.4 brief
 * proposed `MISSING / RECEIVED / UNDER_REVIEW / VERIFIED / REJECTED / NOT_APPLICABLE`;
 * an item-status vocabulary *is* the verification rule, and that is open question three
 * (O-041).
 *
 * So a line's observable state is **whether anything has been filed against it** — the
 * same fact completeness counts, so the list and the percentage cannot disagree. The
 * `status` field is typed and always `null`, kept visible for whoever answers question
 * three.
 *
 * **`requirement_code` is stored and matched against nothing.** What it would match is
 * a requirement template, and D-104 keeps those unbuilt — so it is free text and
 * optional, not a code from a catalogue.
 *
 * **`title_id` and `title_en` are bilingual database fields**, not UI strings
 * (`CLAUDE.md` section 10). They are content an office writes and must never move to
 * the message files.
 */
export type WarkahItem = {
  id: string;
  warkah_id: string;

  requirement_code: string | null;
  title_id: string;
  title_en: string;

  /** Canonical column with no vocabulary. Always null — see the type docblock. */
  status: string | null;

  /** What the interface shows, and what completeness counts. */
  has_document: boolean;

  sequence_no: number;
  notes: string | null;

  party: WarkahPartyStub | null;
  documents: WarkahDocumentStub[];

  created_at: string | null;
  updated_at: string | null;

  can_manage: boolean;
  can_upload: boolean;
};

/**
 * One Warkah bundle.
 *
 * **`completeness_percentage` means one thing precisely**: every line this office
 * listed has a file against it. *Not* that the bundle is legally sufficient — the
 * mandatory Warkah composition per deed type is open question three, and no requirement
 * template drives the number (M7 lock section 8.2). The interface says so in words
 * rather than leaving it implied.
 *
 * **`status` is never derived from the percentage, in either direction.** `COMPLETE`
 * does not follow from 100% and 100% does not require `COMPLETE`.
 *
 * `finalized_at` and `finalized_by` are always null: canonical columns nothing writes.
 * They are typed so a later milestone that does write them needs no change here, and
 * the interface renders them as unset rather than hiding the concept.
 *
 * There is **no `can_finalize` and no `can_archive`**, because there is no route behind
 * either.
 */
export type Warkah = {
  id: string;
  ppat_deed_id: string;

  status: WarkahStatus;

  /** Arithmetic over the office's own checklist. See the type docblock. */
  completeness_percentage: number;
  items_count?: number;

  archive_location: string | null;
  notes: string | null;

  verified_at: string | null;
  verified_by: { id: string; name: string } | null;

  /** Canonical, unwritten in M7. */
  finalized_at: string | null;
  finalized_by: { id: string; name: string } | null;

  deed: {
    id: string;
    deed_number: string | null;
    title: string;
    status: string;
  } | null;

  created_at: string | null;
  updated_at: string | null;

  can_manage: boolean;
  can_verify: boolean;
  can_upload: boolean;
};

export type WarkahItemList = {
  data: WarkahItem[];
  meta: {
    total: number;
    can_manage: boolean;
    can_upload: boolean;
    /** Lines with at least one document — the numerator the percentage came from. */
    collected: number;
    completeness_percentage: number;
    /** `false` is an empty state, not a failure: the first line started starts the bundle. */
    warkah_started: boolean;
  };
};

export type WarkahListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: WarkahStatus | "";
  /** *"Which bundles are still short?"* — the question this surface exists for. */
  incomplete_only?: boolean;
};

export type WarkahListPage = {
  data: Warkah[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type WarkahOptions = {
  data: {
    /** The three a code path can produce. */
    statuses: WarkahStatus[];
    /** The two `PATCH .../status` accepts — `COMPLETE` answers to `verify`. */
    settable_statuses: WarkahStatus[];
    /** Storable, reached by nothing. Rendered if present, never offered as a control. */
    unreachable_statuses: WarkahStatus[];
  };
};

/**
 * What the add-line form sends.
 *
 * **`requirement_code` is optional**, which inverts the brief: it refers to no
 * catalogue, so requiring it would make an office invent one to get past validation.
 *
 * **`status` is absent entirely** — the column has no vocabulary, and the API refuses
 * the field on presence rather than ignoring it.
 */
export type WarkahItemCreateInput = {
  requirement_code?: string | null;
  title_id: string;
  title_en: string;
  party_id?: string | null;
  sequence_no?: number | null;
  notes?: string | null;
};

export type WarkahItemUpdateInput = {
  requirement_code?: string | null;
  title_id?: string;
  title_en?: string;
  /** Sent as `null` clears it; omitted leaves it alone. */
  party_id?: string | null;
  sequence_no?: number | null;
  notes?: string | null;
};
