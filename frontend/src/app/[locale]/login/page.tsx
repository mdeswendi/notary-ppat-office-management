import { getTranslations, setRequestLocale } from "next-intl/server";

import { AuthShell } from "@/components/layout/auth-shell";
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
    <AuthShell
      officeLabel={tCommon("officeLabel")}
      title={t("signIn")}
      description={t("signInSubtitle")}
    >
      <LoginForm />
    </AuthShell>
  );
}
