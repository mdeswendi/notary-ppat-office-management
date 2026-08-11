import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { SecurityPanel } from "@/features/security/security-panel";

/**
 * Account Security.
 *
 * Inside the authenticated route group, so the existing server-side session
 * check applies unchanged. **No permission guards it** — every signed-in user
 * must be able to change their own password, and gating that behind a
 * `security.*` code would mean an account could be forbidden from securing
 * itself (D-071).
 *
 * A sibling of My Profile rather than a Settings destination: Settings holds
 * administration of other people, and this is the opposite of that. Both are
 * reached from the account menu.
 */
export default async function SecurityPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "security" });

  return (
    <PageContainer>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-muted-foreground">{t("subtitle")}</p>
      </div>

      <SecurityPanel />
    </PageContainer>
  );
}
