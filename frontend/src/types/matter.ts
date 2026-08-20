import type { ProjectPriority } from "@/types/project";

/**
 * Which business domain a Matter belongs to (M4.4, D-101).
 *
 * The domain is a property of the **address**, not of a form field. Every Matter
 * screen knows which one it is showing because of the route it lives under, and
 * the API surfaces are separate for the same reason: the domain selects the
 * permission namespace on the backend, so a user never chooses it.
 */
export const MATTER_DOMAINS = ["NOTARY", "PPAT"] as const;
export type MatterDomain = (typeof MATTER_DOMAINS)[number];

/**
 * A Matter's business status.
 *
 * Stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 9. The interface translates them for display; the
 * code is what travels and what is stored (CLAUDE.md section 12).
 *
 * **Only three of these are reachable in M4** *(D-109)*, and the interface must
 * not pretend otherwise. A Matter starts `OPEN`, and the only transitions the
 * canonical registry authorizes are completion and cancellation — Matter has
 * `complete` and `cancel` capabilities but **no `change_status`**, unlike
 * Project. `IN_PROGRESS`, `WAITING`, `ON_HOLD` and `ARCHIVED` are vocabulary a
 * status filter can select on and a badge can render, and nothing in the product
 * can set them. There is deliberately no status dropdown.
 */
export const MATTER_STATUSES = [
  "OPEN",
  "IN_PROGRESS",
  "WAITING",
  "ON_HOLD",
  "COMPLETED",
  "CANCELLED",
  "ARCHIVED",
] as const;

export type MatterStatus = (typeof MATTER_STATUSES)[number];

export type MatterOffice = {
  id: string;
  code: string;
  name: string;
};

/** Enough to say which engagement the work belongs to, and no more. */
export type MatterProjectStub = {
  id: string;
  project_number: string;
  title: string;
};

/**
 * Both names travel so either locale renders without a second request, and
 * `is_active` so a retired classification is visibly retired.
 */
export type MatterServiceTypeStub = {
  id: string;
  code: string;
  name_id: string;
  name_en: string;
  is_active: boolean;
};

export type MatterPicStub = {
  id: string;
  name: string;
};

/**
 * A Matter as the list and detail endpoints return it.
 *
 * Note what has no type here, because the backend sends none of it: no
 * participant collection — `matter_parties` is M4.5 — and nothing workflow,
 * stage, deed, Warkah, property, or Party-identity shaped. A key for any of them
 * would invite a component to render something the API never sends.
 *
 * `matter_number` is **system-generated, immutable, and unique only within its
 * Office** (D-103). It is displayed and never submitted, and it does not identify
 * a Matter on its own — which is why routes address a Matter by its ULID and why
 * the Office travels with it.
 *
 * The `can_*` flags are backend-computed from the real Policy, so the interface
 * asks the same question the server will ask instead of reimplementing Data Scope
 * in TypeScript. They decide what is *offered*; every endpoint authorizes again.
 */
export type Matter = {
  id: string;
  matter_number: string;
  domain: MatterDomain;
  title: string;
  status: MatterStatus;
  priority: ProjectPriority | null;
  notes: string | null;
  opened_at: string | null;
  target_completion_date: string | null;
  completed_at: string | null;
  office: MatterOffice | null;
  project: MatterProjectStub | null;
  service_type: MatterServiceTypeStub | null;
  pic: MatterPicStub | null;
  created_at: string | null;
  updated_at: string | null;
  can_update?: boolean;
  can_assign?: boolean;
  can_complete?: boolean;
  can_cancel?: boolean;

  // Participation (M4.5, D-105). Two flags rather than one, because the two
  // codes are independent and `manage` does not imply `view` — an interface told
  // only "can manage" would have to guess whether to render the list it is
  // offering to edit.
  can_view_parties?: boolean;
  can_manage_parties?: boolean;
};

export type MatterListQuery = {
  page: number;
  per_page?: number;
  search: string;
  status: MatterStatus | "";
  priority: ProjectPriority | "";
  project_id?: string;
};

export type MatterListPage = {
  data: Matter[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

/**
 * The create payload.
 *
 * Ordinary fields only. Domain comes from the address, Office from the parent
 * Project, the reference from the allocator, the status from the system, and the
 * PIC from a separate capability — sending any of them is a 422 rather than a
 * silent no-op, so an interface cannot appear to accept a choice it never made.
 */
export type MatterCreateInput = {
  project_id: string;
  service_type_id?: string | null;
  title: string;
  priority?: ProjectPriority | null;
  opened_at?: string | null;
  target_completion_date?: string | null;
  notes?: string | null;
};

export type MatterUpdateInput = {
  service_type_id?: string | null;
  title?: string;
  priority?: ProjectPriority | null;
  opened_at?: string | null;
  target_completion_date?: string | null;
  notes?: string | null;
};

export type MatterAssignmentInput = {
  pic_user_id: string | null;
};

export type MatterAssigneeOptions = {
  data: { users: { id: string; name: string }[] };
};

export type MatterServiceTypeOptions = {
  data: {
    service_types: { id: string; code: string; name_id: string; name_en: string }[];
  };
};
