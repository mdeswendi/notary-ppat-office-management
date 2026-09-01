import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { CompanyForm } from "@/features/companies/company-form";

/**
 * Create a Company.
 *
 * The form carries no tax identity field: `tax_id` belongs to the identity
 * surface under its own permission, and the backend rejects it here (D-082). A
 * new record therefore starts without a tax identifier, which is added
 * afterwards by somebody holding `parties.identity.update`.
 */
export default async function NewCompanyPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "companies" });

  return (
    <PageContainer>
      <PageHeader title={t("createTitle")} description={t("createDescription")} />

      <CompanyForm />
    </PageContainer>
  );
}
