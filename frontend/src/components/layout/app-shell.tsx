import type { ReactNode } from "react";

import { AppHeader } from "@/components/layout/app-header";
import { AppSidebar } from "@/components/layout/app-sidebar";
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
  return (
    <div className="flex min-h-svh">
      <AppSidebar user={user} />
      <div className="flex min-w-0 flex-1 flex-col">
        <AppHeader user={user} />
        <main className="min-w-0 flex-1">{children}</main>
      </div>
    </div>
  );
}
