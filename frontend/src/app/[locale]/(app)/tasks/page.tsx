import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { TasksList } from "@/features/tasks/tasks-list";

/**
 * Every Task the caller may see.
 *
 * Inside the authenticated route group, so the existing server-side session check
 * applies. `tasks.view` at a usable scope is enforced by the API, which remains
 * the security boundary regardless of what navigation shows.
 *
 * **One surface, not two.** Unlike Matter there is no Notary or PPAT variant:
 * `tasks.*` is a single canonical namespace with no domain split.
 */
export default async function TasksPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "tasks" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("allTasks")}</h1>
        <p className="text-muted-foreground">{t("allTasksSubtitle")}</p>
      </div>

      <TasksList />
    </PageContainer>
  );
}
