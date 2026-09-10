import { Scale } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { MobileNav } from "@/components/layout/mobile-nav";
import { UserMenu } from "@/components/layout/user-menu";
import { Link } from "@/i18n/navigation";
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
 * **It names the Organization and Office, not the product.** A person opening
 * this every morning needs both contexts: whose legal-office deployment this is
 * on the first line, and which operating location their account belongs to on
 * the second. Folding both into `offices.name` loses that distinction and makes
 * every branch repeat the Organization's identity.
 *
 * Both names are **read through the account's own Office record**, never written
 * here. A second Office sees the same Organization with its own location below;
 * another deployment sees its own names without a code change.
 *
 * The Office name and then the application name remain fallbacks, because the
 * nested relations are genuinely nullable in the frontend contract — and a
 * blank header would be worse than a generic one.
 * The mark and name link to Dashboard, giving every viewport a persistent home
 * route without adding another header control.
 */
export async function AppHeader({ user }: { user: CurrentUser }) {
  const [t, tNavigation] = await Promise.all([
    getTranslations("common"),
    getTranslations("navigation"),
  ]);
  const organizationName = user.office?.organization?.name ?? user.office?.name ?? t("appName");
  const officeName = user.office?.organization ? user.office.name : null;

  return (
    <header className="bg-card border-border flex h-16 shrink-0 items-center gap-2 border-b px-4 sm:px-6">
      <MobileNav user={user} />

      <Link
        href="/dashboard"
        aria-label={tNavigation("dashboard")}
        className="focus-visible:ring-ring flex min-w-0 flex-1 items-center gap-2.5 rounded-md focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
      >
        <span
          aria-hidden="true"
          className="bg-primary text-primary-foreground grid size-7 shrink-0 place-items-center rounded-md"
        >
          <Scale className="size-4" />
        </span>
        <span className="flex min-w-0 flex-col">
          <span className="truncate text-sm font-semibold tracking-tight">{organizationName}</span>
          {officeName ? (
            <span className="text-muted-foreground truncate text-xs">{officeName}</span>
          ) : null}
        </span>
      </Link>

      <div className="ml-auto flex shrink-0 items-center gap-2">
        <LocaleSwitcher />
        <UserMenu user={user} />
      </div>
    </header>
  );
}
