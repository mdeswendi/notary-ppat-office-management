import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PropertyForm } from "@/features/properties/property-form";

/**
 * Record a land object (M7.3, D-121).
 *
 * The parcel lands in the actor's own Office — `ALL` is reach over records that exist,
 * never authority to place a new one elsewhere (D-097, D-119) — so Office is not a field
 * on this page, because it is not a choice.
 *
 * **Ownership is not on this page either.** The chain of title answers to
 * `properties.ownership.update`, its own canonical capability, and is recorded from the
 * parcel's own page once it exists. Folding it in here would let `properties.create`
 * perform an act a separate capability was granted to control.
 */
export default async function NewPropertyPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "properties" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("newProperty")}</h1>
        <p className="text-muted-foreground">{t("newPropertySubtitle")}</p>
      </div>

      <PropertyForm />
    </PageContainer>
  );
}
