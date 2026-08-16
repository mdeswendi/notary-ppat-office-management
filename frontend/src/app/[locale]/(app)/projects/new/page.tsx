import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { ProjectForm } from "@/features/projects/project-form";

/**
 * Create a Project.
 *
 * No Office selector, no reference input, and no status choice: a Project is
 * created in the actor's own Office, its reference is allocated server-side, and
 * it starts OPEN (D-097). The API refuses each of those fields outright, so the
 * form does not offer a choice that does not exist.
 */
export default async function NewProjectPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "projects" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createTitle")}</h1>
        <p className="text-muted-foreground">{t("createDescription")}</p>
      </div>

      <ProjectForm />
    </PageContainer>
  );
}
