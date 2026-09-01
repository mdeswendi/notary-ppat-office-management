import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { TasksList } from "@/features/tasks/tasks-list";

/**
 * Work that is finished.
 *
 * `COMPLETED` only, not "settled": cancelled work was called off rather than done,
 * and folding the two together would make this page answer a different question
 * from the one its name asks. Cancelled tasks remain reachable from the All Tasks
 * status filter.
 *
 * The status is fixed, so the list offers no status control — a dropdown that
 * cannot change what you see is worse than no dropdown.
 */
export default async function CompletedTasksPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "tasks" });

  return (
    <PageContainer>
      <PageHeader title={t("completedTasks")} description={t("completedTasksSubtitle")} />

      <TasksList
        fixedFilter={{ status: "COMPLETED" }}
        emptyTitleKey="noCompletedTitle"
        emptyDescriptionKey="noCompletedTasks"
      />
    </PageContainer>
  );
}
