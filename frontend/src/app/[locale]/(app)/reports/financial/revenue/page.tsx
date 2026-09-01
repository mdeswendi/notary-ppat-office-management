import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { RevenueReport } from "@/features/reports/revenue-report";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <PageHeader title={t("revenue")} description={t("revenueSubtitle")} />

      <RevenueReport />
    </PageContainer>
  );
}
