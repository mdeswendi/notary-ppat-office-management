import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createTitle")}</h1>
        <p className="text-muted-foreground">{t("createDescription")}</p>
      </div>

      <IndividualForm />
    </PageContainer>
  );
}
