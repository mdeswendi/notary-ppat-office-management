import { Suspense } from "react";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { Skeleton } from "@/components/ui/skeleton";
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
    <main className="flex min-h-svh flex-col items-center justify-center px-4 py-12">
      <div className="flex w-full max-w-sm flex-col gap-8">
        <div className="flex flex-col gap-2">
          <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {tCommon("officeLabel")}
          </p>
          <h1 className="text-2xl font-semibold tracking-tight">{t("resetTitle")}</h1>
          <p className="text-muted-foreground text-sm">{t("resetSubtitle")}</p>
        </div>

        <Suspense fallback={<Skeleton className="h-40 w-full" />}>
          <ResetPasswordForm />
        </Suspense>

        <div className="flex justify-center">
          <LocaleSwitcher />
        </div>
      </div>
    </main>
  );
}
