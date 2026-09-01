import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { ProjectsList } from "@/features/projects/projects-list";

/**
 * The Project list — the canonical M3 product surface.
 *
 * Inside the authenticated route group, so the existing server-side session
 * check applies. `projects.view` at a usable scope is enforced by the API, which
 * remains the security boundary regardless of what navigation shows.
 *
 * Archived Projects live on their own page under `projects.restore`, not behind
 * a toggle here (D-093).
 */
export default async function ProjectsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "projects" });

  return (
    <PageContainer>
      <PageHeader title={t("title")} description={t("subtitle")} />

      <ProjectsList />
    </PageContainer>
  );
}
