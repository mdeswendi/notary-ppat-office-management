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
 * **It names the office, not the product.** A person opening this every morning
 * is looking at their own office's system, and *Kantor Notaris & PPAT Mila
 * Widyahastuti, S.H., M.Kn.* says that where *Notary & PPAT Office Management
 * System* says only what the software is.
 *
 * The name is **read from the account's own Office record**, never written here.
 * A second office deploying this sees its own name with no code change, and a
 * hard-coded string would be a lie on the first day that happened.
 *
 * The application name remains the fallback, because `office` is genuinely
 * nullable — the backend sends `null` when the relation was not loaded — and a
 * blank header would be worse than a generic one.
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
        <span className="truncate text-sm font-semibold tracking-tight">
          {user.office?.name ?? t("appName")}
        </span>
      </div>

      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <UserMenu user={user} />
      </div>
    </header>
  );
}
