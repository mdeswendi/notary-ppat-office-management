"use client";

import { useEffect, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Plus, Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PermissionGuard } from "@/components/permission-guard";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { toIndividualErrorKey } from "@/features/individuals/individual-errors";
import { Link } from "@/i18n/navigation";
import { getIndividuals, individualQueryKeys } from "@/services/individuals";

/**
 * The Individual directory.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by Data
 * Scope — an office-scoped caller's request never selects another Office's rows
 * — so this component filters nothing and the total it shows is already the
 * total that caller may see.
 *
 * Search covers name, phone, and email only. Identifier search is M2.5 and is
 * deliberately not wired here: a directory that answers "does this NIK exist"
 * is an existence oracle for identity data, which is exactly what the
 * office-scoped duplicate rules exist to prevent (D-084).
 *
 * Identity appears as masks the server computed. No raw identifier is present in
 * this payload, so there is nothing here to accidentally reveal.
 */
export function IndividualsList() {
  const t = useTranslations("individuals");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: individualQueryKeys.list({ page, search }),
    queryFn: () => getIndividuals({ page, search }),
    placeholderData: keepPreviousData,
  });

  const individuals = query.data?.data ?? [];
  const meta = query.data?.meta;

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="relative max-w-sm flex-1">
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

        <PermissionGuard permission="parties.create">
          <Button render={<Link href="/parties/individuals/new" />} className="gap-2">
            <Plus aria-hidden="true" />
            {t("create")}
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
          description={t(`errors.${toIndividualErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : individuals.length === 0 ? (
        <BaseErrorState
          title={search === "" ? t("emptyTitle") : t("noMatchesTitle")}
          description={search === "" ? t("emptyDescription") : t("noMatchesDescription")}
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("nameLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium md:table-cell">
                  {t("nikLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("phoneLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("officeLabel")}
                </th>
              </tr>
            </thead>
            <tbody>
              {individuals.map((individual) => (
                <tr key={individual.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    <Link
                      href={`/parties/individuals/${individual.id}`}
                      className="font-medium underline-offset-4 hover:underline"
                    >
                      {individual.full_name}
                    </Link>
                    <div className="text-muted-foreground">{individual.primary_email ?? "—"}</div>
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 font-mono md:table-cell">
                    {individual.nik_masked ?? t("notRecorded")}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {individual.primary_phone ?? "—"}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {individual.office ? individual.office.code : "—"}
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
