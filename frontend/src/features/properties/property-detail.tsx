"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { Button } from "@/components/ui/button";
import { ButtonLink } from "@/components/ui/button-link";
import { Skeleton } from "@/components/ui/skeleton";
import { OwnershipHistory } from "@/features/properties/ownership-history";
import {
  PropertyArchivedBadge,
  PropertyTypeBadge,
  RightTypeBadge,
} from "@/features/properties/property-badges";
import { toPropertyErrorKey } from "@/features/properties/property-errors";
import { PropertiesList } from "@/features/properties/properties-list";
import { archiveProperty, getProperty, propertyKeys } from "@/services/properties";

/**
 * One land object, and the acts its capabilities allow (M7.3, D-121).
 *
 * **Sections, not tabs.** The M7.3 brief asked for five tabs; the repository has no
 * `Tabs` primitive, and adding one is a design decision affecting every detail page
 * rather than a side effect of a Property milestone — the ruling M5.2, M5.3, M5.4, M6.2
 * and M7.2 each followed on the same question.
 *
 * ## Three of the five sections the brief asked for, and why two are absent
 *
 * ```text
 * Overview     here
 * Ownership    here, asking its own endpoint under its own capability
 * Matters      here, as the parcel's own list filtered by matter_id
 * Documents    ABSENT — property_documents does not exist (O-046)
 * Timeline     ABSENT — never built; see below, the original reason has lapsed
 * ```
 *
 * **Documents**: `DocumentRelationType` carries `party`, `project` and `matter` only
 * and names `property_documents` as *"blocked — batch 8, M7"*. Building it is *"adding
 * a case and a migration"*, and M7.3 was scoped without a migration. A section headed
 * "Documents" that could never list one is worse than none.
 *
 * **Timeline**: an activity history belongs to the audit store. M7.3 shipped without one
 * because none existed — D-115 ruled it required, absent, and not to be improvised, and
 * M5.3, M5.4, M6.1, M7.1 and M7.2 each declined to invent one. **M8.1 built it, so that
 * reason has lapsed**: `audit_logs` and `activities` both exist now. The section is
 * simply unbuilt, and adding it is its own scoped decision, not a consequence of a
 * missing table. Meanwhile what the record itself preserves — who created it, who last
 * corrected it, and the whole chain of title — is shown, and that remains an honest
 * subset rather than a placeholder.
 *
 * ## Every control is gated on a backend-computed flag
 *
 * Not on a permission string the browser assembled. The flags fold in **record state as
 * well as capability**, so a control that would answer 403 is simply absent:
 * `can_update` and `can_archive` are both false on an archived parcel.
 *
 * **There is no delete control**, because `properties.delete` is absent from the
 * canonical catalogue — `properties.archive` is what the catalogue defines, and it soft
 * deletes. **There is no un-archive control** either: `properties.restore` does not
 * exist, unlike `projects.restore` (O-045), so archiving is confirmed before it happens.
 */
