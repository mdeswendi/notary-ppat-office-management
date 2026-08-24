import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { TaskForm } from "@/features/tasks/task-form";

/**
 * Raise a Task.
 *
 * The task lands in the actor's own Office and starts as OPEN — neither is a field
 * on this page, because neither is a choice (D-119).
 *
 * `project_id` and `matter_id` arrive as search parameters when the work is raised
 * from a Project or Matter page. They are pre-filled rather than editable here:
 * re-parenting is refused by the API, so offering a picker would present a choice
 * that does not exist.
 */
export default async function NewTaskPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ project_id?: string; matter_id?: string }>;
}) {
  const { locale } = await params;
  const { project_id: projectId, matter_id: matterId } = await searchParams;

  const t = await getTranslations({ locale, namespace: "tasks" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("newTask")}</h1>
        <p className="text-muted-foreground">{t("newTaskSubtitle")}</p>
      </div>

      <TaskForm projectId={projectId} matterId={matterId} />
    </PageContainer>
  );
}
