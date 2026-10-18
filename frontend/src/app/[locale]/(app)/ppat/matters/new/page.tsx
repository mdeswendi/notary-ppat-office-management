import { getTranslations } from "next-intl/server";

import { PageBackLink } from "@/components/layout/page-back-link";
import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MatterForm } from "@/features/matters/matter-form";

/**
 * Open a new PPAT Matter.
 */
export default async function NewPpatMatterPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ project_id?: string }>;
}) {
  const { locale } = await params;
  const { project_id: projectId } = await searchParams;

  const t = await getTranslations({ locale, namespace: "matters" });

  return (
    <PageContainer>
      <PageHeader
        title={t("createPpatTitle")}
        description={t("createSubtitle")}
        breadcrumb={<PageBackLink href={projectId ? `/projects/${projectId}` : "/ppat/matters"} />}
      />

      <MatterForm domain="PPAT" initialProjectId={projectId} />
    </PageContainer>
  );
}
