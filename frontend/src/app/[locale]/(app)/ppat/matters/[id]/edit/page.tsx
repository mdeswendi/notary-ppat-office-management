import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("editTitle")}</h1>
        <p className="text-muted-foreground">{t("editDescription")}</p>
      </div>

      <MatterEditForm domain="PPAT" matterId={id} />
    </PageContainer>
  );
}
