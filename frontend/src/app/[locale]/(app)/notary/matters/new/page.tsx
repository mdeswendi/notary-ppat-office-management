import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MatterForm } from "@/features/matters/matter-form";

/**
 * Open a new Notary Matter.
 *
 * The form offers no domain, Office, reference, status, or PIC field — each is
 * decided elsewhere and refused outright by the API (D-109), so none is presented
 * as a choice.
 */
export default async function NewNotaryMatterPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <PageHeader title={t("createNotaryTitle")} description={t("createSubtitle")} />

      <MatterForm domain="NOTARY" />
    </PageContainer>
  );
}
