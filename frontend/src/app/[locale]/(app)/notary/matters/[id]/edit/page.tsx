import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("editTitle")}</h1>
        <p className="text-muted-foreground">{t("editDescription")}</p>
      </div>

      <MatterEditForm domain="NOTARY" matterId={id} />
    </PageContainer>
  );
}
