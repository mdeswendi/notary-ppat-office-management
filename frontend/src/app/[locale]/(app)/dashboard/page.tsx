import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { ActivityWidget } from "@/features/dashboard/activity-widget";
import { NeedsAttentionWidget } from "@/features/dashboard/needs-attention-widget";
import { StatsCards } from "@/features/dashboard/stats-cards";
import { TasksWidget } from "@/features/dashboard/tasks-widget";
import { WorkloadWidget } from "@/features/dashboard/workload-widget";

/**
 * Dashboard (M8.1, D-122).
 *
 * Authentication is handled by the `(app)` layout, so this page carries no
 * session logic of its own.
 *
 * ## There is no role check here, and there is no layout variant
 *
 * The M8.1 brief specified two layouts — one for staff, another for
 * principal/manager. That would be role-name branching, which `CLAUDE.md` §27 and
 * D-048 rule out, and it would also be brittle: who holds which role is
 * configuration an office changes.
 *
 * **Composition does the same job better.** Every panel gates itself on the
 * capability of the resource it summarises, and renders `null` when the caller
 * holds none. A member of staff sees their queue and what is stalled; somebody
 * who can read users additionally sees workload; somebody who can read deeds
 * additionally sees those. The page is the union of what the reader may know,
 * and it arrives at the two layouts the brief described without asserting who
 * anybody is.
 *
 * An actor holding nothing sees the heading and no panels — correct behaviour,
 * not an error state (D-122).
 *
 * The `MyTasksWidget` M5.4 put here is superseded by `TasksWidget`, which answers
 * the same question in the three buckets the office actually asks about.
 */
export default async function DashboardPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "dashboard" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <StatsCards />

      {/* Two columns on wide screens, stacking on narrow ones. Desktop-first, but
          the office reads this on a laptop and sometimes a tablet (§50). */}
      <div className="grid gap-4 lg:grid-cols-2">
        <TasksWidget />
        <NeedsAttentionWidget />
        <WorkloadWidget />
        <ActivityWidget />
      </div>
    </PageContainer>
  );
}
