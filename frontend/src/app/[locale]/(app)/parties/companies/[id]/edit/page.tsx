import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { CompanyEditForm } from "@/features/companies/company-edit-form";

/**
 * Edit a Company's ordinary profile.
 *
 * Profile only. Tax identity is edited from its own section on the detail page,
 * because `companies.update` and `parties.identity.update` are separate
 * capabilities — putting `tax_id` on this form would let the first quietly
 * acquire the second (D-082).
 *
 * There is no Office control: transferring a Party between Offices is not
 * designed, and the backend refuses it (D-080).
 */
export default async function EditCompanyPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "companies" });

  return (
    <PageContainer>
      <PageHeader title={t("editTitle")} description={t("editDescription")} />

      <CompanyEditForm companyId={id} />
    </PageContainer>
  );
}
