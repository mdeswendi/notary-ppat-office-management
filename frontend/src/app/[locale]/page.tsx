import { getTranslations, setRequestLocale } from "next-intl/server";

import { AppShell } from "@/components/layout/app-shell";
import { PageContainer } from "@/components/layout/page-container";

/**
 * M0.6 UI foundation preview.
 *
 * Exercises AppShell, Sidebar, Header, PageContainer, and the locale switch.
 * Intentionally holds no statistics, charts, or business records: the real
 * dashboard is built once the system has meaningful data to show.
 */
export default async function HomePage({ params }: PageProps<"/[locale]">) {
  const { locale } = await params;

  setRequestLocale(locale);

  const t = await getTranslations("dashboard");
  const tCommon = await getTranslations("common");

  return (
    <AppShell>
      <PageContainer>
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
          <p className="text-muted-foreground">{t("foundationSubtitle")}</p>
        </div>
        <p className="text-muted-foreground max-w-prose">{tCommon("foundationNotice")}</p>
      </PageContainer>
    </AppShell>
  );
}
