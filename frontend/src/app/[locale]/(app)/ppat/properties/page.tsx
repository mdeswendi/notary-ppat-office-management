import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PropertiesList } from "@/features/properties/properties-list";

/**
 * Every land object the caller may see (M7.3, D-121).
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `properties.view` at a usable scope is enforced by the API, which remains the
 * security boundary regardless of what navigation shows.
 *
 * **The page is under `/ppat/` and the API is not.** `CLAUDE.md` section 16 lists
 * Property among the PPAT-specific concepts, which is why this sits in the PPAT group;
 * the canonical capability family is `properties.*` with no `ppat.` prefix, which is why
 * the endpoint is `/api/v1/properties`. A page path is not a permission namespace, so
 * the two are consistent — but the asymmetry is deliberate rather than an oversight.
 */
export default async function PropertiesPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "properties" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("properties")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <PropertiesList />
    </PageContainer>
  );
}
