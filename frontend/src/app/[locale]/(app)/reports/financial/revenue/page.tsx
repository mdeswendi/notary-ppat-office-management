import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { RevenueReport } from "@/features/reports/revenue-report";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("revenue")}</h1>
        <p className="text-muted-foreground">{t("revenueSubtitle")}</p>
      </div>

      <RevenueReport />
    </PageContainer>
  );
}
