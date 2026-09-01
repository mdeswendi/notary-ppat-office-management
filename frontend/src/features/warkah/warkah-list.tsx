"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { EmptyState } from "@/components/feedback/empty-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { WarkahStatusBadge } from "@/features/warkah/warkah-badges";
import { toWarkahErrorKey } from "@/features/warkah/warkah-errors";
import { Link } from "@/i18n/navigation";
import { getWarkahList, warkahKeys } from "@/services/warkah";
import { REACHABLE_WARKAH_STATUSES, type WarkahListQuery, type WarkahStatus } from "@/types/warkah";

/**
 * Every Warkah the office holds (M7.4, D-121).
 *
 * ## The one question a top-level surface answers that the deed page cannot
 *
 * *Which bundles are still short?* A deed page answers it for one transaction; an
 * office needs it across all of them, which is why this list exists and why it defaults
 * to ordering by completeness ascending — the transactions whose evidence is not in
 * come first.
 *
 * **There is no create control**, and there could not be. A Warkah is the supporting
 * bundle *of one deed*; it has no independent existence, so it is started from the
 * deed's own page by adding the first line. That is also why every row links to its
 * deed rather than to a Warkah address of its own.
 *
 * ## The percentage means one thing, and the column header does not oversell it
 *
 * **Every line that office listed has a file against it** — not that the bundle is
 * legally sufficient. The mandatory composition per deed type is open question three,
 * and the note under the table says so rather than leaving a reader to assume.
 *
 * **`FINALIZED` and `ARCHIVED` are not offered as filters.** No code path produces
 * either — `ppat.warkah.finalize` and `.archive` stay registered and unimplemented
 * because their trigger is open question eight — so a filter for one would reliably
 * return nothing (D-064, O-041). The badge still renders them, because a row written
 * directly to the database could carry one.
 */
export function WarkahList() {
  const t = useTranslations("warkah");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<WarkahStatus | "">("");
  const [incompleteOnly, setIncompleteOnly] = useState(false);

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const request: WarkahListQuery = { page, search, status, incomplete_only: incompleteOnly };

  const query = useQuery({
    queryKey: warkahKeys.list(request),
    queryFn: () => getWarkahList(request),
    placeholderData: keepPreviousData,
  });

  const bundles = query.data?.data ?? [];
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
          <Label htmlFor="warkah-status-filter">{t("status")}</Label>
          <Select
            id="warkah-status-filter"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as WarkahStatus | "");
              setPage(1);
            }}
          >
            <option value="">{t("allStatuses")}</option>
            {REACHABLE_WARKAH_STATUSES.map((code) => (
              <option key={code} value={code}>
                {t(`statuses.${code}`)}
              </option>
            ))}
          </Select>
        </div>

        <label className="flex items-center gap-2 pb-2 text-sm">
          <input
            type="checkbox"
            checked={incompleteOnly}
            onChange={(event) => {
              setIncompleteOnly(event.target.checked);
              setPage(1);
            }}
          />
          {t("incompleteOnly")}
        </label>
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
          title={t("listErrorTitle")}
          description={t(`errors.${toWarkahErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : bundles.length === 0 ? (
        <EmptyState
          title={
            isFiltered(search, status, incompleteOnly) ? t("noMatchesTitle") : t("listEmptyTitle")
          }
          description={
            isFiltered(search, status, incompleteOnly)
              ? t("noMatchesDescription")
              : t("listEmptyDescription")
          }
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("deed")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("status")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("completeness")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("items")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("verifiedAt")}
                </th>
              </tr>
            </thead>
            <tbody>
              {bundles.map((warkah) => (
                <tr key={warkah.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    {/*
                      Links to the deed, not to a Warkah address: a bundle has no
                      independent existence, so its page *is* its deed's page.
                    */}
                    <Link
                      href={`/ppat/deeds/${warkah.ppat_deed_id}`}
                      className="font-medium underline-offset-4 hover:underline"
                    >
                      {warkah.deed?.title ?? warkah.ppat_deed_id}
                    </Link>
                    {warkah.deed?.deed_number ? (
                      <span className="text-muted-foreground block text-xs">
                        {warkah.deed.deed_number}
                      </span>
                    ) : null}
                  </td>
                  <td className="px-4 py-3">
                    <WarkahStatusBadge status={warkah.status} />
                  </td>
                  <td className="px-4 py-3 tabular-nums">{warkah.completeness_percentage}%</td>
                  <td className="text-muted-foreground hidden px-4 py-3 tabular-nums lg:table-cell">
                    {warkah.items_count ?? 0}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 whitespace-nowrap lg:table-cell">
                    {warkah.verified_at?.slice(0, 10) ?? "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/*
        What the column actually counts. The M7 lock section 8.2 requires the interface
        to say this rather than let a reader assume legal sufficiency.
      */}
      <p className="text-muted-foreground text-xs">{t("completenessHint")}</p>

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

function isFiltered(search: string, status: string, incompleteOnly: boolean): boolean {
  return search !== "" || status !== "" || incompleteOnly;
}
