import { render, screen, within } from "@testing-library/react";
import { vi } from "vitest";

import { AppHeader } from "@/components/layout/app-header";
import type { CurrentUser } from "@/types/auth";

vi.mock("next-intl/server", () => ({
  getTranslations: async (namespace: string) => (key: string) => `${namespace}.${key}`,
}));

vi.mock("@/components/layout/mobile-nav", () => ({ MobileNav: () => null }));
vi.mock("@/components/layout/user-menu", () => ({ UserMenu: () => null }));
vi.mock("@/components/locale-switcher", () => ({ LocaleSwitcher: () => null }));

const user: CurrentUser = {
  id: "01USER00000000000000000000",
  name: "Muhammad Deswendi",
  email: "administrator@example.test",
  preferred_locale: "id",
  office: {
    id: "01OFFICE0000000000000000000",
    code: "SBG-01",
    name: "Kantor Pusat - Subang",
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
  it("shows the Organization above the account Office", async () => {
    render(await AppHeader({ user }));

    const dashboardLink = screen.getByRole("link", { name: "navigation.dashboard" });

    expect(
      within(dashboardLink).getByText("Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn"),
    ).toBeInTheDocument();
    expect(within(dashboardLink).getByText("Kantor Pusat - Subang")).toBeInTheDocument();
  });

  it("falls back to one line when Organization context is unavailable", async () => {
    render(
      await AppHeader({
        user: { ...user, office: { ...user.office!, organization: null } },
      }),
    );

    expect(screen.getByText("Kantor Pusat - Subang")).toBeInTheDocument();
    expect(
      screen.queryByText("Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn"),
    ).not.toBeInTheDocument();
  });
});
