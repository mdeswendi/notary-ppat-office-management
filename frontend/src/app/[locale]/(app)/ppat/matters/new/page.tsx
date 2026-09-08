import { getTranslations } from "next-intl/server";

import { PageBackLink } from "@/components/layout/page-back-link";
import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
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
      <PageHeader
        title={t("createPpatTitle")}
        description={t("createSubtitle")}
        breadcrumb={<PageBackLink href="/ppat/matters" />}
      />

      <MatterForm domain="PPAT" />
    </PageContainer>
  );
}
