import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MatterEditForm } from "@/features/matters/matter-edit-form";

/**
 * Edit a PPAT Matter's ordinary attributes.
 */
export default async function EditPpatMatterPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <PageHeader title={t("editTitle")} description={t("editDescription")} />

      <MatterEditForm domain="PPAT" matterId={id} />
    </PageContainer>
  );
}
