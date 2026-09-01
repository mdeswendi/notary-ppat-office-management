import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MatterEditForm } from "@/features/matters/matter-edit-form";

/**
 * Edit a Notary Matter's ordinary attributes.
 *
 * Not the status, not the person in charge — each answers to its own capability
 * and has its own control on the detail page (D-109). Not the Office, the domain,
 * the parent Project, or the reference either: all four are immutable, and the
 * API refuses them.
 */
export default async function EditNotaryMatterPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <PageHeader title={t("editTitle")} description={t("editDescription")} />

      <MatterEditForm domain="NOTARY" matterId={id} />
    </PageContainer>
  );
}
