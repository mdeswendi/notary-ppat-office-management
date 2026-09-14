"use client";

import { useTranslations } from "next-intl";

import { visibleNavigation, type NavigationItem } from "@/config/navigation";
import { Link, usePathname } from "@/i18n/navigation";
import { cn } from "@/lib/utils";
import type { CurrentUser } from "@/types/auth";

type SidebarNavProps = {
  user: CurrentUser;
  /** Lets the mobile drawer close itself once a destination is chosen. */
  onNavigate?: () => void;
};

/**
 * Selects one active destination when route prefixes overlap.
 *
 * For example, `/parties` (Directory) is a prefix of
 * `/parties/individuals` (Individuals). A simple prefix check would mark both
 * links active, so the longest matching destination wins while still keeping
 * detail routes such as `/parties/individuals/01...` attached to Individuals.
 */
export function resolveActiveNavigationHref(
  pathname: string,
  items: ReadonlyArray<NavigationItem>,
): string | undefined {
  let activeHref: string | undefined;

  const visit = (entries: ReadonlyArray<NavigationItem>) => {
    for (const item of entries) {
      if (item.href && (pathname === item.href || pathname.startsWith(`${item.href}/`))) {
        if (!activeHref || item.href.length > activeHref.length) {
          activeHref = item.href;
        }
      }

      if (item.children) {
        visit(item.children);
      }
    }
  };

  visit(items);

  return activeHref;
}

/**
 * The navigation list itself, shared by the desktop sidebar and the mobile
 * drawer so there is exactly one menu definition and one filter.
 *
 * Filtering lives in `visibleNavigation`, which decides both whether a
 * destination exists and whether this account may use it — never by role name
 * (`docs/02_MENU_AND_PERMISSIONS.md` section 1). Parents appear only when a
 * child survives.
 *
 * Presentation only. Hiding a link removes no capability; every route and
 * endpoint is authorized again on the server.
 */
export function SidebarNav({ user, onNavigate }: SidebarNavProps) {
  const t = useTranslations("navigation");
  const pathname = usePathname();

  const items = visibleNavigation(user);
  const activeHref = resolveActiveNavigationHref(pathname, items);

  const renderItem = (item: NavigationItem) => {
    const Icon = item.icon;

    if (item.children) {
      return (
        <li key={item.key} className="flex flex-col gap-1">
          <div className="text-sidebar-foreground/50 flex items-center gap-3 px-3 pt-3 pb-1 text-[11px] font-medium tracking-[0.12em] uppercase">
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            {t(item.translationKey)}
          </div>
          <ul className="flex flex-col gap-1">{item.children.map(renderItem)}</ul>
        </li>
      );
    }

    // A leaf without a destination would render a link to nowhere; the config
    // type allows it only so parents can omit `href`.
    if (!item.href) {
      return null;
    }

    const active = item.href === activeHref;

    return (
      <li key={item.key}>
        <Link
          href={item.href}
          onClick={onNavigate}
          aria-current={active ? "page" : undefined}
          className={cn(
            "focus-visible:ring-sidebar-ring relative flex items-center gap-3 rounded-md border-l-2 px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none",
            active
              ? "border-brand-gold bg-sidebar-accent text-sidebar-accent-foreground font-medium shadow-sm shadow-black/10"
              : "text-sidebar-foreground/68 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground border-transparent",
          )}
        >
          <Icon aria-hidden="true" className="size-4 shrink-0" />
          {t(item.translationKey)}
        </Link>
      </li>
    );
  };

  return <ul className="flex flex-col gap-1">{items.map(renderItem)}</ul>;
}
