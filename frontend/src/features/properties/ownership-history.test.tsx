import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { OwnershipHistory } from "@/features/properties/ownership-history";
import { renderWithProviders } from "@/test/render";
import type { PropertyOwner } from "@/types/property";

vi.mock("@/services/properties", () => ({
  propertyKeys: {
    all: () => ["properties"],
    list: (query: unknown) => ["properties", "list", query],
    detail: (id: string) => ["properties", "detail", id],
    owners: (id: string) => ["properties", "detail", id, "owners"],
    options: () => ["properties", "options"],
    matterProperties: (id: string) => ["ppat", "matters", id, "properties"],
  },
  getPropertyOwners: vi.fn(),
  addPropertyOwner: vi.fn(),
  updatePropertyOwner: vi.fn(),
  getProperties: vi.fn(),
  getPropertyOptions: vi.fn(),
}));

vi.mock("@/services/parties", () => ({
  partyDirectoryKeys: {
    all: ["parties"],
    list: (query: unknown) => ["parties", "directory", query],
  },
  getPartyDirectory: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
  }),
}));

const services = await import("@/services/properties");

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

function link(overrides: Partial<PropertyOwner> = {}): PropertyOwner {
  return {
    id: "01LINK",
    property_id: "01PROPERTY",
    ownership_percentage: null,
    effective_from: "2026-01-01",
    effective_until: null,
    is_current: true,
    party: {
      id: "01PARTY",
      display_name: "Budi Santoso",
      party_type: "INDIVIDUAL",
      is_archived: false,
      can_view_party: true,
    },
    source_matter: null,
    created_at: null,
    updated_at: null,
    can_update: true,
    ...overrides,
  };
}

function chain(links: PropertyOwner[], total = 0, canUpdate = true) {
  return {
    data: links,
    meta: { total: links.length, can_update: canUpdate, current_ownership_total: total },
  };
}

beforeEach(() => {
  vi.mocked(services.getPropertyOwners).mockReset();
});

function renderChain(isArchived = false) {
  return renderWithProviders(<OwnershipHistory propertyId="01PROPERTY" isArchived={isArchived} />);
}

/**
 * These are **presentation tests**. The backend is the security boundary
 * (`CLAUDE.md` §51): a passing assertion here never means an endpoint is authorized.
 * What they pin is that a control the actor may not use is *absent*, and that the
 * co-ownership rulings the M7 lock made survive contact with the interface.
 *
 * Assertions name **translation keys, not sentences**, because the setup mock makes
 * `t()` return its key.
 */
describe("OwnershipHistory", () => {
  /**
   * **The M7 lock section 7.2, at the interface.** A Property legitimately has several
   * current owners at once — the brief's *"hanya satu owner yang bisa is_current =
   * true"* would have made co-ownership unrepresentable, and this is what it looks like
   * when it is not.
   */
  it("shows every current holder, not one", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain(
        [
          link({ id: "01A", ownership_percentage: 50 }),
          link({
            id: "01B",
            ownership_percentage: 50,
            party: {
              id: "01PARTY2",
              display_name: "Siti Rahayu",
              party_type: "INDIVIDUAL",
              is_archived: false,
              can_view_party: true,
            },
          }),
        ],
        100,
      ),
    );

    renderChain();

    expect(await screen.findByText("Budi Santoso")).toBeInTheDocument();
    expect(screen.getByText("Siti Rahayu")).toBeInTheDocument();
  });

  /**
   * A total over 100 renders without complaint. Whether shares must sum to 100 is a
   * rule about Indonesian co-ownership that no canonical document states, so the
   * interface displays the arithmetic and attaches no judgement (`CLAUDE.md` §62).
   */
  it("displays a total over 100 without flagging it", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain([link({ ownership_percentage: 80 })], 160),
    );

    renderChain();

    expect(await screen.findByText("160%")).toBeInTheDocument();
    // No warning, no error styling, no validation message.
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  /**
   * **A closed link stays in the chain.** History is added and never overwritten
   * (`CLAUDE.md` §63), so a past owner is listed alongside the current ones rather than
   * disappearing.
   */
  it("keeps closed links visible", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain([link({ is_current: false, effective_until: "2026-06-30" })]),
    );

    renderChain();

    expect(await screen.findByText("Budi Santoso")).toBeInTheDocument();
    expect(screen.getByText("properties.ownership.closed")).toBeInTheDocument();
  });

  /**
   * **There is no remove control, and there is no route behind one.**
   * `property_owners` has no `deleted_at` in the ERD, so a delete could only be hard,
   * and hard-deleting a link destroys the history the table exists to keep. The control
   * offered says "close", which is what it does.
   */
  it("offers close and never delete, even to a fully capable actor", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(chain([link()]));

    renderChain();

    expect(
      await screen.findByRole("button", { name: "properties.ownership.close" }),
    ).toBeInTheDocument();

    for (const key of ["properties.ownership.removeOwner", "properties.delete", "actions.delete"]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  /**
   * An archived Property's chain is read-only: retirement makes the record read-only,
   * and the Policy refuses `updateOwnership` on one. The control is absent rather than
   * present and broken.
   */
  it("offers no write control on an archived property", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(chain([link()]));

    renderChain(true);

    await screen.findByText("Budi Santoso");

    expect(
      screen.queryByRole("button", { name: "properties.ownership.close" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "properties.ownership.addOwner" }),
    ).not.toBeInTheDocument();
  });

  it("offers no write control when the server says the caller may not write", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain([link({ can_update: false })], 0, false),
    );

    renderChain();

    await screen.findByText("Budi Santoso");

    expect(
      screen.queryByRole("button", { name: "properties.ownership.close" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "properties.ownership.addOwner" }),
    ).not.toBeInTheDocument();
  });

  /**
   * A Party the reader cannot open renders as plain text rather than a link that
   * answers 403 — the M4.5 rule. The row itself always renders: the link is a fact
   * about the land, and hiding it would misreport the title.
   */
  it("renders an unreachable party as text rather than a broken link", async () => {
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain([
        link({
          party: {
            id: "01PARTY",
            display_name: "Budi Santoso",
            party_type: "INDIVIDUAL",
            is_archived: false,
            can_view_party: false,
          },
        }),
      ]),
    );

    renderChain();

    expect(await screen.findByText("Budi Santoso")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Budi Santoso" })).not.toBeInTheDocument();
  });

  it("maps a forbidden chain onto a translated message, never server text", async () => {
    // A caller holding `properties.view` and not `properties.ownership.view` gets a
    // 403 here, which is an ordinary outcome rather than a fault.
    vi.mocked(services.getPropertyOwners).mockRejectedValue(
      axiosError(403, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderChain();

    await waitFor(() => {
      expect(screen.getByText("properties.errors.forbidden")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("shows a share the office did not record as unrecorded, not as zero", async () => {
    // A sole owner needs no percentage, and an office recording inherited title may
    // have a name and no figure. Rendering it as 0% would assert a share nobody wrote.
    vi.mocked(services.getPropertyOwners).mockResolvedValue(
      chain([link({ ownership_percentage: null })]),
    );

    renderChain();

    expect(await screen.findByText("properties.ownership.shareUnrecorded")).toBeInTheDocument();
    expect(screen.queryByText("0%")).not.toBeInTheDocument();
  });
});
