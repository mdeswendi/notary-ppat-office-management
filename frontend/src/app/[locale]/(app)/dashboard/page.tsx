import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { MyTasksWidget } from "@/features/tasks/my-tasks-widget";

/**
 * Dashboard.
 *
 * Authentication is handled by the `(app)` layout, so this page carries no session
 * logic of its own.
 *
 * **The first real panel arrives at M5.4** — the caller's own live work, sorted by
 * what is due soonest. Until now this page was deliberately empty: there were no
 * matters, projects, parties or documents, so any counter, chart or activity feed
 * would have been invented, which `10_M0_FOUNDATION.md` section 57 forbids.
 *
 * That rule is why there is exactly one panel and not a row of tiles. A task queue
 * is something the office genuinely has and genuinely needs to see; counts of
 * things nobody has asked to count are still fabrication. The widget renders
 * **nothing at all** for somebody without `tasks.view`, rather than an empty card
 * that would be permanently blank for a whole role.
 *
 * The fuller dashboard in `04_UI_DESIGN_SYSTEM.md` section 16 is built when the
 * modules behind it exist and there is more that is true to show.
 */
export default async function DashboardPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "dashboard" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("foundationSubtitle")}</p>
      </div>

      <MyTasksWidget />

      <p className="text-muted-foreground max-w-prose">{t("placeholderBody")}</p>
    </PageContainer>
  );
}
