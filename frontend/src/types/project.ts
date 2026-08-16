/**
 * A Project's business status.
 *
 * Stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 7. The interface translates them for display; the
 * code is what travels and what is stored (CLAUDE.md section 12).
 *
 * **There is no transition rule here, deliberately.** No canonical document says
 * which status may follow which, so the frontend offers all of them and the
 * backend authorizes *who* may change status rather than *which* change is legal
 * (D-091). A dropdown that hid options would be inventing a matrix.
 */
export const PROJECT_STATUSES = [
  "OPEN",
  "IN_PROGRESS",
  "WAITING",
  "ON_HOLD",
  "COMPLETED",
  "CANCELLED",
  "ARCHIVED",
] as const;

export const PROJECT_PRIORITIES = ["LOW", "NORMAL", "HIGH", "URGENT"] as const;

export type ProjectStatus = (typeof PROJECT_STATUSES)[number];
export type ProjectPriority = (typeof PROJECT_PRIORITIES)[number];

export type ProjectOffice = {
  id: string;
  code: string;
  name: string;
};

/**
 * A Project as the list and detail endpoints return it.
 *
 * Note what has no type here, because the backend sends none of it: no
 * participant collection — `project_parties` is M3.4 — and nothing Matter,
 * workflow, document, or deed shaped. A key for any of them would invite a
 * component to render something the API never sends.
 *
 * `project_number` is **system-generated, immutable, and unique only within its
 * Office** (D-096). It is displayed and never submitted, and it does not identify
 * a Project on its own — which is why the Office travels with it.
 *
 * The `can_*` flags are backend-computed from the real Policy, so the interface
 * asks the same question the server will ask instead of reimplementing Data
 * Scope. They are presentation hints: every endpoint authorizes again.
 */
export type Project = {
  id: string;
  project_number: string;
  title: string;
  description: string | null;
  status: ProjectStatus | null;
  priority: ProjectPriority | null;
  opened_at: string | null;
  target_completion_date: string | null;
  completed_at: string | null;
  office: ProjectOffice | null;
  /** The person in charge — the ASSIGNED Data Scope predicate. Name only. */
  pic: { id: string; name: string } | null;
  created_at: string | null;
  updated_at: string | null;
  can_update: boolean;
  can_assign: boolean;
  can_change_status: boolean;
  can_archive: boolean;
};

/**
 * An archived Project, as the restore surface sees it.
 *
 * Deliberately narrower than {@link Project}: this surface answers to
 * `projects.restore`, not `projects.view`, and somebody who may put a record back
 * is not thereby somebody who may read everything in it (D-093).
 *
 * `status` is present precisely because archiving did **not** change it — an
 * archived record can still read `IN_PROGRESS`, and showing it is what makes the
 * difference between the two states visible.
 */
export type ArchivedProject = {
  id: string;
  project_number: string;
  title: string;
  status: ProjectStatus | null;
  office: ProjectOffice | null;
  archived_at: string | null;
  can_restore: boolean;
};

export type ProjectListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: ProjectStatus | "";
  priority?: ProjectPriority | "";
};

type Page<T> = {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ProjectListPage = Page<Project>;
export type ArchivedProjectListPage = Page<ArchivedProject>;

/**
 * What create accepts — ordinary fields only.
 *
 * No `office_id`: a Project is created in the actor's own Office and there is no
 * selector, because `ALL` is cross-office reach and never cross-office creation
 * (D-097). No `project_number`, `status`, or `pic_user_id` either: the first is
 * allocated server-side, and the other two answer to their own capabilities. The
 * backend refuses all of them outright rather than ignoring them.
 */
export type ProjectCreateInput = {
  title: string;
  description?: string | null;
  priority?: ProjectPriority | null;
  opened_at?: string | null;
  target_completion_date?: string | null;
};

/**
 * What ordinary update accepts. Still no status and no PIC — each has its own
 * endpoint and its own permission (D-091).
 */
export type ProjectUpdateInput = ProjectCreateInput & {
  completed_at?: string | null;
};

/** `null` means unassign, and must be sent explicitly. */
export type ProjectAssignmentInput = {
  pic_user_id: string | null;
};

export type ProjectStatusInput = {
  status: ProjectStatus;
};

/**
 * A candidate for the person in charge.
 *
 * Two fields, from a narrow endpoint authorized by `projects.assign` on the
 * Project — not a User API. Same Office and active only, which is the same rule
 * the assignment itself enforces.
 */
export type ProjectAssignee = {
  id: string;
  name: string;
};

export type ProjectAssigneeOptions = {
  users: ProjectAssignee[];
};
