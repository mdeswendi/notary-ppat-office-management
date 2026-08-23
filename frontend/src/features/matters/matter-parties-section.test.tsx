import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, vi } from "vitest";

import { MatterPartiesSection } from "@/features/matters/matter-parties-section";
import { renderWithProviders } from "@/test/render";
import type { MatterParty, MatterPartyListPage } from "@/types/matter-party";

vi.mock("@/services/matter-parties", () => ({
  matterPartyQueryKeys: {
    all: (domain: string, matterId: string) => ["matters", domain, "detail", matterId, "parties"],
    candidates: (domain: string, matterId: string, search: string) => [
      "matters",
      domain,
      "detail",
      matterId,
      "party-candidates",
      search,
    ],
  },
  getMatterParties: vi.fn(),
  getMatterPartyCandidates: vi.fn(),
  addMatterParty: vi.fn(),
  updateMatterParty: vi.fn(),
  removeMatterParty: vi.fn(),
}));

const services = await import("@/services/matter-parties");

function participation(overrides: Partial<MatterParty> = {}): MatterParty {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    role_code: "PENGHADAP",
    notes: null,
    created_at: null,
    updated_at: null,
    can_manage: true,
    party: {
      id: "01BX5ZZKBKACTAV9WEVGEMMVRY",
      display_name: "Budi Santoso",
      party_type: "INDIVIDUAL",
      is_archived: false,
      can_view_party: true,
    },
    ...overrides,
  };
}

function page(data: MatterParty[], canManage = true): MatterPartyListPage {
  return { data, meta: { total: data.length, can_manage: canManage } };
}

/**
 * A genuine `AxiosError`, because the error mapper narrows with `instanceof`.
 */
function axiosError(status: number, data: unknown = {}): AxiosError {
  const headers = new AxiosHeaders();
  const config = { headers };

  return new AxiosError("Request failed", String(status), config, null, {
    status,
    statusText: "",
    data,
    headers,
    config,
  });
}

beforeEach(() => {
  vi.mocked(services.getMatterParties).mockReset();
  vi.mocked(services.getMatterPartyCandidates).mockReset();
  vi.mocked(services.removeMatterParty).mockReset();
});

function renderSection() {
  return renderWithProviders(<MatterPartiesSection domain="NOTARY" matterId="01MATTER" />);
}

/**
 * The M4.5 participation section (O-032).
 *
 * The branches worth pinning are the ones D-105 turns on and that typecheck
 * cannot see: whether a Party the reader cannot open still appears, whether it
 * appears as a link, and whether the manage controls are gated on the flag the
 * backend computed rather than on anything the client decided.
 */
