import type { ProjectPriority } from "@/types/project";

/**
 * A Task's lifecycle state (M5.4, D-119).
 *
 * Stable codes mirroring the backend enum exactly, transcribed from
 * `03_DATABASE_ERD.md` section 15. The interface translates them for display; the
 * code is what travels and what is stored (`CLAUDE.md` section 12).
 *
 * **Only three are settable by an ordinary edit.** `COMPLETED` and `CANCELLED`
 * answer to their own capabilities and their own endpoints, so the status control
 * offers the three below and the other two are buttons.
 */
export const TASK_STATUSES = ["OPEN", "IN_PROGRESS", "WAITING", "COMPLETED", "CANCELLED"] as const;

export type TaskStatus = (typeof TASK_STATUSES)[number];

/** What an ordinary edit may set. Mirrors `TaskStatus::settableByUpdate()`. */
export const TASK_EDITABLE_STATUSES = ["OPEN", "IN_PROGRESS", "WAITING"] as const;

/**
 * Priority is **`ProjectPriority`**, not a Task-specific enum.
 *
 * The ERD gives Task `LOW NORMAL HIGH URGENT`, which is exactly what Project and
 * Matter already use (D-095). A third identical union would be three places for
 * one vocabulary to drift — and note it is `NORMAL`, not `MEDIUM`.
 */
export type TaskPriority = ProjectPriority;

export type TaskUserStub = {
  id: string;
  name: string;
};

export type TaskProjectStub = {
  id: string;
  project_number: string;
  title: string;
};

export type TaskMatterStub = {
  id: string;
  matter_number: string;
  title: string;
  /** Decides which surface the link points at; the interface never guesses one. */
  domain: "NOTARY" | "PPAT";
};

export type TaskComment = {
  id: string;
  comment: string;
  created_at: string | null;
  author: TaskUserStub | null;
};

/**
 * One Task as the API returns it.
 *
 * **`created_by` and `assigned_to` are two different things and stay that way.**
 * `created_by` is the `OWN` predicate and never changes; `assigned_to` is
 * `ASSIGNED` and moves with the work. An actor may be granted either scope
 * separately, and they union when both are held (D-028, D-119).
 *
 * **`is_overdue` comes from the server.** A client comparing `due_at` to its own
 * clock would disagree with the backend for anybody whose machine is off, and two
 * people looking at the same task would see different answers.
 *
 * The `can_*` flags are presentation hints computed from the real Policy, with
 * status eligibility folded in — so no control is offered that the endpoint would
 * answer 422 to. They are not an authorization surface: every endpoint authorizes
 * again (D-113).
 */
export type Task = {
  id: string;
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: TaskPriority | null;

  due_at: string | null;
  is_overdue: boolean;

  completed_at: string | null;
  completed_by: TaskUserStub | null;

  office: { id: string; code: string; name: string } | null;
  project: TaskProjectStub | null;
  matter: TaskMatterStub | null;

  created_by: TaskUserStub | null;
  assigned_to: TaskUserStub | null;
  assigned_by: TaskUserStub | null;

  created_at: string | null;
  updated_at: string | null;

  /** Detail only. Absent on a list rather than present-and-empty. */
  comments?: TaskComment[];

  can_update: boolean;
  can_assign: boolean;
  can_complete: boolean;
  can_reopen: boolean;
  can_cancel: boolean;
  can_delete: boolean;
};

export type TaskListQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: TaskStatus | "";
  priority?: TaskPriority | "";
  assigned_to?: string;
  created_by?: string;
  project_id?: string;
  matter_id?: string;
  /** Conveniences over the same columns, not new state. */
  open?: "true" | "";
  overdue?: "true" | "";
  due_from?: string;
  due_to?: string;
  sort_by?: "due_at" | "created_at" | "title" | "priority" | "status";
  sort_direction?: "asc" | "desc";
};

export type TaskListPage = {
  data: Task[];
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
 * Office, status, creator and the completion pair are all decided by the backend,
 * and sending any of them is a 422.
 */
export type TaskCreateInput = {
  title: string;
  description?: string | null;
  priority?: TaskPriority | null;
  due_at?: string | null;
  project_id?: string | null;
  matter_id?: string | null;
  /** Only accepted from an actor holding `tasks.assign`. */
  assigned_to?: string | null;
};

export type TaskUpdateInput = {
  title?: string;
  description?: string | null;
  priority?: TaskPriority | null;
  due_at?: string | null;
  status?: (typeof TASK_EDITABLE_STATUSES)[number];
};

export type TaskOptions = {
  data: {
    statuses: TaskStatus[];
    settable_statuses: TaskStatus[];
    priorities: TaskPriority[];
    /** Active colleagues in the actor's own Office — the only valid assignees. */
    assignees: TaskUserStub[];
  };
};
