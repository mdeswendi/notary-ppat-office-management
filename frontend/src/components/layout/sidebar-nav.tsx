"use client";

import { useTranslations } from "next-intl";

import { navigationItems } from "@/config/navigation";
import { Link, usePathname } from "@/i18n/navigation";
import { can } from "@/lib/permissions/can";
import { cn } from "@/lib/utils";
import type { CurrentUser } from "@/types/auth";

type SidebarNavProps = {
  user: CurrentUser;
  /** Lets the mobile drawer close itself once a destination is chosen. */
  onNavigate?: () => void;
};

/**
 * The navigation list itself, shared by the desktop sidebar and the mobile
 * drawer so there is exactly one menu definition.
 *
 * Entries are filtered by `requiredPermission` against the effective
 * permissions the backend resolved — never by role name, per
 * docs/02_MENU_AND_PERMISSIONS.md section 1. An entry without a
 * `requiredPermission` is visible to any authenticated user.
 *
 * This is presentation only. Hiding a link removes no capability; every route
 * and endpoint is authorized again on the server.
 */
export function SidebarNav({ user, onNavigate }: SidebarNavProps) {
  const t = useTranslations("navigation");
  const pathname = usePathname();

  const visibleItems = navigationItems.filter(
    (item) => !item.requiredPermission || can(user, item.requiredPermission),
  );

  return (
    <ul className="flex flex-col gap-1">
      {visibleItems.map((item) => {
        const Icon = item.icon;
        const isActive = pathname === item.href;

        return (
          <li key={item.key}>
            <Link
              href={item.href}
              onClick={onNavigate}
              aria-current={isActive ? "page" : undefined}
              className={cn(
                "focus-visible:ring-sidebar-ring flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none",
                isActive
                  ? "bg-sidebar-accent text-sidebar-accent-foreground font-medium"
                  : "text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
              )}
            >
              <Icon aria-hidden="true" className="size-4 shrink-0" />
              {t(item.translationKey)}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
