import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { PageHeader } from "@/components/layout/page-header";
import { UsersList } from "@/features/users/users-list";

/**
 * User Management.
 *
 * Authentication is handled by the `(app)` layout, so this page carries no
 * session logic. Authorization is the API's: which accounts a caller may see
 * and administer depends on a canonical `users.*` permission and its Data
 * Scope, which the browser cannot evaluate, so the list asks and renders a
 * forbidden state if refused.
 *
 * Reached by direct URL. The sidebar is untouched — permission-aware navigation
 * is its own milestone, and an always-visible Settings entry would show every
 * user a link most of them cannot use.
 */
export default async function UsersPage({ params }: { params: Promise<{ locale: string }> }) {
  const { locale } = await params;

  const t = await getTranslations({ locale, namespace: "users" });

  return (
    <PageContainer>
      <PageHeader title={t("title")} description={t("subtitle")} />

      <UsersList />
    </PageContainer>
  );
}
