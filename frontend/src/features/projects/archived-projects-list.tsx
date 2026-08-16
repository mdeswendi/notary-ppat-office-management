"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { ProjectStatusBadge } from "@/features/projects/project-badges";
import { toProjectErrorKey } from "@/features/projects/project-errors";
import { getArchivedProjects, projectQueryKeys, restoreProject } from "@/services/projects";

/**
 * Archived Projects, and putting one back.
 *
 * **A separate page under a separate capability.** This list answers to
 * `projects.restore`, not `projects.view`, and the split is strict in both
 * directions: holding `projects.view` reaches no archived record anywhere, and
 * holding `projects.restore` reaches archived records within its own Data Scope
 * and no live one (D-093).
 *
 * The alternative — an "include archived" toggle on the ordinary list — would
 * expose archived work to everyone who can read Projects at all, which is a much
 * larger group than those who may restore, and one nobody granted
 * archive-visibility to.
 *
 * **Business status is shown precisely because archiving did not change it.** An
 * archived record can still read `IN_PROGRESS`; the two states have unfortunately
 * similar names, and showing the status is what makes the difference visible.
 * Restoring returns the record and leaves the status exactly as it was — it is
 * not a reopen.
 */
export function ArchivedProjectsList() {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: projectQueryKeys.archivedList({ page, search }),
    queryFn: () => getArchivedProjects({ page, search }),
    placeholderData: keepPreviousData,
    // A 403 is an ordinary outcome for somebody who may read Projects but not
    // restore them, so it is not retried into a spinner.
    retry: false,
  });

  const projects = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <div className="relative max-w-sm">
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

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          {[0, 1, 2].map((row) => (
            <Skeleton key={row} className="h-14 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <BaseErrorState
          title={t("archivedErrorTitle")}
          description={t(`errors.${toProjectErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : projects.length === 0 ? (
        <BaseErrorState
          title={t("archivedEmptyTitle")}
          description={t("archivedEmptyDescription")}
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("archivedTableCaption")}</caption>
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
                  {t("archivedAtLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  <span className="sr-only">{t("restoreAction")}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {projects.map((project) => (
                <tr key={project.id} className="border-border border-t">
                  <td className="text-muted-foreground px-4 py-3 font-mono text-xs whitespace-nowrap">
                    {project.project_number}
                  </td>
                  <td className="px-4 py-3 font-medium">{project.title}</td>
                  <td className="px-4 py-3">
                    <ProjectStatusBadge status={project.status} />
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {project.archived_at?.slice(0, 10) ?? "—"}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {project.can_restore ? <RestoreButton projectId={project.id} /> : null}
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

function RestoreButton({ projectId }: { projectId: string }) {
  const t = useTranslations("projects");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [errorKey, setErrorKey] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => restoreProject(projectId),
    onSuccess: async () => {
      setErrorKey(null);
      await queryClient.invalidateQueries({ queryKey: projectQueryKeys.all });
    },
    onError: (error: unknown) => setErrorKey(toProjectErrorKey(error)),
  });

  return (
    <div className="flex flex-col items-end gap-1">
      <Button
        variant="outline"
        size="sm"
        disabled={mutation.isPending}
        onClick={() => mutation.mutate()}
      >
        {mutation.isPending ? tActions("saving") : t("restoreAction")}
      </Button>

      {errorKey ? (
        <p role="alert" className="text-destructive text-xs">
          {t(`errors.${errorKey}`)}
        </p>
      ) : null}
    </div>
  );
}
