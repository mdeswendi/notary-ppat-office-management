import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { PpatDeedForm } from "@/features/ppat/deed-form";

/**
 * Record a PPAT Deed (M7.2, D-121).
 *
 * The deed lands in its Matter's Office and starts as DRAFT — neither is a field on
 * this page, because neither is a choice.
 *
 * `matter_id` arrives as a search parameter when the deed is recorded from a Matter
 * page. It is pre-filled and locked rather than editable then: re-parenting is refused
 * by the API, so offering a picker would present a choice that does not exist.
 */
export default async function NewPpatDeedPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ matter_id?: string }>;
}) {
  const { locale } = await params;
  const { matter_id: matterId } = await searchParams;

  const t = await getTranslations({ locale, namespace: "ppat" });

  return (
    <PageContainer>
      <PageHeader title={t("newDeed")} description={t("newDeedSubtitle")} />

      <PpatDeedForm matterId={matterId} />
    </PageContainer>
  );
}
