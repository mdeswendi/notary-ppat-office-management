import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { QuotationList } from "@/features/billing/quotation-list";

export default async function BillingPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "billing" });

  return (
    <PageContainer>
      <PageHeader title={t("quotations")} description={t("quotationsSubtitle")} />

      <QuotationList />
    </PageContainer>
  );
}
