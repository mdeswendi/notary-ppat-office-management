import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { MyTasksList } from "@/features/tasks/my-tasks-list";

/**
 * The caller's own live work.
 *
 * The list is a client component because the filter is "assigned to me", and the
 * signed-in user's id is only known once `/api/v1/me` answers.
 */
export default async function MyTasksPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "tasks" });

  return (
    <PageContainer>
      <PageHeader title={t("myTasks")} description={t("myTasksSubtitle")} />

      <MyTasksList />
    </PageContainer>
  );
}
