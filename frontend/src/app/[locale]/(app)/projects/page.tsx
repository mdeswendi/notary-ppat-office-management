import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <ProjectsList />
    </PageContainer>
  );
}
