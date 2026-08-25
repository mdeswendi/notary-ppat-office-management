/**
 * Land objects and their chains of title (M7.3, D-121).
 *
 * **Its own module, not an appendix to `ppat.ts`.** The M7.3 brief put these types in
 * the deed file; the repository gives each aggregate its own — `matter.ts`,
 * `matter-party.ts`, `document.ts`, `document-relation.ts` — and a Property is
 * genuinely separate: office-owned reference data that predates every Matter naming it
 * and answers to the `properties.*` capability family rather than `ppat.deeds.*`.
 */

/**
 * What kind of land object this is.
 *
 * **A closed list**, and the only vocabulary on this record that is.
 * `03_DATABASE_ERD.md` section 16 gives these four flat, with no hedging word, which
 * is why the database CHECKs them and why the interface may safely render a `<select>`.
 *
 * `APARTMENT_UNIT`, not `APARTMENT`: a stable machine code is only stable if copied
 * exactly (M7.1).
 */
export const PROPERTY_TYPES = ["LAND", "LAND_AND_BUILDING", "APARTMENT_UNIT", "OTHER"] as const;

export type PropertyType = (typeof PROPERTY_TYPES)[number];

/**
 * The kind of right held over the land.
 *
 * **A plain string, deliberately, and never a union.** The ERD says *"Right type
 * **may** use stable machine codes, **for example**"* before listing `HAK_MILIK`,
 * `HGB`, `HGU`, `HAK_PAKAI`, `STRATA_TITLE` and `OTHER` — *for example*, not *these are
 * the values*. A union type here would assert that Indonesian land law has six kinds of
 * right, which `11_LEGAL_REFERENCES.md` exists as a statutory register precisely
 * because nobody in this repository may decide (`CLAUDE.md` section 62).
 *
 * The options endpoint returns the six as **suggestions**, and the form renders them in
 * a `datalist` over a free-text input.
 */
export type RightType = string;

export type PropertyPartyStub = {
  id: string;
  display_name: string;
  party_type: string;
  is_archived: boolean;
  /** Whether the Party surfaces would open this record for this actor. Presentation only. */
  can_view_party: boolean;
};

/**
 * One current holder, as the Property payload summarises them.
 *
 * A name and a share — never Party identity (D-082). The full chain of title is its own
 * endpoint under its own capability.
 */
export type PropertyCurrentOwner = {
  id: string;
  party_id: string;
  display_name: string | null;
  ownership_percentage: number | null;
  effective_from: string | null;
};

/**
 * One land object as the API returns it.
 *
 * ## `current_owners` is plural, and `status` is absent
 *
 * **Plural**, because the M7 lock section 7.2 is explicit that a Property legitimately
 * has several current owners at once, each with a share. A singular `current_owner` —
 * which the M7.3 brief specified — would show one of two co-owners and silently drop
 * the other, and co-ownership is ordinary for Indonesian land.
 *
 * It is `null` rather than `[]` for a caller who does not hold
 * `properties.ownership.view`: reading a parcel is not reading its chain of title, and
 * the two are separate canonical capabilities. `null` says *"not shown to you"*, which
 * an empty array would not.
 *
 * **There is no `status` field**, because `properties.status` has no vocabulary in the
 * ERD and nothing writes it (D-121 section 12). Retirement is `is_archived`, computed
 * from `deleted_at` — structural, not invented vocabulary.
 *
 * **There is no `document_count`.** `property_documents` does not exist:
 * `DocumentRelationType` carries `party`, `project` and `matter` only and names this
 * junction as blocked, so a count of zero would be a lie about a table that has no rows
 * because it has none (O-046).
 *
 * The `can_*` flags are presentation hints computed from the real Policy, with record
 * state folded in. They are not an authorization surface: every endpoint authorizes
 * again (D-113).
 */
export type Property = {
  id: string;
  property_number: string | null;
  property_type: PropertyType;
  right_type: RightType;

  /** The legal identifier. Deliberately not unique — a certificate may be reissued. */
  certificate_number: string;
  certificate_date: string | null;

  land_area: number | null;
  building_area: number | null;

  measurement_letter_number: string | null;
  measurement_letter_date: string | null;

  address: string;
  village: string | null;
  district: string | null;
  city: string | null;
  province: string | null;
  postal_code: string | null;

  latitude: number | null;
  longitude: number | null;

  /** Structural retirement, from `deleted_at`. Not a status vocabulary. */
  is_archived: boolean;
  archived_at: string | null;

  office: { id: string; code: string; name: string } | null;

  /** Null when the caller does not hold `properties.ownership.view`. */
  current_owners: PropertyCurrentOwner[] | null;
  /** The arithmetic sum of the current shares. Never validated against 100. */
  current_ownership_total: number | null;

  matter_count?: number;

  created_at: string | null;
  created_by: { id: string; name: string } | null;
  updated_at: string | null;
  updated_by: { id: string; name: string } | null;

  can_update: boolean;
  can_archive: boolean;
  can_view_ownership: boolean;
  can_update_ownership: boolean;
};

