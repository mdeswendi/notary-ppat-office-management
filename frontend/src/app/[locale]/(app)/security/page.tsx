import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
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
      <PageHeader title={t("title")} description={t("subtitle")} />

      <SecurityPanel />
    </PageContainer>
  );
}
