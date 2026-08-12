/**
 * The legal form of an organization.
 *
 * Stable codes, mirroring the backend enum exactly — seven values, transcribed
 * from `03_DATABASE_ERD.md` and never extended here. The interface translates
 * them for display; the code is what travels and what is stored (CLAUDE.md
 * section 12).
 */
export const COMPANY_ENTITY_TYPES = [
  "PT",
  "CV",
  "YAYASAN",
  "PERKUMPULAN",
  "KOPERASI",
  "FIRMA",
  "OTHER",
] as const;

export type CompanyEntityType = (typeof COMPANY_ENTITY_TYPES)[number];

/**
 * A Company as the ordinary list and detail endpoints return it.
 *
 * Note what has no type here: there is no `tax_id` field, because the backend
 * never sends one on these endpoints (D-082). Only the mask and a presence flag
 * exist, so no component can render a raw identifier by accident — the type
 * system refuses before review has to.
 *
 * Note also what is absent: any relationship collection. Directors,
 * commissioners, and shareholders are M2.4, and a type for them here would
 * invite a component to render something the API does not send.
 */
export type Company = {
  /** The Party ULID. One public identifier for the aggregate, not two. */
  id: string;
  legal_name: string;
  short_name: string | null;
  entity_type: CompanyEntityType | null;
  registration_number: string | null;
  /** Derived: short_name when present, otherwise legal_name (D-079). */
  display_name: string | null;
  primary_phone: string | null;
  primary_email: string | null;
  address: string | null;
  village: string | null;
  district: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  /** Server-computed. Never the raw value. */
  tax_id_masked: string | null;
  has_tax_id: boolean;
  office: { id: string; code: string; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
};

/**
 * The sensitive identity surface — tier 1.
 *
 * Reaching it is not seeing the value, so this too carries only a mask. The
 * `can_reveal_tax_id` flag is presentation metadata telling the interface
 * whether to offer a reveal control; the reveal endpoint authorizes
 * independently.
 */
export type CompanyIdentity = {
  id: string;
  tax_id_masked: string | null;
  has_tax_id: boolean;
  can_reveal_tax_id: boolean;
};

/**
 * The one-shot result of an explicit reveal.
 *
 * Deliberately its own type rather than part of `CompanyIdentity`: a raw value
 * must never end up in the same object the detail query caches.
 */
export type RevealedTaxId = {
  field: "tax_id";
  value: string | null;
};

export type CompanyListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
};

export type CompanyListPage = {
  data: Company[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type CompanyCreateInput = {
  office_id: string;
  legal_name: string;
  entity_type: CompanyEntityType;
  short_name?: string | null;
  registration_number?: string | null;
  primary_phone?: string | null;
  primary_email?: string | null;
  address?: string | null;
  village?: string | null;
  district?: string | null;
  city?: string | null;
  province?: string | null;
  postal_code?: string | null;
};

export type CompanyUpdateInput = Omit<CompanyCreateInput, "office_id">;

/**
 * The identity mutation contract. Only the sensitive field — profile data
 * belongs to a different capability and a different endpoint.
 */
export type CompanyIdentityInput = {
  tax_id?: string | null;
};

export type CompanyOptions = {
  offices: { id: string; code: string; name: string }[];
  entity_types: CompanyEntityType[];
};
