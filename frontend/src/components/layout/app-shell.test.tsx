import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { AppShell } from "@/components/layout/app-shell";
import { renderWithProviders } from "@/test/render";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/components/layout/skip-link", () => ({ SkipLink: () => null }));
vi.mock("@/components/layout/session-expiry-guard", () => ({ SessionExpiryGuard: () => null }));
vi.mock("@/components/layout/app-sidebar", () => ({
  AppSidebar: ({ onCollapse }: { onCollapse: () => void }) => (
    <button type="button" onClick={onCollapse}>
      collapse-test
    </button>
  ),
}));
vi.mock("@/components/layout/app-header", () => ({
  AppHeader: ({
    sidebarOpen,
    onExpandSidebar,
  }: {
    sidebarOpen: boolean;
    onExpandSidebar: () => void;
  }) => (
    <div>
      <span>{sidebarOpen ? "sidebar-open" : "sidebar-closed"}</span>
      {!sidebarOpen ? (
        <button type="button" onClick={onExpandSidebar}>
          expand-test
        </button>
      ) : null}
    </div>
  ),
}));

const user: CurrentUser = {
  id: "01USER00000000000000000000",
  name: "Pengguna Uji",
  email: "user@example.test",
  preferred_locale: "id",
  office: null,
  roles: [],
  permissions: [],
  permission_scopes: {},
};

describe("AppShell", () => {
  it("collapses the sidebar and lets the user restore it", async () => {
    const events = userEvent.setup();
    renderWithProviders(<AppShell user={user}>dashboard-content</AppShell>);

    await events.click(screen.getByRole("button", { name: "collapse-test" }));
    expect(screen.getByText("sidebar-closed")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "collapse-test" })).not.toBeInTheDocument();

    await events.click(screen.getByRole("button", { name: "expand-test" }));
    expect(screen.getByText("sidebar-open")).toBeInTheDocument();
    expect(screen.getByText("dashboard-content")).toBeInTheDocument();
  });
});
