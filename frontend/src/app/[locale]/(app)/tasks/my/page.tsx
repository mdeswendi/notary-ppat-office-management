import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
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
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("myTasks")}</h1>
        <p className="text-muted-foreground">{t("myTasksSubtitle")}</p>
      </div>

      <MyTasksList />
    </PageContainer>
  );
}
