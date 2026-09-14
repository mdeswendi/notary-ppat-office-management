"use client";

import { PanelLeftClose } from "lucide-react";
import { useTranslations } from "next-intl";

import { OfficeBrandMark } from "@/components/layout/office-brand-mark";
import { SidebarNav } from "@/components/layout/sidebar-nav";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Link } from "@/i18n/navigation";
import { resolveOfficeIdentity } from "@/lib/office-identity";
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
export function AppSidebar({ user, onCollapse }: { user: CurrentUser; onCollapse: () => void }) {
  const t = useTranslations("navigation");
  const tCommon = useTranslations("common");
  const practiceLabel = user.office?.practice_type
    ? tCommon(`practiceTypes.${user.office.practice_type}`)
    : tCommon("officeLabel");
  const officeIdentity = resolveOfficeIdentity(user.office) ?? practiceLabel;
  const isPpatPractice = user.office?.practice_type === "PPAT";
  const brandHeading = isPpatPractice ? tCommon("officeLabel") : practiceLabel;
  const brandName = isPpatPractice
    ? officeIdentity.replace(/^Kantor\s+PPAT\s*/i, "")
    : officeIdentity;

  return (
    <aside className="bg-sidebar text-sidebar-foreground border-sidebar-border shadow-primary/10 sticky top-0 hidden h-svh w-64 shrink-0 flex-col border-r shadow-xl lg:flex">
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        onClick={onCollapse}
        aria-label={t("collapseSidebar")}
        title={t("collapseSidebar")}
        className="text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground absolute top-3 right-3 z-10"
      >
        <PanelLeftClose aria-hidden="true" />
      </Button>
      <Link
        href="/dashboard"
        aria-label={t("dashboard")}
        className="focus-visible:ring-sidebar-ring mx-5 mt-6 flex flex-col items-start rounded-lg px-1 py-2 pr-8 focus-visible:ring-2 focus-visible:outline-none"
      >
        <OfficeBrandMark aria-hidden="true" className="text-brand-gold mb-3 size-16" />
        <span className="block max-w-full font-serif leading-tight font-semibold text-white">
          <span className="block text-lg">{brandHeading}</span>
          <span className="block text-sm">{brandName}</span>
        </span>
        <span className="text-brand-gold mt-2 block text-[9px] leading-relaxed font-semibold tracking-[0.1em] uppercase">
          {tCommon("sidebarBrandTagline")}
        </span>
      </Link>

      <div className="from-brand-gold/60 via-sidebar-border mx-5 mt-5 h-px bg-gradient-to-r to-transparent" />

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
