import { getTranslations, setRequestLocale } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { LoginForm } from "@/features/auth/login-form";

/**
 * Sign-in screen.
 *
 * Carries only what is implemented. Password reset, MFA, registration, and SSO
 * are not part of M0.7, so no link to them is rendered — a dead control is
 * worse than an absent one.
 */
export default async function LoginPage({ params }: PageProps<"/[locale]/login">) {
  const { locale } = await params;

  setRequestLocale(locale);

  const t = await getTranslations("auth");
  const tCommon = await getTranslations("common");

  return (
    <main className="flex min-h-svh flex-col items-center justify-center px-4 py-12">
      <div className="flex w-full max-w-sm flex-col gap-8">
        <div className="flex flex-col gap-2">
          <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {tCommon("officeLabel")}
          </p>
          <h1 className="text-2xl font-semibold tracking-tight">{t("signIn")}</h1>
        </div>

        <LoginForm />

        <div className="flex justify-center">
          <LocaleSwitcher />
        </div>
      </div>
    </main>
  );
}
