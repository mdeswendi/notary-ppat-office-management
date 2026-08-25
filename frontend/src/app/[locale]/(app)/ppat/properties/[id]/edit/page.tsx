"use client";

import { use } from "react";
import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { BaseErrorState } from "@/components/feedback/base-error-state";
import { PageContainer } from "@/components/layout/page-container";
import { Skeleton } from "@/components/ui/skeleton";
import { toPropertyErrorKey } from "@/features/properties/property-errors";
import { PropertyForm } from "@/features/properties/property-form";
import { getProperty, propertyKeys } from "@/services/properties";

/**
 * Correct a land object (M7.3, D-121).
 *
 * A client page rather than a server one, because the form needs the record's current
 * values before it can render — the shape `/projects/[id]/edit` and
 * `/ppat/matters/[id]/edit` already use.
 *
 * **`property_number` renders read-only.** A reference belongs to the record that
 * received it (D-103), and the API answers 422 rather than ignoring a change.
 *
 * **An archived parcel cannot be corrected**, and the API says so with a 403 — the
 * detail page offers no edit control for one, so reaching this page for an archived
 * record means a stale link rather than a missing check.
 */
export default function EditPropertyPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const t = useTranslations("properties");

  const query = useQuery({
    queryKey: propertyKeys.detail(id),
    queryFn: () => getProperty(id),
  });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("editProperty")}</h1>
        <p className="text-muted-foreground">{t("editPropertySubtitle")}</p>
      </div>

      {query.isPending ? (
        <div className="flex flex-col gap-4" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-64 w-full max-w-3xl" />
        </div>
      ) : query.isError || !query.data ? (
        <BaseErrorState
          title={t("detailErrorTitle")}
          description={t(`errors.${toPropertyErrorKey(query.error)}`)}
        />
      ) : (
        <PropertyForm property={query.data} />
      )}
    </PageContainer>
  );
}