export function PropertyDetail({ propertyId }: { propertyId: string }) {
  const t = useTranslations("properties");
  const tActions = useTranslations("actions");
  const queryClient = useQueryClient();

  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: propertyKeys.detail(propertyId),
    queryFn: () => getProperty(propertyId),
  });

  const property = query.data;

  const archive = useMutation({
    mutationFn: () => archiveProperty(propertyId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: propertyKeys.all() });
    },
    onError: (error: unknown) => setActionError(t(`errors.${toPropertyErrorKey(error)}`)),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (query.isError || !property) {
    return (
      <BaseErrorState
        title={t("detailErrorTitle")}
        description={t(`errors.${toPropertyErrorKey(query.error)}`)}
        action={
          <Button variant="outline" onClick={() => void query.refetch()}>
            {tActions("retry")}
          </Button>
        }
      />
    );
  }

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight">{property.certificate_number}</h1>
          <PropertyTypeBadge type={property.property_type} />
          <RightTypeBadge code={property.right_type} />
          <PropertyArchivedBadge isArchived={property.is_archived} />
        </div>

        <p className="text-muted-foreground text-sm">{property.address}</p>

        {actionError ? (
          <p
            role="alert"
            className="border-destructive/30 bg-destructive/5 text-destructive rounded-md border px-3 py-2 text-sm"
          >
            {actionError}
          </p>
        ) : null}

        {property.is_archived ? (
          <p className="text-muted-foreground border-border rounded-md border px-3 py-2 text-sm">
            {t("archivedNotice")}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {property.can_update ? (
            <ButtonLink variant="outline" href={`/ppat/properties/${property.id}/edit`}>
              {tActions("edit")}
            </ButtonLink>
          ) : null}

          {property.can_archive ? (
            <Button
              variant="outline"
              disabled={archive.isPending}
              onClick={() => {
                setActionError(null);

                // Nothing in the product reverses this: `properties.restore` is not a
                // canonical capability, so there is no un-archive path (O-045). The
                // wording says so rather than implying an undo.
                if (window.confirm(t("archiveConfirm"))) {
                  archive.mutate();
                }
              }}
            >
              {t("archive")}
            </Button>
          ) : null}
        </div>
      </header>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("sections.overview")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-3">
          <Detail label={t("propertyNumber")} value={property.property_number ?? t("unnumbered")} />
          <Detail label={t("certificateNumber")} value={property.certificate_number} />
          <Detail label={t("certificateDate")} value={property.certificate_date ?? "—"} />
          <Detail label={t("landArea")} value={area(property.land_area)} />
          <Detail label={t("buildingArea")} value={area(property.building_area)} />
          <Detail
            label={t("measurementLetterNumber")}
            value={property.measurement_letter_number ?? "—"}
          />
        </dl>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("sections.location")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-3">
          <Detail label={t("village")} value={property.village ?? "—"} />
          <Detail label={t("district")} value={property.district ?? "—"} />
          <Detail label={t("city")} value={property.city ?? "—"} />
          <Detail label={t("province")} value={property.province ?? "—"} />
          <Detail label={t("postalCode")} value={property.postal_code ?? "—"} />
          {property.office ? (
            <Detail label={t("officeLabel")} value={property.office.name} />
          ) : null}
        </dl>
      </section>

      {/*
        The chain of title. A section, not a tab, and it asks its own endpoint under
        `properties.ownership.*` — its own family of codes, so reading the parcel does
        not confer reading who owns it, and the section renders its own honest failure
        for a reader who holds one and not the other.
      */}
      {property.can_view_ownership ? (
        <section className="border-border rounded-lg border p-4">
          <OwnershipHistory propertyId={property.id} isArchived={property.is_archived} />
        </section>
      ) : null}

      {/*
        Which work names this parcel. The Property list filtered by `matter_id` would
        be the wrong direction — this is the parcel's own Matters, so it reuses the
        Matter-side surface. Rendered as a count plus a link rather than an embedded
        list, because which of those Matters a caller may see answers to
        `ppat.matters.view` with its own Data Scope (D-101), not to `properties.view`.
      */}
      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("sections.matters")}</h2>

        <p className="text-muted-foreground border-border rounded-lg border p-4 text-sm">
          {t("matterCount", { count: property.matter_count ?? 0 })}
          <span className="mt-1 block text-xs">{t("matterCountHint")}</span>
        </p>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t("sections.record")}</h2>

        <dl className="border-border grid gap-x-8 gap-y-3 rounded-lg border p-4 sm:grid-cols-2">
          <Detail
            label={t("createdAt")}
            value={stamp(property.created_at, property.created_by?.name)}
          />
          <Detail
            label={t("updatedAt")}
            value={stamp(property.updated_at, property.updated_by?.name)}
          />
        </dl>

        {/*
          The two stamps above are what the record itself preserves. A full activity
          history belongs to the audit store, which M7.3 shipped without because none
          existed — D-115 ruled it required, absent, and not to be improvised, and the
          M7.3 brief's request for an activity log would have been exactly that
          improvisation. M8.1 has since built the store, so this section is unbuilt
          rather than blocked.
        */}
        <p className="text-muted-foreground text-xs">{t("recordHint")}</p>
      </section>
    </div>
  );
}

/**
 * The Properties one Matter concerns, reusing the ordinary list.
 *
 * Exported for the Matter page; kept here so the filter and the list stay in one file.
 */
export function PropertiesForMatter({ matterId }: { matterId: string }) {
  return <PropertiesList fixedFilter={{ matter_id: matterId }} showCreate={false} />;
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-sm font-medium">{label}</dt>
      <dd className="text-muted-foreground text-sm">{value}</dd>
    </div>
  );
}

/** Square metres, or a dash. The unit is in the label, not repeated per value. */
function area(value: number | null): string {
  return value === null ? "—" : String(value);
}

/**
 * A stamp's date and actor, or a dash.
 *
 * Sliced rather than parsed: `new Date(...).toLocaleDateString()` renders in the
 * browser's timezone, which shifts a date by a day either side of midnight and would
 * then differ between two people looking at the same parcel.
 */
function stamp(at: string | null, who: string | undefined): string {
  if (at === null) {
    return "—";
  }

  return who === undefined ? at.slice(0, 10) : `${at.slice(0, 10)} · ${who}`;
}
