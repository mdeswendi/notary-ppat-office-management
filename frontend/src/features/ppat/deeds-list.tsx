"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PpatDeedStatusBadge, PpatDeedTypeBadge } from "@/features/ppat/deed-badges";
import { toPpatErrorKey } from "@/features/ppat/deed-errors";
import { Link } from "@/i18n/navigation";
import { getPpatDeeds, ppatDeedKeys } from "@/services/ppat";
import {
  REACHABLE_PPAT_DEED_STATUSES,
  type PpatDeedListQuery,
  type PpatDeedStatus,
} from "@/types/ppat";

/**
 * The PPAT Deed list (M7.2, D-121).
 *
 * **One component, three surfaces.** The standalone page, the Matter detail section
 * and the Project detail section differ only in the filter they are given, which the
 * caller decides — the argument `TasksList` made for three views, `MattersList` for
 * two domains, and `DeedsList` for the Notary side.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by the four
 * deed predicates, so this component filters nothing and the total it shows is
 * already the total that caller may see.
 *
 * **The status filter offers four values, not six.** `VOID` and `SUPERSEDED` are
 * canonical vocabulary no code path produces (D-121), so a filter for either would
 * reliably return nothing — a control that cannot work is worse than no control.
 */
export function PpatDeedsList({
  fixedFilter,
  emptyTitleKey = "deedsEmptyTitle",
  emptyDescriptionKey = "deedsEmptyDescription",
  showCreate = true,
  matterOptions,
}: {
  /** Applied on every request and not offered as a control. */
  fixedFilter?: Pick<PpatDeedListQuery, "matter_id" | "project_id" | "status">;
  emptyTitleKey?: string;
  emptyDescriptionKey?: string;
  showCreate?: boolean;
  /**
   * Matters to offer as a filter, and the signal that rows span more than one.
   *
   * Passed only by the Project view, where deeds come from several Matters at once:
   * it adds a Matter column and a Matter dropdown. The deeds page and the Matter
   * section both leave it undefined — on the Matter page every row has the same
   * parent, so a column repeating it and a dropdown that cannot change anything would
   * both be noise.
   */
  matterOptions?: { id: string; matter_number: string; title: string }[];
}) {
  const t = useTranslations("ppat");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<PpatDeedStatus | "">("");
  const [matter, setMatter] = useState("");

  const showsMatter = matterOptions !== undefined;

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  // The chosen Matter narrows within the fixed filter and never past it: a fixed
  // `matter_id` wins, so a section pinned to one Matter cannot be widened.
  const request: PpatDeedListQuery = {
    page,
    search,
    status,
    ...(matter === "" ? {} : { matter_id: matter }),
    ...fixedFilter,
  };

  const query = useQuery({
    queryKey: ppatDeedKeys.list(request),
    queryFn: () => getPpatDeeds(request),
    placeholderData: keepPreviousData,
  });

  const deeds = query.data?.data ?? [];
  const meta = query.data?.meta;

  const offersStatusFilter = fixedFilter?.status === undefined;

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
            aria-label={t("deedSearchLabel")}
            placeholder={t("deedSearchPlaceholder")}
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
          />
        </div>

        {offersStatusFilter ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor="ppat-deed-status-filter">{t("status")}</Label>
            <select
              id="ppat-deed-status-filter"
              className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value as PpatDeedStatus | "");
                setPage(1);
              }}
            >
              <option value="">{t("allStatuses")}</option>
              {REACHABLE_PPAT_DEED_STATUSES.map((code) => (
                <option key={code} value={code}>
                  {t(`deedStatuses.${code}`)}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        {showsMatter ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor="ppat-deed-matter-filter">{t("matterLabel")}</Label>
            <select
              id="ppat-deed-matter-filter"
              className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={matter}
              onChange={(event) => {
                setMatter(event.target.value);
                setPage(1);
              }}
            >
              <option value="">{t("allMatters")}</option>
              {matterOptions.map((option) => (
                <option key={option.id} value={option.id}>
                  {option.matter_number} — {option.title}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        {showCreate ? (
          <PermissionGuard permission="ppat.deeds.create">
            <ButtonLink href="/ppat/deeds/new" className="gap-2">
              <Plus aria-hidden="true" />
              {t("newDeed")}
            </ButtonLink>
          </PermissionGuard>
        ) : null}
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
          title={t("deedErrorTitle")}
          description={t(`errors.${toPpatErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : deeds.length === 0 ? (
        <BaseErrorState
          title={isFiltered(search, status) ? t("noMatchesTitle") : t(emptyTitleKey)}
          description={
            isFiltered(search, status) ? t("noMatchesDescription") : t(emptyDescriptionKey)
          }
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("deedTableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("deedTitle")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("deedNumber")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("status")}
                </th>
                {showsMatter ? (
                  <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                    {t("matterLabel")}
                  </th>
                ) : null}
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("deedType")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("deedDate")}
                </th>
              </tr>
            </thead>
            <tbody>
              {deeds.map((deed) => (
                <tr key={deed.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    <Link
                      href={`/ppat/deeds/${deed.id}`}
                      className="font-medium underline-offset-4 hover:underline"
                    >
                      {deed.title}
                    </Link>
                  </td>
                  <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                    {/* Unnumbered is the ordinary state of a draft, not a gap. */}
                    {deed.deed_number ?? t("unnumbered")}
                  </td>
                  <td className="px-4 py-3">
                    <PpatDeedStatusBadge status={deed.status} />
                  </td>
                  {showsMatter ? (
                    <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                      {deed.matter ? (
                        <Link
                          href={`/ppat/matters/${deed.matter.id}`}
                          className="underline-offset-4 hover:underline"
                        >
                          {deed.matter.matter_number}
                        </Link>
                      ) : (
                        "—"
                      )}
                    </td>
                  ) : null}
                  <td className="hidden px-4 py-3 lg:table-cell">
                    <PpatDeedTypeBadge code={deed.deed_type_code} />
                  </td>
                  <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                    {deed.deed_date ?? "—"}
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

function isFiltered(search: string, status: string): boolean {
  return search !== "" || status !== "";
}
