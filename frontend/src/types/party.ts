import type { CompanyEntityType } from "@/types/company";

/**
 * The two Party subtypes. Stable codes mirroring the backend enum; the interface
 * translates them for display and never sends a label (CLAUDE.md section 12).
 */
export const PARTY_TYPES = ["INDIVIDUAL", "COMPANY"] as const;

export type PartyType = (typeof PARTY_TYPES)[number];

export type PartyDirectoryOffice = {
  id: string;
  code: string;
  name: string;
};

/**
 * One row of the unified Party Directory (M2.5).
 *
 * Note what has no type here, because the backend sends none of it: no `nik`,
 * `npwp`, or `tax_id`; no mask; no fingerprint; no birth details; no
 * relationships. The directory is the widest-reach read surface in the Party
 * domain, which is exactly why it carries the least — and the type system
 * refuses to render what does not exist before review has to (D-082).
 *
 * `individual` and `company` are mutually exclusive and follow `party_type`.
 * They carry only the handful of ordinary fields that make a row recognizable.
 */
export type PartyDirectoryEntry = {
  /** The Party ULID — the same identifier the subtype detail routes take. */
  id: string;
  party_type: PartyType | null;
  display_name: string | null;
  primary_phone: string | null;
  primary_email: string | null;
  office: PartyDirectoryOffice | null;
  individual: { full_name: string } | null;
  company: {
    legal_name: string;
    short_name: string | null;
    entity_type: CompanyEntityType | null;
  } | null;
  created_at: string | null;
};

/**
 * Directory filters.
 *
 * Ordinary discovery data only. There is deliberately no identifier field: a
 * directory that answers "does this NIK exist" is the existence oracle the
 * Office-scoped duplicate rules exist to prevent (D-084).
 */
export type PartyDirectoryQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  party_type?: PartyType | "";
  office_id?: string;
};

export type PartyDirectoryPage = {
  data: PartyDirectoryEntry[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};
