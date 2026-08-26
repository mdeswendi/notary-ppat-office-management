import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { DeedSummary } from "@/features/reports/deed-summary";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("notarySummary")}</h1>
        <p className="text-muted-foreground">{t("summarySubtitle")}</p>
      </div>

      <DeedSummary endpoint="/api/v1/reports/notary/summary" />
    </PageContainer>
  );
}
