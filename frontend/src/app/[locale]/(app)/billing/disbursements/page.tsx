import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { DisbursementList } from "@/features/billing/disbursement-list";

export default async function BillingPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "billing" });

  return (
    <PageContainer>
      <PageHeader title={t("disbursements")} description={t("disbursementsSubtitle")} />

      <DisbursementList />
    </PageContainer>
  );
}
