import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { MatterForm } from "@/features/matters/matter-form";

/**
 * Open a new PPAT Matter.
 */
export default async function NewPpatMatterPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createPpatTitle")}</h1>
        <p className="text-muted-foreground">{t("createSubtitle")}</p>
      </div>

      <MatterForm domain="PPAT" />
    </PageContainer>
  );
}