/** A Property as the Matter section returns it, with the role it plays there. */
export type MatterProperty = Property & {
  /** Opaque, free text. The ERD calls its three values "Example role codes". */
  role_code: string | null;
};

export type PropertyListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  property_type?: PropertyType | "";
  right_type?: string;
  certificate_number?: string;
  city?: string;
  district?: string;
  province?: string;
  village?: string;
  /** Current holders only — which is what "properties this party owns" means. */
  owner_party_id?: string;
  matter_id?: string;
  /**
   * Correlates two junctions deep: `matter_properties` to `matters` to `project_id`.
   *
   * A filter rather than a nested route, for the reason D-118 gave — one question, one
   * surface. Documents, Tasks and both deed families answer their Project page the same
   * way.
   */
  project_id?: string;
  /** `""` active (default), `"1"` archived only, `"all"` both. */
  archived?: "" | "1" | "all";
};

export type PropertyListPage = {
  data: Property[];
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
 * **`property_number` is required and office-supplied.** M7.3 settled the M7 lock's
 * open question that way: the ERD gives the column no format, `CLAUDE.md` section 38
 * shows `PROP-000001` without a year — alone among the internal references it lists —
 * and an allocator would need a counter table this milestone has no migration for. The
 * software validates uniqueness within the Office and nothing else, which is the shape
 * `ppat.deeds.number` has.
 *
 * Office and `status` are refused outright: the first is the actor's own, the second
 * has no vocabulary at all.
 */
export type PropertyCreateInput = {
  property_number: string;
  property_type: PropertyType;
  right_type: string;
  certificate_number: string;
  certificate_date?: string | null;
  land_area?: number | null;
  building_area?: number | null;
  measurement_letter_number?: string | null;
  measurement_letter_date?: string | null;
  address: string;
  village?: string | null;
  district?: string | null;
  city?: string | null;
  province?: string | null;
  postal_code?: string | null;
};

/**
 * Everything the create form sends, minus the reference.
 *
 * `property_number` is absent because it is immutable once assigned (D-103): a
 * reference belongs to the record that received it, and the API answers 422 rather
 * than ignoring the field.
 */
export type PropertyUpdateInput = Partial<Omit<PropertyCreateInput, "property_number">>;

export type PropertyOptions = {
  data: {
    /** Closed list — safe to render as a `<select>`. */
    property_types: PropertyType[];
    /** Suggestions only — render as a `datalist` over free text. */
    right_type_examples: string[];
    /** Suggestions only, for `matter_properties.role_code`. */
    matter_role_examples: string[];
  };
};

/**
 * One link in a chain of title.
 *
 * **A closed link is not a deleted one.** Every row the office recorded appears,
 * current or ended, because that is what makes this a chain rather than a current state
 * somebody keeps editing (`CLAUDE.md` section 63).
 *
 * **There is no delete**, and the type reflects it: ending an ownership is stamping
 * `effective_until` and clearing `is_current`. `property_owners` carries no
 * `deleted_at` in the ERD, so a delete could only be a hard one, and hard-deleting a
 * link destroys the history the table exists to keep.
 *
 * `is_current` is a flag on **many** rows, not a pointer to one.
 */
export type PropertyOwner = {
  id: string;
  property_id: string;

  ownership_percentage: number | null;
  effective_from: string | null;
  effective_until: string | null;
  is_current: boolean;

  party: PropertyPartyStub | null;

  /** The transfer that produced this link. Null when the caller cannot reach it. */
  source_matter: {
    id: string;
    matter_number: string;
    title: string;
    domain: "NOTARY" | "PPAT";
  } | null;

  created_at: string | null;
  updated_at: string | null;

  can_update: boolean;
};

export type PropertyOwnerList = {
  data: PropertyOwner[];
  meta: {
    total: number;
    can_update: boolean;
    /** The arithmetic sum of the current shares. Shown, never judged. */
    current_ownership_total: number;
  };
};

/**
 * What the add-owner form sends.
 *
 * **`supersedes_current` decides which act this is.** `false` adds a co-owner beside
 * the existing holders; `true` records a transfer, closing the current links at
 * `effective_from`. Default `false`, because it is the choice that ends nobody's
 * recorded ownership — a wrong `true` silently writes an end date onto somebody's
 * title, while a wrong `false` leaves a list the office can see is wrong and fix.
 */
export type PropertyOwnerCreateInput = {
  party_id: string;
  ownership_percentage?: number | null;
  effective_from: string;
  effective_until?: string | null;
  is_current?: boolean;
  source_matter_id?: string | null;
  supersedes_current?: boolean;
};

export type PropertyOwnerUpdateInput = {
  ownership_percentage?: number | null;
  effective_from?: string;
  effective_until?: string | null;
  is_current?: boolean;
};

export type MatterPropertyList = {
  data: MatterProperty[];
  meta: {
    total: number;
    can_manage: boolean;
  };
};
