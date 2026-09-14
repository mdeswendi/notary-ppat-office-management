import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { expect, vi } from "vitest";

import { AppSidebar } from "@/components/layout/app-sidebar";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/components/layout/sidebar-nav", () => ({ SidebarNav: () => null }));

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
      name: "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn.",
    },
  },
  roles: [],
  permissions: [],
  permission_scopes: {},
};

describe("AppSidebar", () => {
  it("places the current PPAT office identity and brand promise above navigation", () => {
    render(<AppSidebar user={user} onCollapse={vi.fn()} />);

    const brandLink = screen.getByRole("link", { name: "navigation.dashboard" });

    expect(within(brandLink).getByText("common.officeLabel")).toBeInTheDocument();
    expect(within(brandLink).getByText("Mila Widyahastuti, S.H., M.Kn.")).toBeInTheDocument();
    expect(within(brandLink).getByText("common.sidebarBrandTagline")).toBeInTheDocument();
  });

  it("lets the user collapse the desktop sidebar", async () => {
    const events = userEvent.setup();
    const onCollapse = vi.fn();
    render(<AppSidebar user={user} onCollapse={onCollapse} />);

    await events.click(screen.getByRole("button", { name: "navigation.collapseSidebar" }));

    expect(onCollapse).toHaveBeenCalledOnce();
  });
});
