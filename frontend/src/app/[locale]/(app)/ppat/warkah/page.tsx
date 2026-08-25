import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { WarkahList } from "@/features/warkah/warkah-list";

/**
 * Every Warkah the caller may see (M7.4, D-121).
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `ppat.warkah.view` at a usable scope is enforced by the API, which remains
 * the security boundary regardless of what navigation shows.
 *
 * **A list, not a create surface.** A Warkah is the supporting bundle *of one deed* and
 * has no independent existence — it is started from the deed's own page by adding the
 * first line, because the catalogue has no `ppat.warkah.create` for a separate act to
 * answer to. Every row here links to its deed.
 *
 * The page exists because one question genuinely needs it: *which bundles are still
 * short?* That is an office-wide question a per-deed page cannot answer.
 */
export default async function WarkahPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "warkah" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        {/* Indonesian legal terminology, used exactly as written. */}
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <WarkahList />
    </PageContainer>
  );
}
