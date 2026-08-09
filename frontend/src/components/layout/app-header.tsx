import { getTranslations } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { MobileNav } from "@/components/layout/mobile-nav";
import { UserMenu } from "@/components/layout/user-menu";
import type { CurrentUser } from "@/types/auth";

/**
 * Application header: navigation trigger, application context, locale switch,
 * account menu.
 *
 * Global search, quick create, and notifications from
 * docs/04_UI_DESIGN_SYSTEM.md section 10 are **reserved slots, not built**.
 * Each depends on modules that do not exist — there is nothing to search, no
 * record type to create, and no event to notify about. Rendering them disabled
 * would be dead UI that invites "why is this greyed out?"; rendering them
 * enabled would be a lie. They belong in the header the moment the first
 * module gives them something to do.
 */
export async function AppHeader({ user }: { user: CurrentUser }) {
  const t = await getTranslations("common");

  return (
    <header className="bg-card border-border flex h-14 shrink-0 items-center gap-2 border-b px-4 sm:px-6">
      <MobileNav user={user} />

      <span className="truncate text-sm font-semibold tracking-tight">{t("appName")}</span>

      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <UserMenu user={user} />
      </div>
    </header>
  );
}
