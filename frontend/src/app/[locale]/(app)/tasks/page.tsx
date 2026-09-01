import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
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
      <PageHeader title={t("allTasks")} description={t("allTasksSubtitle")} />

      <TasksList />
    </PageContainer>
  );
}
