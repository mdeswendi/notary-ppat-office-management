import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { RolesList } from "@/features/roles/roles-list";

/**
 * Role Management.
 *
 * Authentication is handled by the `(app)` layout, so this page carries no
 * session logic. Authorization is the API's: role administration needs a
 * canonical `roles.*` permission at the `ALL` Data Scope, which the browser
 * cannot evaluate, so the list asks and renders a forbidden state if refused.
 *
 * Reached by direct URL. The sidebar is not touched here — permission-aware
 * navigation is its own milestone, and adding an always-visible Settings entry
 * would show every user a link most of them cannot use.
 */
export default async function RolesPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "roles" });

  return (
    <PageContainer>
      <PageHeader title={t("title")} description={t("subtitle")} />

      <RolesList />
    </PageContainer>
  );
}
