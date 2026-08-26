"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { DashboardPanel } from "@/features/dashboard/dashboard-panel";
import { TaskOverdueBadge, TaskStatusBadge } from "@/features/tasks/task-badges";
import { Link } from "@/i18n/navigation";
import { dashboardQueryKeys, getDashboardTasks } from "@/services/dashboard";
import type { DashboardTask } from "@/types/dashboard";

/**
 * The caller's own work, in three buckets (M8.1).
 *
 * Distinct from `MyTasksWidget`, which M5.4 built as a flat "next five due".
 * This one answers *"what is late, what is today, and what is coming"* — three
 * questions the office asks separately — and it comes from the Dashboard
 * endpoint, which buckets server-side so the client never re-derives dates.
 */
export function TasksWidget() {
  const t = useTranslations("dashboard");

  const query = useQuery({
    queryKey: dashboardQueryKeys.tasks(),
    queryFn: getDashboardTasks,
  });

  const buckets = query.data ?? null;

  const total =
    (buckets?.overdue.length ?? 0) + (buckets?.today.length ?? 0) + (buckets?.upcoming.length ?? 0);

  return (
    <DashboardPanel
      title={t("myTasks")}
      action={
        <Link
          href="/tasks/my"
          className="text-muted-foreground text-sm underline-offset-4 hover:underline"
        >
          {t("viewAll")}
        </Link>
      }
      isPending={query.isPending}
      isError={query.isError}
      unavailable={buckets === null}
      isEmpty={total === 0}
      emptyMessage={t("noTasks")}
    >
      <div className="flex flex-col gap-4">
        {/* Overdue first: the bucket that needs a decision today. */}
        <TaskBucket label={t("overdue")} tasks={buckets?.overdue ?? []} />
        <TaskBucket label={t("today")} tasks={buckets?.today ?? []} />
        <TaskBucket label={t("upcoming")} tasks={buckets?.upcoming ?? []} />
      </div>
    </DashboardPanel>
  );
}

function TaskBucket({ label, tasks }: { label: string; tasks: DashboardTask[] }) {
  if (tasks.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-2">
      <h3 className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
        {label} <span className="tabular-nums">({tasks.length})</span>
      </h3>

      <ul className="divide-border divide-y text-sm">
        {tasks.map((task) => (
          <li key={task.id} className="flex flex-wrap items-center gap-2 py-2 first:pt-0 last:pb-0">
            <Link
              href={`/tasks/${task.id}`}
              className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
            >
              {task.title}
            </Link>

            <TaskOverdueBadge isOverdue={task.is_overdue} />
            <TaskStatusBadge status={task.status} />

            <span className="text-muted-foreground shrink-0 text-xs whitespace-nowrap tabular-nums">
              {task.due_at?.slice(0, 10) ?? "—"}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
