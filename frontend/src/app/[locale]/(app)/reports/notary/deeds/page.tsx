import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { REPORTS } from "@/features/reports/report-definitions";
import { ReportSurface } from "@/features/reports/report-surface";

export default async function ReportPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "reports" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("notaryDeeds")}</h1>
        <p className="text-muted-foreground">{t("notaryDeedsSubtitle")}</p>
      </div>

      <ReportSurface definition={REPORTS["notary.deeds"]} />
    </PageContainer>
  );
}
