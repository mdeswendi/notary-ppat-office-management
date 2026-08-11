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

  /**
   * Nested routes keep their parent entry active — `/settings/roles/7` should
   * still highlight Roles.
   */
  const isActive = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  const renderItem = (item: NavigationItem) => {
    const Icon = item.icon;

    if (item.children) {
      return (
        <li key={item.key} className="flex flex-col gap-1">
          <div className="text-muted-foreground flex items-center gap-3 px-3 pt-3 pb-1 text-xs font-medium tracking-wide uppercase">
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

    const active = isActive(item.href);

    return (
      <li key={item.key}>
        <Link
          href={item.href}
          onClick={onNavigate}
          aria-current={active ? "page" : undefined}
          className={cn(
            "focus-visible:ring-sidebar-ring flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none",
            active
              ? "bg-sidebar-accent text-sidebar-accent-foreground font-medium"
              : "text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
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
