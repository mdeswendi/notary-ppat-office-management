import { Suspense } from "react";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { AuthShell } from "@/components/layout/auth-shell";
import { Skeleton } from "@/components/ui/skeleton";
import { publicOfficeBrand } from "@/config/public-brand";
import { ResetPasswordForm } from "@/features/security/reset-password-form";

/**
 * Where an emailed password-reset link lands.
 *
 * Outside the authenticated route group — somebody resetting a password by
 * definition cannot sign in. The token in the URL is the only credential, which
 * is why the backend route is rate limited and the token is single use.
 *
 * Completing the reset creates no session (D-072); the page offers the login
 * screen instead.
 */
export default async function ResetPasswordPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  setRequestLocale(locale);

  const t = await getTranslations({ locale, namespace: "security" });
  const tCommon = await getTranslations({ locale, namespace: "common" });

  return (
    <AuthShell
      officeLabel={publicOfficeBrand(tCommon("officeLabel"))}
      title={t("resetTitle")}
      description={t("resetSubtitle")}
    >
      <Suspense fallback={<Skeleton className="h-40 w-full" />}>
        <ResetPasswordForm />
      </Suspense>
    </AuthShell>
  );
}
