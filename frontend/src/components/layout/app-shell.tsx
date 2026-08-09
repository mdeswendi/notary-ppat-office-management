import type { ReactNode } from "react";

import { AppHeader } from "@/components/layout/app-header";
import { AppSidebar } from "@/components/layout/app-sidebar";

type AppShellProps = {
  children: ReactNode;
};

/**
 * Application layout frame — sidebar, header, scrollable content area.
 *
 * Presentational only. This component does not read the current user, call
 * `/api/v1/me`, inspect roles or permissions, guard routes, or make any
 * business API call. M0.9 owns wiring it into the authenticated application;
 * until then it is a layout primitive that renders whatever it is given.
 */
export function AppShell({ children }: AppShellProps) {
  return (
    <div className="flex min-h-svh">
      <AppSidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <AppHeader />
        <main className="min-w-0 flex-1">{children}</main>
      </div>
    </div>
  );
}
