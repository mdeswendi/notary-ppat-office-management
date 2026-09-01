"use client";

import { useEffect, useMemo, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { partyDetailHref } from "@/features/parties/party-links";
import { toPartyErrorKey } from "@/features/parties/party-errors";
import { Link } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { getPartyDirectory, partyDirectoryKeys } from "@/services/parties";
import {
  PARTY_TYPES,
  type PartyDirectoryEntry,
  type PartyDirectoryOffice,
  type PartyType,
} from "@/types/party";

/**
 * The unified Party Directory — one place to find a person or an organization
 * (M2.5).
 *
 * **Read-only, and deliberately the only generic Party surface.** There is no
 * New Party, no Edit Party, and no Archive Party control here, because there is
 * no generic Party lifecycle to drive: Individual and Company own their own,
 * each with its own permissions, validation, and aggregate rules (D-078).
 * Creating and retiring records stays on the two subtype directories, which this
 * page does not replace.
 *
 * **No directory permission exists.** Visibility is composed from the two
 * subtype capabilities the caller already holds, and the backend refuses
 * outright when neither reaches anything.
 *
 * **The two scopes are independent, and this component must not pretend
 * otherwise.** An actor may hold `parties.view` at `OFFICE` and
 * `companies.view` at `ALL`, in which case the honest result is their own
 * Office's people beside every Office's organizations. Nothing here ranks,
 * unions, or reconciles the two scopes — that calculation belongs to the server,
 * which builds one query branch per capability — and no copy on this page claims
 * a single scope governs every row.
 *
 * Search covers ordinary discovery fields only. No identifier is searchable:
 * a directory that answers "does this NIK exist" is the existence oracle the
 * Office-scoped duplicate rules exist to prevent (D-084).
 */
export function PartyDirectory() {
  const t = useTranslations("parties");
  const tActions = useTranslations("actions");

  const { data: user } = useCurrentUser();

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [partyType, setPartyType] = useState<PartyType | "">("");
  const [officeId, setOfficeId] = useState("");

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const query = useQuery({
    queryKey: partyDirectoryKeys.list({ page, search, party_type: partyType, office_id: officeId }),
    queryFn: () => getPartyDirectory({ page, search, party_type: partyType, office_id: officeId }),
    placeholderData: keepPreviousData,
  });

  const offices = useVisibleOffices(query.data?.data);

  const entries = query.data?.data ?? [];
  const meta = query.data?.meta;

  // Presentation only, and belt-and-braces: a row of a given subtype is present
  // precisely because the caller holds that subtype's view capability at a scope
  // reaching it, so this can never wrongly unlink a row it was shown. The
  // backend authorizes the detail surface again regardless.
  const canOpen: Record<PartyType, boolean> = {
    INDIVIDUAL: can(user, "parties.view"),
    COMPANY: can(user, "companies.view"),
  };

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
          <Label htmlFor="party-type-filter">{t("typeFilterLabel")}</Label>
          <select
            id="party-type-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={partyType}
            onChange={(event) => {
              setPartyType(event.target.value as PartyType | "");
              setPage(1);
            }}
          >
            <option value="">{t("allTypes")}</option>
            {PARTY_TYPES.map((code) => (
              <option key={code} value={code}>
                {t(`partyTypes.${code}`)}
              </option>
            ))}
          </select>
        </div>

        {offices.length > 0 ? (
          <div className="flex flex-col gap-2">
            <Label htmlFor="party-office-filter">{t("officeFilterLabel")}</Label>
            <select
              id="party-office-filter"
              className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={officeId}
              onChange={(event) => {
                setOfficeId(event.target.value);
                setPage(1);
              }}
            >
              <option value="">{t("allOffices")}</option>
              {offices.map((office) => (
                <option key={office.id} value={office.id}>
                  {office.code} — {office.name}
                </option>
              ))}
            </select>
          </div>
        ) : null}
      </div>

      <p className="text-muted-foreground text-sm">{t("scopeHint")}</p>

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
          description={t(`errors.${toPartyErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : entries.length === 0 ? (
        <BaseErrorState
          title={isFiltered(search, partyType, officeId) ? t("noMatchesTitle") : t("emptyTitle")}
          description={
            isFiltered(search, partyType, officeId)
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
                  {t("nameLabel")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("typeLabel")}
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
              {entries.map((entry) => (
                <DirectoryRow
                  key={entry.id}
                  entry={entry}
                  linkable={entry.party_type !== null && canOpen[entry.party_type]}
                />
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

/**
 * One row, routed to the subtype it actually is.
 *
 * A Party is a person or an organization, so the destination is the Individual
 * or the Company page — never a generic Party detail, which does not exist and
 * would have to render one of the two anyway.
 *
 * The secondary line is the subtype's own ordinary summary: a person's full
 * name, an organization's legal name. Nothing sensitive appears here — no
 * identifier, no mask, no birth detail — because the directory is the
 * widest-reach read surface in the domain and carries the least.
 */
function DirectoryRow({ entry, linkable }: { entry: PartyDirectoryEntry; linkable: boolean }) {
  const t = useTranslations("parties");

  const href = partyDetailHref(entry.party_type, entry.id);
  const name =
    entry.display_name ?? entry.individual?.full_name ?? entry.company?.legal_name ?? "—";

  // The subtype's canonical name, shown only when it differs from the display
  // name — a Company's `display_name` is its short name when it has one, so the
  // legal name is worth showing beside it, and repeating an identical string is
  // noise.
  const canonical = entry.individual?.full_name ?? entry.company?.legal_name ?? null;
  const secondary = canonical !== null && canonical !== name ? canonical : entry.primary_email;

  return (
    <tr className="border-border border-t">
      <td className="px-4 py-3">
        {href !== null && linkable ? (
          <Link href={href} className="font-medium underline-offset-4 hover:underline">
            {name}
          </Link>
        ) : (
          <span className="font-medium">{name}</span>
        )}
        <div className="text-muted-foreground">{secondary ?? "—"}</div>
      </td>
      <td className="px-4 py-3">
        <Badge>{entry.party_type === null ? "—" : t(`partyTypes.${entry.party_type}`)}</Badge>
      </td>
      <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
        {entry.primary_phone ?? "—"}
      </td>
      <td className="text-muted-foreground hidden px-4 py-3 lg:table-cell">
        {entry.office ? entry.office.code : "—"}
      </td>
    </tr>
  );
}

/**
 * The Offices represented in the rows currently on screen.
 *
 * Built from the returned rows rather than from an options endpoint, because the
 * ones that exist answer a different question: `individuals/options` and
 * `companies/options` list the Offices an actor may **create** in, which is
 * neither necessary nor sufficient for reading. Offering those as a view filter
 * would show destinations that return nothing and hide ones that return rows.
 *
 * Derived from the current page, not accumulated across pages — a pure
 * derivation with no state to fall out of step with the query, and it can never
 * offer an Office the caller's capabilities do not already reach. Selecting one
 * only narrows: the backend applies `office_id` on top of the per-capability
 * scope predicates, so it cannot widen what either capability permits. "All
 * offices" always returns to the unfiltered view.
 */
function useVisibleOffices(rows: PartyDirectoryEntry[] | undefined): PartyDirectoryOffice[] {
  return useMemo(() => {
    const merged = new Map<string, PartyDirectoryOffice>();

    for (const row of rows ?? []) {
      if (row.office !== null) {
        merged.set(row.office.id, row.office);
      }
    }

    return [...merged.values()].sort((left, right) => left.code.localeCompare(right.code));
  }, [rows]);
}

function isFiltered(search: string, partyType: PartyType | "", officeId: string): boolean {
  return search !== "" || partyType !== "" || officeId !== "";
}