describe("MatterPartiesSection", () => {
  it("lists a participation and links a Party the reader may open", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(page([participation()]));

    renderSection();

    const link = await screen.findByRole("link", { name: "Budi Santoso" });

    // Individuals and Companies live at different addresses; sending a reader to
    // the wrong one is a real defect a type cannot catch.
    expect(link).toHaveAttribute("href", "/parties/individuals/01BX5ZZKBKACTAV9WEVGEMMVRY");
  });

  it("routes a Company participation to the Company surface", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(
      page([
        participation({
          party: {
            id: "01COMPANY",
            display_name: "PT Contoh",
            party_type: "COMPANY",
            is_archived: false,
            can_view_party: true,
          },
        }),
      ]),
    );

    renderSection();

    expect(await screen.findByRole("link", { name: "PT Contoh" })).toHaveAttribute(
      "href",
      "/parties/companies/01COMPANY",
    );
  });

  it("shows a Party the reader cannot open as plain text, not a link", async () => {
    // D-105: the row still renders, because hiding it would misreport the
    // Matter's composition to somebody authorized to read it. But an anchor that
    // answers 403 is worse than plain text, so `can_view_party` decides.
    vi.mocked(services.getMatterParties).mockResolvedValue(
      page([
        participation({
          party: {
            id: "01HIDDEN",
            display_name: "Siti Rahayu",
            party_type: "INDIVIDUAL",
            is_archived: false,
            can_view_party: false,
          },
        }),
      ]),
    );

    renderSection();

    expect(await screen.findByText("Siti Rahayu")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Siti Rahayu" })).not.toBeInTheDocument();
  });

  it("marks an archived Party without removing it from the list", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(
      page([
        participation({
          party: {
            id: "01ARCHIVED",
            display_name: "Arsip Lama",
            party_type: "INDIVIDUAL",
            is_archived: true,
            can_view_party: false,
          },
        }),
      ]),
    );

    renderSection();

    expect(await screen.findByText("Arsip Lama")).toBeInTheDocument();
    expect(screen.getByText("matterParties.archivedParty")).toBeInTheDocument();
  });

  it("falls back to a no-role label rather than rendering a blank", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(
      page([participation({ role_code: null })]),
    );

    renderSection();

    expect(await screen.findByText("matterParties.noRole")).toBeInTheDocument();
  });

  it("offers the add control only when the backend says the actor may manage", async () => {
    // `view` and `manage` are independent codes in both directions (D-110), so a
    // reader who may not manage must not be shown a control that would 403.
    vi.mocked(services.getMatterParties).mockResolvedValue(page([participation()], false));

    renderSection();

    await screen.findByText("Budi Santoso");

    expect(
      screen.queryByRole("button", { name: "matterParties.addAction" }),
    ).not.toBeInTheDocument();
  });

  it("offers the add control when the actor may manage", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(page([participation()], true));

    renderSection();

    expect(
      await screen.findByRole("button", { name: "matterParties.addAction" }),
    ).toBeInTheDocument();
  });

  it("hides the per-row controls for a row the actor may not manage", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(
      page([participation({ can_manage: false })], false),
    );

    renderSection();

    await screen.findByText("Budi Santoso");

    expect(
      screen.queryByRole("button", { name: "matterParties.editAction" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "matterParties.removeAction" }),
    ).not.toBeInTheDocument();
  });

  it("says the list is empty rather than rendering nothing", async () => {
    vi.mocked(services.getMatterParties).mockResolvedValue(page([]));

    renderSection();

    expect(await screen.findByText("matterParties.empty")).toBeInTheDocument();
  });

  it("maps a 403 onto the forbidden message rather than showing a raw error", async () => {
    // CLAUDE.md section 48: never display a raw server exception. The error key
    // is chosen from the status alone, so no response body can leak through.
    //
    // A **real** `AxiosError`, not a shaped plain object: `toMatterErrorKey`
    // narrows with `instanceof`, so an object merely carrying `isAxiosError`
    // falls through to the generic server message. Building the error properly
    // is what makes this test exercise the branch it names.
    vi.mocked(services.getMatterParties).mockRejectedValue(
      axiosError(403, { message: "leaked internal detail" }),
    );

    renderSection();

    expect(await screen.findByText("matterParties.errors.forbidden")).toBeInTheDocument();
    expect(screen.queryByText(/leaked internal detail/)).not.toBeInTheDocument();
  });

  it("asks for confirmation before removing and only then calls the service", async () => {
    // Removal is a hard delete with no history to restore from (D-105), so the
    // confirmation is the safeguard rather than a courtesy.
    const user = userEvent.setup();

    vi.mocked(services.getMatterParties).mockResolvedValue(page([participation()]));
    vi.mocked(services.removeMatterParty).mockResolvedValue(undefined);

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterParties.removeAction" }));

    expect(services.removeMatterParty).not.toHaveBeenCalled();

    await user.click(await screen.findByRole("button", { name: "matterParties.removeConfirm" }));

    await waitFor(() => {
      expect(services.removeMatterParty).toHaveBeenCalledWith(
        "NOTARY",
        "01MATTER",
        "01ARZ3NDEKTSV4RRFFQ69G5FAV",
      );
    });
  });

  it("reads the domain it was given rather than inferring one", async () => {
    // D-101 on the client side: the domain comes from the address the screen
    // lives at, so a PPAT page must never fetch a Notary list.
    vi.mocked(services.getMatterParties).mockResolvedValue(page([]));

    renderWithProviders(<MatterPartiesSection domain="PPAT" matterId="01MATTER" />);

    await waitFor(() => {
      expect(services.getMatterParties).toHaveBeenCalledWith("PPAT", "01MATTER");
    });
  });
});
