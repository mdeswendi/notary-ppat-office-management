import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { DashboardHero } from "@/features/dashboard/dashboard-hero";
import { LatestPpatMattersWidget } from "@/features/dashboard/latest-ppat-matters-widget";
import { ProfessionalCollaboratorsWidget } from "@/features/dashboard/professional-collaborators-widget";
import { TodayScheduleWidget } from "@/features/dashboard/today-schedule-widget";
import { StatsCards } from "@/features/dashboard/stats-cards";

/**
 * Dashboard (M8.1, D-122).
 *
 * Authentication is handled by the `(app)` layout, so this page carries no
 * session logic of its own.
 *
 * ## There is no role check here, and there is no layout variant
 *
 * The M8.1 brief specified two layouts — one for staff, another for
 * principal/manager. That would be role-name branching, which `AGENTS.md` §27 and
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
    <PageContainer className="gap-6">
      <DashboardHero currentDate={new Date().toISOString()} />

      <section aria-labelledby="dashboard-summary" className="flex flex-col gap-3">
        <div>
          <h2 id="dashboard-summary" className="text-lg font-semibold tracking-tight">
            {t("summaryTitle")}
          </h2>
          <p className="text-muted-foreground mt-0.5 text-sm">{t("subtitle")}</p>
        </div>
        <StatsCards />
      </section>

      {/* Two columns on wide screens, stacking on narrow ones. Desktop-first, but
          the office reads this on a laptop and sometimes a tablet (§50). */}
      <div
        id="latest-matters"
        className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,22rem)]"
      >
        <div className="min-w-0">
          <LatestPpatMattersWidget />
        </div>
        <div className="grid min-w-0 items-start gap-6">
          <TodayScheduleWidget />
          <ProfessionalCollaboratorsWidget />
        </div>
      </div>

      <footer className="border-border/60 text-muted-foreground mt-2 border-t pt-5 text-center text-xs">
        {t("footerCopyright", { year: new Date().getFullYear() })} · {t("footerTagline")}
      </footer>
    </PageContainer>
  );
}
