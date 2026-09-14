import { render, screen } from "@testing-library/react";
import { vi } from "vitest";

import { AppHeader } from "@/components/layout/app-header";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/components/layout/mobile-nav", () => ({ MobileNav: () => null }));
vi.mock("@/components/layout/user-menu", () => ({ UserMenu: () => null }));
vi.mock("@/components/locale-switcher", () => ({ LocaleSwitcher: () => null }));
vi.mock("@/features/dashboard/dashboard-search", () => ({
  DashboardSearch: () => <div>office-search</div>,
}));
vi.mock("@/components/layout/header-notifications", () => ({
  HeaderNotifications: ({ enabled }: { enabled: boolean }) => (
    <div>{enabled ? "notifications-enabled" : "notifications-disabled"}</div>
  ),
}));

const user: CurrentUser = {
  id: "01USER00000000000000000000",
  name: "Muhammad Deswendi",
  email: "administrator@example.test",
  preferred_locale: "id",
  office: {
    id: "01OFFICE0000000000000000000",
    code: "SBG-01",
    name: "Kantor Pusat - Subang",
    practice_type: "PPAT",
    jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
    organization: {
      id: "01ORG000000000000000000000",
      name: "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn",
    },
  },
  roles: [],
  permissions: [],
  permission_scopes: {},
};

describe("AppHeader", () => {
  it("puts global search in the top bar and enables task notifications by permission", () => {
    render(<AppHeader user={{ ...user, permissions: ["tasks.view"] }} />);

    expect(screen.getByText("office-search")).toBeInTheDocument();
    expect(screen.getByText("notifications-enabled")).toBeInTheDocument();
  });
});
