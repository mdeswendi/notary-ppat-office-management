import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { PpatDeedsList } from "@/features/ppat/deeds-list";

/**
 * Every PPAT Deed the caller may see (M7.2, D-121).
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `ppat.deeds.view` at a usable scope is enforced by the API, which remains
 * the security boundary regardless of what navigation shows.
 *
 * **One root, not two.** Unlike Matter there is no Notary variant here:
 * `ppat.deeds.*` is a PPAT-only namespace, and a Notarial Deed is a different table
 * behind `/notary/deeds`.
 */
export default async function PpatDeedsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "ppat" });

  return (
    <PageContainer>
      <PageHeader title={t("deeds")} description={t("deedsSubtitle")} />

      <PpatDeedsList />
    </PageContainer>
  );
}
