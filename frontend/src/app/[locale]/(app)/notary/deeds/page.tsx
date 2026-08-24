import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { DeedsList } from "@/features/notary/deeds-list";

/**
 * Every Notarial Deed the caller may see (M6.2, D-120).
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `notary.deeds.view` at a usable scope is enforced by the API, which
 * remains the security boundary regardless of what navigation shows.
 *
 * **One root, not two.** Unlike Matter there is no PPAT variant: `notary.deeds.*` is
 * a Notary-only namespace, and PPAT deeds are a different table in a different
 * milestone.
 */
export default async function NotaryDeedsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "notary" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("deeds")}</h1>
        <p className="text-muted-foreground">{t("deedsSubtitle")}</p>
      </div>

      <DeedsList />
    </PageContainer>
  );
}
