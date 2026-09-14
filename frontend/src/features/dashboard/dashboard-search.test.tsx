import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardSearch } from "@/features/dashboard/dashboard-search";
import { renderWithProviders } from "@/test/render";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/features/auth/use-current-user", () => ({ useCurrentUser: vi.fn() }));
vi.mock("@/services/parties", () => ({ getPartyDirectory: vi.fn() }));
vi.mock("@/services/ppat", () => ({ getPpatDeeds: vi.fn() }));
vi.mock("@/services/properties", () => ({ getProperties: vi.fn() }));
vi.mock("@/services/matters", () => ({ getMatters: vi.fn() }));

const auth = await import("@/features/auth/use-current-user");
const parties = await import("@/services/parties");
const deeds = await import("@/services/ppat");
const properties = await import("@/services/properties");
const matters = await import("@/services/matters");

const user: CurrentUser = {
  id: "01USER00000000000000000000",
  name: "Pengguna Uji",
  email: "user@example.test",
  preferred_locale: "id",
  office: null,
  roles: [],
  permissions: ["parties.view", "ppat.deeds.view", "properties.view", "ppat.matters.view"],
  permission_scopes: {
    "parties.view": ["OFFICE"],
    "ppat.deeds.view": ["OFFICE"],
    "properties.view": ["OFFICE"],
    "ppat.matters.view": ["OFFICE"],
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(auth.useCurrentUser).mockReturnValue({
    data: user,
    isPending: false,
  } as unknown as ReturnType<typeof auth.useCurrentUser>);
  vi.mocked(parties.getPartyDirectory).mockResolvedValue({
    data: [
      {
        id: "01PARTY0000000000000000000",
        party_type: "INDIVIDUAL",
        display_name: "Budi Santoso",
        primary_phone: "08123456789",
        primary_email: null,
        office: null,
        individual: { full_name: "Budi Santoso" },
        company: null,
        created_at: null,
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 5, total: 1 },
  });
  vi.mocked(deeds.getPpatDeeds).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0 },
  });
  vi.mocked(properties.getProperties).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0 },
  });
  vi.mocked(matters.getMatters).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 5, total: 0 },
  });
});

describe("DashboardSearch", () => {
  it("searches permitted office surfaces and links to a real result", async () => {
    const events = userEvent.setup();
    renderWithProviders(<DashboardSearch />);

    await events.type(screen.getByRole("combobox"), "Budi");

    const result = await screen.findByRole("link", { name: /Budi Santoso/ });
    expect(result).toHaveAttribute("href", "/parties/individuals/01PARTY0000000000000000000");
    expect(parties.getPartyDirectory).toHaveBeenCalled();
    expect(deeds.getPpatDeeds).toHaveBeenCalled();
    expect(properties.getProperties).toHaveBeenCalled();
    expect(matters.getMatters).toHaveBeenCalled();
  });

  it("does not offer or request search when the account can read none of its sources", () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: { ...user, permissions: [], permission_scopes: {} },
      isPending: false,
    } as unknown as ReturnType<typeof auth.useCurrentUser>);

    const { container } = renderWithProviders(<DashboardSearch />);

    expect(container.textContent).toBe("");
    expect(parties.getPartyDirectory).not.toHaveBeenCalled();
  });
});
