import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { REPORTS } from "@/features/reports/report-definitions";
import { ReportSurface } from "@/features/reports/report-surface";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <PageHeader title={t("documents")} description={t("documentsSubtitle")} />

      <ReportSurface definition={REPORTS["operational.documents"]} />
    </PageContainer>
  );
}
