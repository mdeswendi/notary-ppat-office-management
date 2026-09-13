import { Landmark } from "lucide-react";
import { getTranslations } from "next-intl/server";

import { SidebarNav } from "@/components/layout/sidebar-nav";
import { Separator } from "@/components/ui/separator";
import { Link } from "@/i18n/navigation";
import type { CurrentUser } from "@/types/auth";

/**
 * Desktop sidebar.
 *
 * Width follows docs/04_UI_DESIGN_SYSTEM.md section 3 (240-260px). Hidden
 * below `lg`, where MobileNav takes over with the same navigation data.
 *
 * Collapse to a 72px icon rail is described in section 3 but is deliberately
 * not built at M0.9 — with Dashboard as the only destination it would be a
 * toggle plus tooltip machinery around a single row. See the open item.
 *
 * The rail is `sticky h-svh` and scrolls its own nav rather than letting the
 * window scroll it. That is what keeps the menu where the reader left it:
 * navigation resets the *window* scroll to the top, and while the sidebar rode
 * along with the document, reaching anything below the fold meant scrolling
 * down again after every single click. The `<aside>` lives in the `(app)`
 * layout, so it is never remounted between pages and its own scrollTop simply
 * survives. `overscroll-contain` stops a scroll that reaches the end of the
 * menu from continuing into the page behind it.
 */
export async function AppSidebar({ user }: { user: CurrentUser }) {
  const t = await getTranslations("navigation");
  const tCommon = await getTranslations("common");
  const practiceLabel = user.office?.practice_type
    ? tCommon(`practiceTypes.${user.office.practice_type}`)
    : tCommon("officeLabel");

  return (
    <aside className="bg-sidebar text-sidebar-foreground border-sidebar-border shadow-primary/10 sticky top-0 hidden h-svh w-64 shrink-0 flex-col border-r shadow-xl lg:flex">
      <Link
        href="/dashboard"
        aria-label={t("dashboard")}
        className="focus-visible:ring-sidebar-ring mx-4 mt-5 flex items-center gap-3 rounded-lg p-2 focus-visible:ring-2 focus-visible:outline-none"
      >
        <span
          aria-hidden="true"
          className="text-brand-gold border-brand-gold/35 grid size-10 shrink-0 place-items-center rounded-lg border bg-white/5 shadow-inner shadow-white/10"
        >
          <Landmark className="size-5" />
        </span>
        <span className="min-w-0">
          <span className="block truncate text-sm font-semibold tracking-wide">
            {practiceLabel}
          </span>
          <span className="text-sidebar-foreground/60 block truncate text-[11px]">
            {tCommon("appName")}
          </span>
        </span>
      </Link>

      <div className="from-brand-gold/60 via-sidebar-border mx-5 mt-4 h-px bg-gradient-to-r to-transparent" />

      <nav
        aria-label={t("mainLabel")}
        className="mt-2 flex-1 overflow-y-auto overscroll-contain p-3"
      >
        <SidebarNav user={user} />
      </nav>

      <div className="p-4">
        <Separator className="bg-sidebar-border" />
        <p className="text-sidebar-foreground/60 px-2 pt-4 text-xs leading-relaxed">
          {tCommon("sidebarMotto")}
        </p>
      </div>
    </aside>
  );
}
