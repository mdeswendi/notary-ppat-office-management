import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { DeedSummary } from "@/features/reports/deed-summary";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <PageHeader title={t("ppatSummary")} description={t("summarySubtitle")} />

      <DeedSummary endpoint="/api/v1/reports/ppat/summary" />
    </PageContainer>
  );
}
