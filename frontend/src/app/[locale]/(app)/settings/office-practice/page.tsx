import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { OfficePracticeSettings } from "@/features/office-practice/office-practice-settings";

export default async function OfficePracticePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "officePractice" });

  return (
    <PageContainer>
      <PageHeader title={t("title")} description={t("subtitle")} />
      <OfficePracticeSettings />
    </PageContainer>
  );
}
