import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createNotaryTitle")}</h1>
        <p className="text-muted-foreground">{t("createSubtitle")}</p>
      </div>

      <MatterForm domain="NOTARY" />
    </PageContainer>
  );
}
