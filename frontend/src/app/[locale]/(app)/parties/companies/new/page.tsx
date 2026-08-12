import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createTitle")}</h1>
        <p className="text-muted-foreground">{t("createDescription")}</p>
      </div>

      <CompanyForm />
    </PageContainer>
  );
}
