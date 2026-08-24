import { PageContainer } from "@/components/layout/page-container";
import { TaskDetail } from "@/features/tasks/task-detail";

/**
 * One Task.
 *
 * The heading lives in the client component because it renders the title, which is
 * only known once the record loads — and the record may legitimately be
 * unreachable: one outside the caller's Data Scope answers **404** rather than
 * 403, because telling an unreachable Task apart from a nonexistent one would
 * confirm the record exists somewhere the caller may not look.
 */
export default async function TaskDetailPage({
  params,
}: {
  params: Promise<{ locale: string; id: string }>;
}) {
  const { id } = await params;

  return (
    <PageContainer>
      <TaskDetail taskId={id} />
    </PageContainer>
  );
}
