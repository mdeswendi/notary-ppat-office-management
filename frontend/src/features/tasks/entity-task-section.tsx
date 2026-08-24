"use client";

import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { TaskOverdueBadge, TaskPriorityBadge, TaskStatusBadge } from "@/features/tasks/task-badges";
import { toTaskErrorKey } from "@/features/tasks/task-errors";
import { Link } from "@/i18n/navigation";
import { getTasks, taskQueryKeys } from "@/services/tasks";
import type { TaskListQuery } from "@/types/task";

/**
 * The tasks raised against one Project or Matter.
 *
 * **A section, not a tab** — the pattern these two pages already use for
 * participation, workflow and documents. The repository has no `Tabs` primitive,
 * and adding one is a design decision affecting pages M4 already shipped rather
 * than a side effect of a task milestone.
 *
 * **It answers to its own capability and its own endpoint.** Reading the tasks on a
 * Matter requires `tasks.view` at a usable scope, which is a separate question
 * from reaching the Matter — so this is deliberately not folded into the Matter
 * resource, where it would have made `notary.matters.view` a way to read who is
 * doing what. A reader who can open the Matter and not its tasks sees the section
 * fail honestly rather than see a fabricated empty one.
 *
 * **The filter is applied server-side**, inside the visibility-scoped query, so
 * this never fetches everything and discards.
 */
export function EntityTaskSection({
  filter,
  createHref,
}: {
  filter: Pick<TaskListQuery, "project_id" | "matter_id">;
  createHref?: string;
}) {
  const t = useTranslations("tasks");
  const tActions = useTranslations("actions");

  const query = useQuery({
    queryKey: taskQueryKeys.list({ ...filter, per_page: 50 }),
    queryFn: () => getTasks({ ...filter, per_page: 50 }),
  });

  const tasks = query.data?.data ?? [];

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t("sectionTitle")}</h2>

        {createHref ? (
          <PermissionGuard permission="tasks.create">
            <Button
              variant="outline"
              size="sm"
              className="gap-2"
              render={<Link href={createHref} />}
            >
              <Plus aria-hidden="true" className="size-4" />
              {t("newTask")}
            </Button>
          </PermissionGuard>
        ) : null}
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("errorTitle")}
          description={t(`errors.${toTaskErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : tasks.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noTasks")}</p>
      ) : (
        <ul className="border-border divide-border divide-y rounded-lg border text-sm">
          {tasks.map((task) => (
            <li key={task.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
              <Link
                href={`/tasks/${task.id}`}
                className="min-w-0 flex-1 truncate font-medium underline-offset-4 hover:underline"
              >
                {task.title}
              </Link>

              <TaskOverdueBadge isOverdue={task.is_overdue} />
              <TaskPriorityBadge priority={task.priority} />
              <TaskStatusBadge status={task.status} />

              <span className="text-muted-foreground shrink-0 text-xs">
                {task.assigned_to?.name ?? t("unassigned")}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
