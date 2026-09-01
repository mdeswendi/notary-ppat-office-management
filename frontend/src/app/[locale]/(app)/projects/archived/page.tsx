import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { ArchivedProjectsList } from "@/features/projects/archived-projects-list";

/**
 * Archived Projects.
 *
 * **Its own page under `projects.restore`**, not a filter on the ordinary list.
 * Widening the Project list to include soft-deleted rows would expose archived
 * work to everyone holding `projects.view` — a much larger group than those who
 * may restore, and one nobody granted archive-visibility to (D-093).
 *
 * The route sits at `/projects/archived`, which matches the API's own ordering
 * concern: `projects/{project}` must not swallow the literal path.
 */
export default async function ArchivedProjectsPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "projects" });

  return (
    <PageContainer>
      <PageHeader title={t("archivedTitle")} description={t("archivedSubtitle")} />

      <ArchivedProjectsList />
    </PageContainer>
  );
}
