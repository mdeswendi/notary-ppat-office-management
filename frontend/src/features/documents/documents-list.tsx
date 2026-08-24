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
import { DocumentSensitiveBadge, DocumentStatusBadge } from "@/features/documents/document-badges";
import { toDocumentErrorKey } from "@/features/documents/document-errors";
import { Link } from "@/i18n/navigation";
import { documentQueryKeys, getDocuments } from "@/services/documents";
import { DOCUMENT_STATUSES, type DocumentStatus } from "@/types/document";

/**
 * The Document list.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by the
 * three Document predicates, so this component filters nothing and the total it
 * shows is already the total that caller may see.
 *
 * **Sensitive documents the caller may not reach never arrive**, because the
 * backend excludes them from the query rather than sending a stub. What a stub for
 * an unreachable sensitive document may carry is a genuinely open question the M5
 * lock records, so nothing here renders one.
 *
 * Search covers the title and the internal reference. The reference is ordinary
 * office identification rather than sensitive identity, so searching it is safe;
 * but because references are unique only within an Office (D-116), one reference
 * may legitimately match rows in several Offices for an `ALL`-scoped caller, and
 * the list does not pretend otherwise.
 *
 * **The status filter offers all seven codes**, including the four no capability
 * can currently set (D-117). Filtering on a status is reading, not writing.
 */
export function DocumentsList() {
  const t = useTranslations("documents");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<DocumentStatus | "">("");
  const [sensitive, setSensitive] = useState<"true" | "false" | "">("");

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: documentQueryKeys.list({ page, search, status, is_sensitive: sensitive }),
    queryFn: () => getDocuments({ page, search, status, is_sensitive: sensitive }),
    placeholderData: keepPreviousData,
  });

  const documents = query.data?.data ?? [];
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
          <Label htmlFor="document-status-filter">{t("statusLabel")}</Label>
          <select
            id="document-status-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value as DocumentStatus | "");
              setPage(1);
            }}
          >
            <option value="">{t("allStatuses")}</option>
            {DOCUMENT_STATUSES.map((code) => (
              <option key={code} value={code}>
                {t(`statuses.${code}`)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="document-sensitive-filter">{t("sensitivityLabel")}</Label>
          <select
            id="document-sensitive-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={sensitive}
            onChange={(event) => {
              setSensitive(event.target.value as "true" | "false" | "");
              setPage(1);
            }}
          >
            <option value="">{t("allSensitivity")}</option>
            <option value="true">{t("sensitiveOnly")}</option>
            <option value="false">{t("ordinaryOnly")}</option>
          </select>
        </div>

        <PermissionGuard permission="documents.upload">
          <Button render={<Link href="/documents/upload" />} className="gap-2">
            <Plus aria-hidden="true" />
            {t("upload")}
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
          description={t(`errors.${toDocumentErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : documents.length === 0 ? (
        <BaseErrorState
          title={isFiltered(search, status, sensitive) ? t("noMatchesTitle") : t("emptyTitle")}
          description={
            isFiltered(search, status, sensitive)
              ? t("noMatchesDescription")
              : t("emptyDescription")
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
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("typeLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("statusLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("uploadedByLabel")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("uploadedAtLabel")}
                </th>
              </tr>
            </thead>
            <tbody>
              {documents.map((document) => (
                <tr key={document.id} className="border-border border-t">
                  <td className="text-muted-foreground px-4 py-3 font-mono text-xs whitespace-nowrap">
                    {document.document_number}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link
                        href={`/documents/${document.id}`}
                        className="font-medium underline-offset-4 hover:underline"
                      >
                        {document.title}
                      </Link>
                      <DocumentSensitiveBadge isSensitive={document.is_sensitive} />
                    </div>
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {document.document_type_code ?? "—"}
                  </td>
                  <td className="px-4 py-3">
                    <DocumentStatusBadge status={document.status} />
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
                    {document.created_by?.name ?? "—"}
                  </td>
                  <td className="text-muted-foreground hidden px-4 py-3 whitespace-nowrap lg:table-cell">
                    {formatDate(document.created_at)}
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

function isFiltered(search: string, status: string, sensitive: string): boolean {
  return search !== "" || status !== "" || sensitive !== "";
}

/**
 * Dates are rendered from the ISO string the API sends, sliced rather than parsed.
 *
 * `new Date(...).toLocaleDateString()` renders in the *browser's* timezone, which
 * silently shifts a date by a day either side of midnight — and would then differ
 * between two people looking at the same record.
 */
function formatDate(value: string | null): string {
  return value === null ? "—" : value.slice(0, 10);
}
