"use client";

import { AlertTriangle } from "lucide-react";
import { useTranslations } from "next-intl";

import type { TaskPriority, TaskStatus } from "@/types/task";

/**
 * Status, priority and overdue, rendered as labelled badges.
 *
 * **Status must not rely on colour alone** (`CLAUDE.md` section 49): every badge
 * carries its translated text, and the tint is a secondary cue rather than the
 * information itself. A reader who cannot distinguish the tints still reads the
 * status.
 *
 * The tints stay muted on purpose. This is a professional office system, not a
 * dashboard — section 39 rules out the traffic-light palette a status chip usually
 * attracts, and a task being `CANCELLED` is an ordinary operational fact.
 *
 * **All five statuses are reachable in M5.4**, unlike Document and Matter where
 * several are vocabulary nothing can set. Every badge here corresponds to
 * something the product can actually do.
 */

const STATUS_TINT: Record<TaskStatus, string> = {
  OPEN: "border-border text-foreground",
  IN_PROGRESS: "border-primary/40 text-primary",
  WAITING: "border-border text-muted-foreground",
  COMPLETED: "border-primary/30 text-primary",
  CANCELLED: "border-border text-muted-foreground",
};

export function TaskStatusBadge({ status }: { status: TaskStatus }) {
  const t = useTranslations("tasks");

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_TINT[status]}`}
      aria-label={`${t("status")}: ${t(`statuses.${status}`)}`}
    >
      {t(`statuses.${status}`)}
    </span>
  );
}

/**
 * Priority, borrowed from Project's vocabulary.
 *
 * `URGENT` is the one value that earns emphasis — a priority scale where every
 * step shouts is a scale nobody reads.
 */
export function TaskPriorityBadge({ priority }: { priority: TaskPriority | null }) {
  const t = useTranslations("tasks");

  if (priority === null) {
    return <span className="text-muted-foreground text-sm">—</span>;
  }

  return (
    <span
      className={`rounded-full border px-2 py-0.5 text-xs ${
        priority === "URGENT"
          ? "border-destructive/40 text-destructive"
          : "border-border text-muted-foreground"
      }`}
      aria-label={`${t("priority")}: ${t(`priorities.${priority}`)}`}
    >
      {t(`priorities.${priority}`)}
    </span>
  );
}

/**
 * The overdue marker.
 *
 * Renders nothing when the task is on time, so the badge means something when it
 * appears rather than being a field that is always there. The icon is decorative
 * and the text carries the meaning — an icon alone would be exactly the
 * shape-only signal section 49 rules out.
 *
 * **The server decides this**, not the browser: a client comparing `due_at` to its
 * own clock would disagree with the backend for anybody whose machine is off.
 */
export function TaskOverdueBadge({ isOverdue }: { isOverdue: boolean }) {
  const t = useTranslations("tasks");

  if (!isOverdue) {
    return null;
  }

  return (
    <span className="border-destructive/40 text-destructive inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs">
      <AlertTriangle aria-hidden="true" className="size-3" />
      {t("overdue")}
    </span>
  );
}
