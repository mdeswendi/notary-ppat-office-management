import { getTranslations } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";

/**
 * Header presentation foundation.
 *
 * Carries application context and the locale switch only. Global search,
 * quick create, notifications, and the user menu from
 * docs/04_UI_DESIGN_SYSTEM.md section 10 are deliberately absent: rendering
 * them now would mean inert controls that look operational but do nothing.
 * They arrive with M0.9, once there is state behind them.
 */
export async function AppHeader() {
  const t = await getTranslations("common");

  return (
    <header className="bg-card border-border flex h-14 shrink-0 items-center justify-between gap-4 border-b px-4 sm:px-6">
      <span className="truncate text-sm font-semibold tracking-tight">{t("appName")}</span>
      <LocaleSwitcher />
    </header>
  );
}
