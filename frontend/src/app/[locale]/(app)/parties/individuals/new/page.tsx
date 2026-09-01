import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { IndividualForm } from "@/features/individuals/individual-form";

/**
 * Create an Individual.
 *
 * The form carries no identity fields: NIK and NPWP belong to the identity
 * surface under their own permission, and the backend rejects them here
 * (D-082). A new record therefore starts without identity, which is added
 * afterwards by somebody holding `parties.identity.update`.
 */
export default async function NewIndividualPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "individuals" });

  return (
    <PageContainer>
      <PageHeader title={t("createTitle")} description={t("createDescription")} />

      <IndividualForm />
    </PageContainer>
  );
}
