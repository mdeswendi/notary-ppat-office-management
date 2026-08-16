import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { IndividualsList } from "@/features/individuals/individuals-list";

/**
 * The Individual directory.
 *
 * Operational shared-office data, deliberately **not** under `/settings/`:
 * Settings administers the deployment, while this is a tool most staff use
 * daily, and putting it behind an administrative door would misrepresent it.
 *
 * Inside the authenticated route group, so the existing server-side session
 * check applies. `parties.view` at a usable scope is enforced by the API, which
 * remains the security boundary regardless of what navigation shows.
 */
export default async function IndividualsPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "individuals" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <IndividualsList />
    </PageContainer>
  );
}
