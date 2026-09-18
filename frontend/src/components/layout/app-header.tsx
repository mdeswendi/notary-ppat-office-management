import { PanelLeftOpen } from "lucide-react";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { HeaderNotifications } from "@/components/layout/header-notifications";
import { MobileNav } from "@/components/layout/mobile-nav";
import { UserMenu } from "@/components/layout/user-menu";
import { ThemeToggle } from "@/components/theme-toggle";
import { DashboardSearch } from "@/features/dashboard/dashboard-search";
import { can } from "@/lib/permissions/can";
import { Button } from "@/components/ui/button";
import { useTranslations } from "next-intl";
import type { CurrentUser } from "@/types/auth";

/**
 * Application header: navigation trigger, office-wide search, task
 * notifications, locale switch, and account menu.
 *
 * The organization identity belongs to the Dashboard hero and sidebar. Keeping
 * this row operational matches the approved reference and gives the search
 * enough width to remain useful on an office laptop.
 */
export function AppHeader({
  user,
  sidebarOpen,
  onExpandSidebar,
}: {
  user: CurrentUser;
  sidebarOpen: boolean;
  onExpandSidebar: () => void;
}) {
  const tNavigation = useTranslations("navigation");

  return (
    <header className="border-border/80 bg-card sticky top-0 z-30 flex min-h-16 shrink-0 items-center gap-2 border-b px-3 sm:px-6">
      <MobileNav user={user} />
      {!sidebarOpen ? (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          onClick={onExpandSidebar}
          aria-label={tNavigation("expandSidebar")}
          title={tNavigation("expandSidebar")}
          className="hidden lg:inline-flex"
        >
          <PanelLeftOpen aria-hidden="true" />
        </Button>
      ) : null}

      <div className="min-w-0 flex-1 sm:max-w-xl">
        <DashboardSearch />
      </div>

      <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
        <HeaderNotifications enabled={can(user, "tasks.view")} />
        <ThemeToggle />
        <div className="hidden sm:block">
          <LocaleSwitcher />
        </div>
        <UserMenu user={user} />
      </div>
    </header>
  );
}
