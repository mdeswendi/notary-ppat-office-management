"use client";

import { useTranslations } from "next-intl";

import { Link, usePathname } from "@/i18n/navigation";
import { navigationItems } from "@/config/navigation";
import { Separator } from "@/components/ui/separator";
import { cn } from "@/lib/utils";

/**
 * Sidebar presentation foundation.
 *
 * Width follows docs/04_UI_DESIGN_SYSTEM.md section 3 (240-260px). Hidden
 * below `lg`; the mobile navigation drawer in section 35 belongs to M0.9,
 * where the shell becomes stateful.
 *
 * Presentational only — it renders whatever `navigationItems` contains and
 * performs no permission filtering. M0.8 owns authorization.
 */
export function AppSidebar() {
  const t = useTranslations("navigation");
  const tCommon = useTranslations("common");
  const pathname = usePathname();

  return (
    <aside className="bg-sidebar border-sidebar-border hidden w-64 shrink-0 flex-col border-r lg:flex">
      <nav aria-label={t("mainLabel")} className="flex-1 p-3">
        <ul className="flex flex-col gap-1">
          {navigationItems.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;

            return (
              <li key={item.key}>
                <Link
                  href={item.href}
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
      </nav>

      <div className="p-3">
        <Separator />
        <p className="text-muted-foreground px-3 pt-3 text-xs">{tCommon("officeLabel")}</p>
      </div>
    </aside>
  );
}
