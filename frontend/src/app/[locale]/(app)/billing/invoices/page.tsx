import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { InvoiceList } from "@/features/billing/invoice-list";

export default async function BillingPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "billing" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("invoices")}</h1>
        <p className="text-muted-foreground">{t("invoicesSubtitle")}</p>
      </div>

      <InvoiceList />
    </PageContainer>
  );
}
