import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MattersList } from "@/features/matters/matters-list";

/**
 * The Notary Matter list.
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `notary.matters.view` at a usable scope is enforced by the API, which
 * remains the security boundary regardless of what navigation shows.
 *
 * **The domain comes from the address**, and this page is the one place it is
 * stated for the Notary surface (D-101). Nothing downstream infers it from data.
 */
export default async function NotaryMattersPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <PageHeader title={t("notaryTitle")} description={t("notarySubtitle")} />

      <MattersList domain="NOTARY" />
    </PageContainer>
  );
}
