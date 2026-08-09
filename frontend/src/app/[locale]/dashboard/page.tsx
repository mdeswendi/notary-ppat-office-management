import { getTranslations } from "next-intl/server";

import { PageContainer } from "@/components/layout/page-container";
import { LogoutButton } from "@/features/auth/logout-button";
import { redirect } from "@/i18n/navigation";
import { fetchCurrentUser } from "@/lib/auth/session";

/**
 * Authentication verification route.
 *
 * This is NOT the M0.9 application shell — no sidebar, header, search, or
 * account menu. It exists to prove that a session grants access and that its
 * absence does not.
 *
 * The route is dynamic by construction: it reads cookies and asks Laravel on
 * every request, so a stale or invalidated session cannot be served from a
 * cache. `setRequestLocale` is intentionally not called here; static rendering
 * would defeat the point of a protected page.
 */
export default async function DashboardPage({ params }: PageProps<"/[locale]/dashboard">) {
  const { locale } = await params;

  const user = await fetchCurrentUser();

  if (user === null) {
    // Returned rather than called bare: `redirect` is typed `never`, but
    // TypeScript does not narrow through a destructured const, so the explicit
    // return is what tells it `user` is non-null below.
    return redirect({ href: "/login", locale });
  }

  const t = await getTranslations({ locale, namespace: "dashboard" });
  const tAuth = await getTranslations({ locale, namespace: "auth" });

  return (
    <PageContainer>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
          <p className="text-muted-foreground">{t("foundationSubtitle")}</p>
        </div>
        <LogoutButton />
      </div>

      <p className="text-muted-foreground">
        {tAuth("signedInAs")} <span className="text-foreground font-medium">{user.name}</span>
      </p>
    </PageContainer>
  );
}
