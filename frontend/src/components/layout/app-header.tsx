import { Scale } from "lucide-react";
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
 *
 * ## The identity is a mark and a name, not a name alone
 *
 * The bar previously held the application's name as one line of 14px text and
 * nothing else on the left, while the locale switcher spelled out "Bahasa
 * Indonesia" and "English" on the right — so the visually heaviest thing in the
 * header was the control an office touches least. The mark anchors the left, and
 * the switcher is a two-letter segment now.
 *
 * **The name is still the product's, not the office's.** Showing *Kantor Notaris
 * & PPAT Mila Widyahastuti* here would be better, and it is not possible yet:
 * `/api/v1/me` carries no office, so the browser has nothing to render. That is a
 * small backend addition rather than a layout question, and it is not smuggled in
 * here.
 */
export async function AppHeader({ user }: { user: CurrentUser }) {
  const t = await getTranslations("common");

  return (
    <header className="bg-card border-border flex h-14 shrink-0 items-center gap-2 border-b px-4 sm:px-6">
      <MobileNav user={user} />

      <div className="flex min-w-0 items-center gap-2.5">
        <span
          aria-hidden="true"
          className="bg-primary text-primary-foreground grid size-7 shrink-0 place-items-center rounded-md"
        >
          <Scale className="size-4" />
        </span>
        <span className="truncate text-sm font-semibold tracking-tight">{t("appName")}</span>
      </div>

      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <UserMenu user={user} />
      </div>
    </header>
  );
}
