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
import {
  PropertyArchivedBadge,
  PropertyTypeBadge,
  RightTypeBadge,
} from "@/features/properties/property-badges";
import { toPropertyErrorKey } from "@/features/properties/property-errors";
import { Link } from "@/i18n/navigation";
import { getProperties, propertyKeys } from "@/services/properties";
import { PROPERTY_TYPES, type PropertyListQuery, type PropertyType } from "@/types/property";

/**
 * The Property list (M7.3, D-121).
 *
 * **One component, three surfaces.** The standalone page, the Matter detail section and
 * the Project detail section differ only in the filter they are given, which the caller
 * decides — the argument `TasksList`, `MattersList` and both deed lists already make.
 *
 * Rows are whatever the API returns. Visibility is decided server-side by the two
 * Property predicates (`OFFICE` and `ALL` — a parcel is office-owned reference data, so
 * `OWN` and `ASSIGNED` reach nothing), so this component filters nothing and the total
 * it shows is already the total that caller may see.
 *
 * ## Two things this list shows that need explaining
 *
 * **The owner column is absent for a caller who lacks `properties.ownership.view`**, not
 * blank. Reading a parcel is not reading its chain of title — the catalogue splits the
 * two capabilities — so `current_owners` arrives `null` and the column is not rendered
 * at all. A blank column would suggest nobody owns the land.
 *
 * **Archived parcels are excluded by default and never hidden.** The filter offers
 * active, archived and both, because retiring a record from the active list is not
 * making it unfindable: an office looking up an old certificate needs it.
 */
export function PropertiesList({
  fixedFilter,
  emptyTitleKey = "listEmptyTitle",
  emptyDescriptionKey = "listEmptyDescription",
  showCreate = true,
}: {
  /** Applied on every request and not offered as a control. */
  fixedFilter?: Pick<PropertyListQuery, "matter_id" | "project_id" | "owner_party_id">;
  emptyTitleKey?: string;
  emptyDescriptionKey?: string;
  showCreate?: boolean;
}) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");

  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [propertyType, setPropertyType] = useState<PropertyType | "">("");
  const [archived, setArchived] = useState<"" | "1" | "all">("");

  // Debounced so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const request: PropertyListQuery = {
    page,
    search,
    property_type: propertyType,
    archived,
    ...fixedFilter,
  };

  const query = useQuery({
    queryKey: propertyKeys.list(request),
    queryFn: () => getProperties(request),
    placeholderData: keepPreviousData,
  });

  const properties = query.data?.data ?? [];
  const meta = query.data?.meta;

  // The server decides this, per capability. A caller without
  // `properties.ownership.view` gets `null` rather than an empty array, which is what
  // makes "not shown to you" distinguishable from "nobody owns it".
  const showsOwners = properties.some((property) => property.current_owners !== null);

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
          <Label htmlFor="property-type-filter">{t("propertyType")}</Label>
          <select
            id="property-type-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={propertyType}
            onChange={(event) => {
              setPropertyType(event.target.value as PropertyType | "");
              setPage(1);
            }}
          >
            <option value="">{t("allTypes")}</option>
            {PROPERTY_TYPES.map((code) => (
              <option key={code} value={code}>
                {t(`propertyTypes.${code}`)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="property-archived-filter">{t("archivedFilter")}</Label>
          <select
            id="property-archived-filter"
            className="border-border bg-background focus-visible:ring-ring h-9 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            value={archived}
            onChange={(event) => {
              setArchived(event.target.value as "" | "1" | "all");
              setPage(1);
            }}
          >
            <option value="">{t("archivedFilters.active")}</option>
            <option value="1">{t("archivedFilters.archived")}</option>
            <option value="all">{t("archivedFilters.all")}</option>
          </select>
        </div>

        {showCreate ? (
          <PermissionGuard permission="properties.create">
            <ButtonLink href="/ppat/properties/new" className="gap-2">
              <Plus aria-hidden="true" />
              {t("newProperty")}
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
          title={t("listErrorTitle")}
          description={t(`errors.${toPropertyErrorKey(query.error)}`)}
          action={
            <Button variant="outline" onClick={() => void query.refetch()}>
              {tActions("retry")}
            </Button>
          }
        />
      ) : properties.length === 0 ? (
        <BaseErrorState
          title={isFiltered(search, propertyType) ? t("noMatchesTitle") : t(emptyTitleKey)}
          description={
            isFiltered(search, propertyType) ? t("noMatchesDescription") : t(emptyDescriptionKey)
          }
        />
      ) : (
        <div className="border-border overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">{t("tableCaption")}</caption>
            <thead className="bg-muted/40 text-muted-foreground">
              <tr>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("certificateNumber")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("propertyNumber")}
                </th>
                <th scope="col" className="px-4 py-3 font-medium">
                  {t("propertyType")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("rightType")}
                </th>
                <th scope="col" className="hidden px-4 py-3 font-medium lg:table-cell">
                  {t("address")}
                </th>
                {showsOwners ? (
                  <th scope="col" className="hidden px-4 py-3 font-medium xl:table-cell">
                    {t("currentOwner")}
                  </th>
                ) : null}
              </tr>
            </thead>
            <tbody>
              {properties.map((property) => (
                <tr key={property.id} className="border-border border-t">
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link
                        href={`/ppat/properties/${property.id}`}
                        className="font-medium underline-offset-4 hover:underline"
                      >
                        {property.certificate_number}
                      </Link>
                      <PropertyArchivedBadge isArchived={property.is_archived} />
                    </div>
                  </td>
                  <td className="text-muted-foreground px-4 py-3 whitespace-nowrap">
                    {/* An unnumbered parcel is an imported record, not a gap. */}
                    {property.property_number ?? t("unnumbered")}
                  </td>
                  <td className="px-4 py-3">
                    <PropertyTypeBadge type={property.property_type} />
                  </td>
                  <td className="hidden px-4 py-3 lg:table-cell">
                    <RightTypeBadge code={property.right_type} />
                  </td>
                  <td className="text-muted-foreground hidden max-w-xs truncate px-4 py-3 lg:table-cell">
                    {property.address}
                  </td>
                  {showsOwners ? (
                    <td className="text-muted-foreground hidden px-4 py-3 xl:table-cell">
                      <OwnerSummary property={property} />
                    </td>
                  ) : null}
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

/**
 * Who owns this parcel now, in one cell.
 *
 * **Names every current holder, not the first.** Co-ownership is ordinary for
 * Indonesian land and the M7 lock is explicit that several links may be current at once
 * — showing one and dropping the rest would be a wrong answer rather than a shorter one.
 * The list is truncated visually and never in the data.
 */
function OwnerSummary({
  property,
}: {
  property: { current_owners: { id: string; display_name: string | null }[] | null };
}) {
  const t = useTranslations("properties");

  const owners = property.current_owners ?? [];

  if (owners.length === 0) {
    return <span>{t("noOwnerRecorded")}</span>;
  }

  return (
    <span className="line-clamp-2">
      {owners.map((owner) => owner.display_name ?? t("unnamedParty")).join(", ")}
    </span>
  );
}

function isFiltered(search: string, propertyType: string): boolean {
  return search !== "" || propertyType !== "";
}
