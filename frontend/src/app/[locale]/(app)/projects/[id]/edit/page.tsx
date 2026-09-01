import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { ProjectEditForm } from "@/features/projects/project-edit-form";

/**
 * Edit a Project's ordinary attributes.
 *
 * Not the status and not the person in charge — each answers to its own
 * capability and has its own control on the detail page (D-091). Not the Office
 * or the reference either: both are immutable, and the API refuses them.
 */
export default async function EditProjectPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { locale, id } = await params;

  const t = await getTranslations({ locale, namespace: "projects" });

  return (
    <PageContainer>
      <PageHeader title={t("editTitle")} description={t("editDescription")} />

      <ProjectEditForm projectId={id} />
    </PageContainer>
  );
}
