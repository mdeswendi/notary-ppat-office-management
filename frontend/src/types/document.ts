/**
 * A Document's lifecycle state (M5.2, D-117).
 *
 * Stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 13. The interface translates them for display; the
 * code is what travels and what is stored (`CLAUDE.md` section 12).
 *
 * **Only four of these are reachable in M5.2, and the interface must not pretend
 * otherwise.** Upload creates `RECEIVED`; verification reaches `VERIFIED`;
 * archiving reaches `ARCHIVED`. `DRAFT` remains a valid stored value that nothing
 * currently produces, and `UNDER_REVIEW`, `FINAL` and `VOID` are vocabulary a
 * status filter can select on and a badge can render — with nothing in the product
 * able to set them. There is deliberately no status dropdown; the two acts that
 * change a status are their own buttons answering to their own capabilities.
 */
export const DOCUMENT_STATUSES = [
  "DRAFT",
  "RECEIVED",
  "UNDER_REVIEW",
  "VERIFIED",
  "FINAL",
  "ARCHIVED",
  "VOID",
] as const;

export type DocumentStatus = (typeof DOCUMENT_STATUSES)[number];

export type DocumentOffice = {
  id: string;
  code: string;
  name: string;
};

export type DocumentUserStub = {
  id: string;
  name: string;
};

/**
 * One uploaded file.
 *
 * **`storage_path`, `stored_filename` and `checksum_sha256` are absent from this
 * type because the API never sends them** (D-114). A path would invite a client to
 * try it; a checksum would invite a client to treat its own digest as the server's
 * agreement. Neither belongs in a browser.
 */
export type DocumentVersion = {
  id: string;
  version_number: number;
  original_filename: string;
  mime_type: string;
  file_size: number;
  uploaded_at: string | null;
  uploaded_by: DocumentUserStub | null;
  is_current: boolean;
};

/** Enough to say what a document is attached to, and to link there. */
export type DocumentPartyStub = {
  id: string;
  party_type: "INDIVIDUAL" | "COMPANY";
  display_name: string;
};

export type DocumentProjectStub = {
  id: string;
  project_number: string;
  title: string;
};

export type DocumentMatterStub = {
  id: string;
  matter_number: string;
  title: string;
  domain: "NOTARY" | "PPAT";
};

export type DocumentRelations = {
  parties?: DocumentPartyStub[];
  projects?: DocumentProjectStub[];
  matters?: DocumentMatterStub[];
};

/**
 * One Document as the API returns it.
 *
 * The `can_*` flags are **presentation hints computed from the real Policy**, so
 * the interface asks the same question the backend will ask. They are not an
 * authorization surface: every endpoint authorizes again (D-113).
 *
 * **`can_download` is false for every sensitive document**, whatever the actor
 * holds, because no sensitive-download surface ships before an audit store exists
 * (D-115). The flag reports the endpoint's real answer, so the interface never
 * offers a button that would 403.
 */
export type Document = {
  id: string;
  document_number: string;
  title: string;
  document_type_code: string | null;
  status: DocumentStatus;
  is_sensitive: boolean;

  document_date: string | null;
  expiry_date: string | null;
  notes: string | null;

  office: DocumentOffice | null;
  created_by: DocumentUserStub | null;

  archived_at: string | null;
  archived_by: DocumentUserStub | null;

  created_at: string | null;
  updated_at: string | null;

  /** Detail only. Absent on a list rather than present-and-empty. */
  current_version?: DocumentVersion | null;
  versions?: DocumentVersion[];

  related: DocumentRelations;

  can_update: boolean;
  can_upload: boolean;
  can_download: boolean;
  can_verify: boolean;
  can_archive: boolean;
  can_delete: boolean;
};

export type DocumentListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: DocumentStatus | "";
  document_type_code?: string;
  is_sensitive?: "true" | "false" | "";
  party_id?: string;
  project_id?: string;
  matter_id?: string;
  sort_by?: "created_at" | "document_number" | "title";
  sort_direction?: "asc" | "desc";
};

export type DocumentListPage = {
  data: Document[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

/**
 * What the upload form sends.
 *
 * The file travels as `multipart/form-data`; everything else is ordinary
 * metadata. Office, reference, status and the version pointer are all decided by
 * the backend, and sending any of them is a 422.
 */
export type DocumentUploadInput = {
  title: string;
  document_type_code?: string;
  is_sensitive?: boolean;
  document_date?: string;
  expiry_date?: string;
  notes?: string;
  file: File;
  related_to?: {
    party_id?: string;
    project_id?: string;
    matter_id?: string;
  };
};

export type DocumentUpdateInput = {
  title?: string;
  document_type_code?: string | null;
  is_sensitive?: boolean;
  document_date?: string | null;
  expiry_date?: string | null;
  notes?: string | null;
};

/**
 * Form metadata.
 *
 * **`document_types` are suggestions, not a catalogue.** `document_type_code` is
 * opaque and nothing validates against this list (D-115, D-116) — an office that
 * files something it does not name may type it, and the request accepts it. The
 * control that renders it must therefore allow free text.
 */
export type DocumentOptions = {
  data: {
    document_types: string[];
    statuses: DocumentStatus[];
    mime_types: string[];
    max_upload_kilobytes: number;
  };
};
