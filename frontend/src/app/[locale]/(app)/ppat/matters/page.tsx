import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { MattersList } from "@/features/matters/matters-list";

/**
 * The PPAT Matter list.
 *
 * The mirror of the Notary page, and deliberately a separate address rather than
 * one screen with a domain switch: the domain selects the permission namespace
 * (D-101), and an actor may hold one domain's capabilities and not the other's.
 */
export default async function PpatMattersPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("ppatTitle")}</h1>
        <p className="text-muted-foreground">{t("ppatSubtitle")}</p>
      </div>

      <MattersList domain="PPAT" />
    </PageContainer>
  );
}
