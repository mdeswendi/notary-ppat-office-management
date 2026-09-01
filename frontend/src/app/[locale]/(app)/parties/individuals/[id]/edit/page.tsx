import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
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
      <PageHeader title={t("editTitle")} description={t("editDescription")} />

      <IndividualEditForm individualId={id} />
    </PageContainer>
  );
}
