/**
 * An Individual as the ordinary list and detail endpoints return it.
 *
 * Note what has no type here: there is no `nik` or `npwp` field, because the
 * backend never sends one on these endpoints (D-082). Only masks and presence
 * flags exist, so no component can render a raw identifier by accident — the
 * type system refuses before review has to.
 */
export type Individual = {
  /** The Party ULID. One public identifier for the aggregate, not two. */
  id: string;
  full_name: string;
  prefix: string | null;
  suffix: string | null;
  display_name: string | null;
  primary_phone: string | null;
  primary_email: string | null;
  birth_place: string | null;
  birth_date: string | null;
  gender: string | null;
  occupation: string | null;
  nationality: string | null;
  marital_status: string | null;
  address: string | null;
  village: string | null;
  district: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;
  /** Server-computed. Never the raw value. */
  nik_masked: string | null;
  npwp_masked: string | null;
  has_nik: boolean;
  has_npwp: boolean;
  office: { id: string; code: string; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
};

/**
 * The sensitive identity surface — tier 1.
 *
 * Reaching it is not seeing the values, so this too carries only masks. The
 * `can_reveal_*` flags are presentation metadata telling the interface whether
 * to offer a reveal control; the reveal endpoint authorizes independently.
 */
export type IndividualIdentity = {
  id: string;
  nik_masked: string | null;
  npwp_masked: string | null;
  has_nik: boolean;
  has_npwp: boolean;
  can_reveal_nik: boolean;
  can_reveal_npwp: boolean;
};

/**
 * The one-shot result of an explicit reveal.
 *
 * Deliberately its own type rather than part of `IndividualIdentity`: a raw
 * value must never end up in the same object the detail query caches.
 */
export type RevealedIdentifier = {
  field: "nik" | "npwp";
  value: string | null;
};

export type IndividualListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
};

export type IndividualListPage = {
  data: Individual[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type IndividualCreateInput = {
  office_id: string;
  full_name: string;
  prefix?: string | null;
  suffix?: string | null;
  primary_phone?: string | null;
  primary_email?: string | null;
  birth_place?: string | null;
  birth_date?: string | null;
  gender?: string | null;
  occupation?: string | null;
  nationality?: string | null;
  marital_status?: string | null;
  address?: string | null;
  village?: string | null;
  district?: string | null;
  city?: string | null;
  province?: string | null;
  postal_code?: string | null;
};

export type IndividualUpdateInput = Omit<IndividualCreateInput, "office_id">;

/**
 * The identity mutation contract. Only the two sensitive fields — profile data
 * belongs to a different capability and a different endpoint.
 */
export type IndividualIdentityInput = {
  nik?: string | null;
  npwp?: string | null;
};

export type IndividualOptions = {
  offices: { id: string; code: string; name: string }[];
};
