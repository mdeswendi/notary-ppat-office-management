"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { TaskOverdueBadge, TaskStatusBadge } from "@/features/tasks/task-badges";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getTasks, taskQueryKeys } from "@/services/tasks";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * The five pieces of live work due soonest, for the person looking (M5.4, D-119).
 *
 * **Filtered by `assigned_to`, not by scope.** A person may hold `OFFICE` reach
 * and still want their own queue, so the widget asks for their tasks explicitly
 * rather than relying on a Data Scope that might be wider. The backend still
 * applies the scope on top, so this can only ever narrow.
 *
 * **`open=true` rather than a status filter**, because "what should I do next" is
 * about live work, and the three live statuses are one idea rather than three
 * separate choices here.
 *
 * **It renders nothing at all without `tasks.view`**, rather than an empty card.
 * A dashboard panel that is permanently empty for a whole role is dead UI — the
 * position `10_M0_FOUNDATION.md` section 57 takes on fabricated dashboard
 * content, and the reason O-022 left the header controls out.
 *
 * Sorting is the server's: `due_at` ascending with undated work last, which is
 * why nothing here re-sorts.
 */
export function MyTasksWidget() {
  const t = useTranslations("tasks");
  const { data: user } = useCurrentUser();

  const enabled = user !== undefined && can(user, "tasks.view");

  const query = useQuery({
    queryKey: taskQueryKeys.list({
      assigned_to: user?.id ?? "",
      open: "true",
      per_page: 5,
      sort_by: "due_at",
      sort_direction: "asc",
    }),
    queryFn: () =>
      getTasks({
        assigned_to: user?.id ?? "",
        open: "true",
        per_page: 5,
        sort_by: "due_at",
        sort_direction: "asc",
      }),
    enabled,
  });

  if (!enabled) {
    return null;
  }

  const tasks = query.data?.data ?? [];

  return (
    <section className="border-border bg-card flex flex-col gap-4 rounded-lg border p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-medium">{t("myTasks")}</h2>
        <Link
          href="/tasks/my"
          className="text-muted-foreground text-sm underline-offset-4 hover:underline"
        >
          {t("viewAll")}
        </Link>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : query.isError ? (
        // Quiet on the dashboard: a panel that shouts about its own failure
        // beside working ones is worse than one that says it has nothing.
        <p className="text-muted-foreground text-sm">{t("widgetUnavailable")}</p>
      ) : tasks.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noAssignedTasks")}</p>
      ) : (
        <ul className="divide-border divide-y text-sm">
          {tasks.map((task) => (
            <li
              key={task.id}
              className="flex flex-wrap items-center gap-2 py-2 first:pt-0 last:pb-0"
            >
              <Link
                href={`/tasks/${task.id}`}
                className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
              >
                {task.title}
              </Link>

              <TaskOverdueBadge isOverdue={task.is_overdue} />
              <TaskStatusBadge status={task.status} />

              <span className="text-muted-foreground shrink-0 text-xs whitespace-nowrap">
                {task.due_at?.slice(0, 10) ?? "—"}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
