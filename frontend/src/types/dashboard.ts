/**
 * The Dashboard's payloads (M8.1, D-122).
 *
 * **`null` and `0` mean different things throughout, and the types say so.**
 * A `null` panel is one the actor holds no capability for; a `0` is a panel they
 * may see with nothing in it. Collapsing them in the UI would either invent a
 * zero for somebody not entitled to know, or imply a permission problem where the
 * office simply has no work outstanding.
 *
 * That is why every figure below is `number | null` rather than `number`, and why
 * the widgets check for `null` before rendering anything at all.
 */

import type { TaskStatus } from "@/types/task";

/** A count the actor may not see is `null`, never `0`. */
export type ScopedCount = number | null;

export interface DashboardStats {
  active_projects: ScopedCount;
  active_matters: ScopedCount;
  pending_reviews: ScopedCount;
  overdue_tasks: ScopedCount;
  total_deeds_this_month: ScopedCount;
}

export type TaskPriority = "LOW" | "NORMAL" | "HIGH" | "URGENT";

/**
 * A task as the Dashboard shows it.
 *
 * `status` reuses the Task surface's own union rather than widening to `string`,
 * so the shared `TaskStatusBadge` accepts it directly — one vocabulary, not two
 * that could drift.
 */
export interface DashboardTask {
  id: string;
  title: string;
  status: TaskStatus;
  priority: TaskPriority;
  due_at: string | null;
  is_overdue: boolean;
  matter?: { id: string; reference: string | null; title: string } | null;
  project?: { id: string; reference: string | null; title: string } | null;
}

export interface DashboardTaskBuckets {
  today: DashboardTask[];
  overdue: DashboardTask[];
  upcoming: DashboardTask[];
}

/**
 * One thing that is stalled.
 *
 * **`days_waiting` is reported, never thresholded.** The backend includes every
 * item that is actually waiting, actually under review, or actually past its due
 * date, and leaves the judgement to the reader — how long an office tolerates a
 * stalled Matter is an office policy nobody has written down.
 */
export interface NeedsAttentionItem {
  type: "MATTER_WAITING" | "DEED_PENDING_REVIEW" | "TASK_OVERDUE";
  domain?: string;
  id: string;
  reference: string | null;
  title: string;
  status: string;
  days_waiting: number | null;
}

export interface WorkloadItem {
  user_id: string;
  user_name: string;
  matter_count: number;
  task_count: number;
}

/**
 * One timeline entry.
 *
 * `description_key` is a translation key and `metadata` are its interpolation
 * values — the server never sends a rendered sentence, because choosing the
 * language is the client's job in a bilingual product (`CLAUDE.md` §6).
 */
export interface ActivityItem {
  id: string;
  activity_type: string;
  description_key: string;
  metadata: Record<string, string | number | null>;
  subject_type: string;
  subject_id: string;
  project_id: string | null;
  matter_id: string | null;
  actor?: { id: string; name: string } | null;
  created_at: string | null;
}

/** Deed counts keyed by status, per domain. `null` where the domain is unreadable. */
export type DeedStatusCounts = Record<string, number>;

export interface DashboardDeeds {
  notary: DeedStatusCounts | null;
  ppat: DeedStatusCounts | null;
}
