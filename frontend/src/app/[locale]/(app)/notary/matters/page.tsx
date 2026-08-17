import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("notaryTitle")}</h1>
        <p className="text-muted-foreground">{t("notarySubtitle")}</p>
      </div>

      <MattersList domain="NOTARY" />
    </PageContainer>
  );
}
