import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("completedTasks")}</h1>
        <p className="text-muted-foreground">{t("completedTasksSubtitle")}</p>
      </div>

      <TasksList
        fixedFilter={{ status: "COMPLETED" }}
        emptyTitleKey="noCompletedTitle"
        emptyDescriptionKey="noCompletedTasks"
      />
    </PageContainer>
  );
}
