import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { IndividualEditForm } from "@/features/individuals/individual-edit-form";

/**
 * Edit an Individual's ordinary profile.
 *
 * Profile only. Identity is edited from its own section on the detail page,
 * because `parties.update` and `parties.identity.update` are separate
 * capabilities — putting NIK on this form would let the first quietly acquire
 * the second (D-082).
 *
 * There is no Office control: transferring a Party between Offices is not
 * designed, and the backend refuses it (D-080).
 */
export default async function EditIndividualPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "individuals" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("editTitle")}</h1>
        <p className="text-muted-foreground">{t("editDescription")}</p>
      </div>

      <IndividualEditForm individualId={id} />
    </PageContainer>
  );
}
