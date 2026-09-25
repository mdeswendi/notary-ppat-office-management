"use client";

import type { ReactNode } from "react";
import { useState } from "react";

import { AppHeader } from "@/components/layout/app-header";
import { AppSidebar } from "@/components/layout/app-sidebar";
import { AppUpdateNotice } from "@/components/layout/app-update-notice";
import { SessionExpiryGuard } from "@/components/layout/session-expiry-guard";
import { SkipLink } from "@/components/layout/skip-link";
import type { CurrentUser } from "@/types/auth";

type AppShellProps = {
  /**
   * The already-authenticated user, resolved by the authenticated layout.
   *
   * The shell receives it rather than fetching it: the session has been
   * verified against Laravel once, above, and re-checking here would mean two
   * slightly different notions of "signed in".
   */
  user: CurrentUser;
  children: ReactNode;
};

/**
 * Authenticated application frame — sidebar, header, content area.
 *
 * Layout only. It performs no session check and no authorization decision of
 * its own; both belong to the layout above it and to the backend respectively.
 */
export function AppShell({ user, children }: AppShellProps) {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const appVersion = process.env.NEXT_PUBLIC_APP_VERSION ?? "development";

  return (
    <div className="bg-background flex min-h-svh">
      <SessionExpiryGuard />
      <SkipLink />
      {sidebarOpen ? <AppSidebar user={user} onCollapse={() => setSidebarOpen(false)} /> : null}
      <div className="relative flex min-w-0 flex-1 flex-col overflow-clip">
        <AppHeader
          user={user}
          sidebarOpen={sidebarOpen}
          onExpandSidebar={() => setSidebarOpen(true)}
        />
        <main
          id="main-content"
          tabIndex={-1}
          className="relative min-w-0 flex-1 focus:outline-none"
        >
          {children}
        </main>
        <AppUpdateNotice currentVersion={appVersion} />
      </div>
    </div>
  );
}
