"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { ProjectPriorityBadge, ProjectStatusBadge } from "@/features/projects/project-badges";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { Link } from "@/i18n/navigation";
import { getProjects, projectQueryKeys } from "@/services/projects";
import {
  PROJECT_PRIORITIES,
  PROJECT_STATUSES,
  type ProjectPriority,
  type ProjectStatus,
} from "@/types/project";

/**
 * The Project list.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by Data
 * Scope — the four Project predicates union, and a scoped caller's query never
 * selects a row they may not open — so this component filters nothing and the
 * total it shows is already the total that caller may see.
 *
 * Search covers the title and the internal reference. The reference is ordinary
 * office identification rather than sensitive identity, so searching it is safe;
 * but because references are unique only within an Office (D-096), one reference
 * may legitimately match rows in several Offices for an `ALL`-scoped caller, and
 * the list does not pretend otherwise.
 *
 * **Archived Projects are not here.** They answer to `projects.restore` on their
 * own surface, because widening this list to include them would expose archived
 * work to everyone who can read Projects at all (D-093).
 */
export function ProjectsList() {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<ProjectStatus | "">("");
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
    queryKey: projectQueryKeys.list({ page, search, status, priority }),
    queryFn: () => getProjects({ page, search, status, priority }),
    placeholderData: keepPreviousData,
  });

  const projects = query.data?.data ?? [];
  const meta = query.data?.meta;

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

        <div className="flex flex-col gap-2">
          <Label htmlFor="project-status-filter">{t("statusLabel")}</Label>
          <select
            id="project-status-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as ProjectStatus | "");
              setPage(1);
            }}
          >
            <option value="">{t("allStatuses")}</option>
            {PROJECT_STATUSES.map((code) => (
              <option key={code} value={code}>
                {t(`statuses.${code}`)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="project-priority-filter">{t("priorityLabel")}</Label>
          <select
            id="project-priority-filter"
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

        <PermissionGuard permission="projects.create">
          <ButtonLink href="/projects/new" className="gap-2">
            <Plus aria-hidden="true" />
            {t("create")}
          </ButtonLink>
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
          description={t(`errors.${toProjectErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : projects.length === 0 ? (
        <EmptyState
          title={isFiltered(search, status, priority) ? t("noMatchesTitle") : t("emptyTitle")}
          description={
            isFiltered(search, status, priority) ? t("noMatchesDescription") : t("emptyDescription")
          }
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("referenceLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("titleLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("statusLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("priorityLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("picLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("officeLabel")}
                </th>
              </tr>
            </thead>
            <tbody>
              {projects.map((project) => (
                <tr key={project.id} className="border-border border-t">
                  <td className="text-muted-foreground px-4 py-3 font-mono text-xs whitespace-nowrap">
                    {project.project_number}
                  </td>
                  <td className="px-4 py-3">
                    <Link
                      href={`/projects/${project.id}`}
                      className="font-medium underline-offset-4 hover:underline"
                    >
                      {project.title}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <ProjectStatusBadge status={project.status} />
                  </td>
                  <td className="hidden px-4 py-3 lg:table-cell">
                    <ProjectPriorityBadge priority={project.priority} />
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {project.pic?.name ?? t("unassigned")}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {project.office?.code ?? "—"}
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
