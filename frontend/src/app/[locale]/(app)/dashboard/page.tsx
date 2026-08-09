import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";

/**
 * Dashboard placeholder.
 *
 * Authentication is handled by the `(app)` layout, so this page carries no
 * session logic of its own.
 *
 * Intentionally empty of data. There are no matters, projects, parties, or
 * documents in the system yet, so any counter, chart, deadline, or activity
 * feed here would be invented. The real dashboard in
 * docs/04_UI_DESIGN_SYSTEM.md section 16 is built once those modules exist and
 * there is something true to show.
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

      <p className="text-muted-foreground max-w-prose">{t("placeholderBody")}</p>
    </PageContainer>
  );
}
