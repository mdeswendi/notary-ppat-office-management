"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { TaskOverdueBadge, TaskPriorityBadge, TaskStatusBadge } from "@/features/tasks/task-badges";
import { toTaskErrorKey } from "@/features/tasks/task-errors";
import { Link } from "@/i18n/navigation";
import { getTasks, taskQueryKeys } from "@/services/tasks";
import { PROJECT_PRIORITIES, type ProjectPriority } from "@/types/project";
import { TASK_STATUSES, type TaskListQuery, type TaskStatus } from "@/types/task";

/**
 * The Task list.
 *
 * **One component, three surfaces.** All Tasks, My Tasks and Completed differ only
 * in the filter they are given, which the page decides. Duplicating the component
 * per view would be three places for one behaviour to drift — the argument
 * `MattersList` made for two domains.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by the four
 * Task predicates, so this component filters nothing and the total it shows is
 * already the total that caller may see.
 *
 * **`is_overdue` comes from the server** and is rendered, never recomputed: a
 * client comparing to its own clock would disagree with the backend.
 */
export function TasksList({
  fixedFilter,
  emptyTitleKey = "emptyTitle",
  emptyDescriptionKey = "emptyDescription",
}: {
  /** Applied on every request and not offered as a control. */
  fixedFilter?: Pick<TaskListQuery, "assigned_to" | "status" | "open" | "project_id" | "matter_id">;
  emptyTitleKey?: string;
  emptyDescriptionKey?: string;
}) {
  const t = useTranslations("tasks");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<TaskStatus | "">("");
  const [priority, setPriority] = useState<ProjectPriority | "">("");

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: taskQueryKeys.list({ page, search, status, priority, ...fixedFilter }),
    queryFn: () => getTasks({ page, search, status, priority, ...fixedFilter }),
    placeholderData: keepPreviousData,
  });

  const tasks = query.data?.data ?? [];
  const meta = query.data?.meta;

  // A view that pins the status offers no status control: a dropdown that cannot
  // change what you see is worse than no dropdown.
  const offersStatusFilter = fixedFilter?.status === undefined && fixedFilter?.open === undefined;

  return (
    <>
      <div className="flex flex-wrap items-end gap-3">
        <div className="relative min-w-56 flex-1">
          <Search
            aria-hidden="true"
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
          />
          <Input
            className="pl-9"
            aria-label={t("searchLabel")}
            placeholder={t("searchPlaceholder")}
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
        </div>

        {offersStatusFilter ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor="task-status-filter">{t("status")}</Label>
            <select
              id="task-status-filter"
              className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value as TaskStatus | "");
                setPage(1);
              }}
            >
              <option value="">{t("allStatuses")}</option>
              {TASK_STATUSES.map((code) => (
                <option key={code} value={code}>
                  {t(`statuses.${code}`)}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div className="flex flex-col gap-2">
          <Label htmlFor="task-priority-filter">{t("priority")}</Label>
          <select
            id="task-priority-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={priority}
            onChange={(event) => {
              setPriority(event.target.value as ProjectPriority | "");
              setPage(1);
            }}
          >
            <option value="">{t("allPriorities")}</option>
            {PROJECT_PRIORITIES.map((code) => (
              <option key={code} value={code}>
                {t(`priorities.${code}`)}
              </option>
            ))}
          </select>
        </div>

        <PermissionGuard permission="tasks.create">
          <Button render={<Link href="/tasks/new" />} className="gap-2">
            <Plus aria-hidden="true" />
            {t("newTask")}
          </Button>
        </PermissionGuard>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          {[0, 1, 2, 3].map((row) => (
            <Skeleton key={row} className="h-14 w-full" />
          ))}
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
        <BaseErrorState
          title={isFiltered(search, status, priority) ? t("noMatchesTitle") : t(emptyTitleKey)}
          description={
            isFiltered(search, status, priority)
              ? t("noMatchesDescription")
              : t(emptyDescriptionKey)
          }
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("titleLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("status")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("priority")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("assignedTo")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("dueDate")}
                </th>
              </tr>
            </thead>
            <tbody>
              {tasks.map((task) => (
                <tr key={task.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link
                        href={`/tasks/${task.id}`}
                        className="font-medium underline-offset-4 hover:underline"
                      >
                        {task.title}
                      </Link>
                      <TaskOverdueBadge isOverdue={task.is_overdue} />
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <TaskStatusBadge status={task.status} />
                  </td>
                  <td className="hidden px-4 py-3 lg:table-cell">
                    <TaskPriorityBadge priority={task.priority} />
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {task.assigned_to?.name ?? t("unassigned")}
                  </td>
                  <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                    {formatDate(task.due_at)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 ? (
        <nav className="flex items-center justify-between gap-3" aria-label={t("paginationLabel")}>
          <p className="text-muted-foreground text-sm">
            {t("paginationSummary", {
              current: meta.current_page,
              last: meta.last_page,
              total: meta.total,
            })}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1 || query.isFetching}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
            >
              {t("previousPage")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page || query.isFetching}
              onClick={() => setPage((current) => current + 1)}
            >
              {t("nextPage")}
            </Button>
          </div>
        </nav>
      ) : null}
    </>
  );
}

function isFiltered(search: string, status: string, priority: string): boolean {
  return search !== "" || status !== "" || priority !== "";
}

/**
 * Dates render from the ISO string the API sends, sliced rather than parsed.
 *
 * `new Date(...).toLocaleDateString()` renders in the browser's timezone, which
 * shifts a date by a day either side of midnight — and would then differ between
 * two people looking at the same task.
 */
function formatDate(value: string | null): string {
  return value === null ? "—" : value.slice(0, 10);
}
