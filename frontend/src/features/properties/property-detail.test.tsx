import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PropertyDetail } from "@/features/properties/property-detail";
import { renderWithProviders } from "@/test/render";
import type { Property } from "@/types/property";

vi.mock("@/services/properties", () => ({
  propertyKeys: {
    all: () => ["properties"],
    list: (query: unknown) => ["properties", "list", query],
    detail: (id: string) => ["properties", "detail", id],
    owners: (id: string) => ["properties", "detail", id, "owners"],
    options: () => ["properties", "options"],
    matterProperties: (id: string) => ["ppat", "matters", id, "properties"],
  },
  getProperty: vi.fn(),
  getProperties: vi.fn(),
  getPropertyOptions: vi.fn(),
  archiveProperty: vi.fn(),
  // The ownership section asks its own endpoint. A rejection is the ordinary
  // "you hold properties.view and not the ownership pair" answer.
  getPropertyOwners: vi.fn().mockRejectedValue(new Error("no ownership")),
  addPropertyOwner: vi.fn(),
  updatePropertyOwner: vi.fn(),
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

function property(overrides: Partial<Property> = {}): Property {
  return {
    id: "01PROPERTY",
    property_number: "PROP-000001",
    property_type: "LAND",
    right_type: "HAK_MILIK",
    certificate_number: "UJI-001",
    certificate_date: null,
    land_area: 250,
    building_area: null,
    measurement_letter_number: null,
    measurement_letter_date: null,
    address: "Jalan Uji No. 1",
    village: null,
    district: null,
    city: null,
    province: null,
    postal_code: null,
    latitude: null,
    longitude: null,
    is_archived: false,
    archived_at: null,
    office: null,
    current_owners: null,
    current_ownership_total: null,
    matter_count: 0,
    created_at: "2026-08-25T09:00:00+00:00",
    created_by: null,
    updated_at: null,
    updated_by: null,
    can_update: false,
    can_archive: false,
    can_view_ownership: false,
    can_update_ownership: false,
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getProperty).mockReset();
});

function renderDetail() {
  return renderWithProviders(<PropertyDetail propertyId="01PROPERTY" />);
}

/**
 * Presentation tests. The backend is the security boundary (`CLAUDE.md` §51).
 */
describe("PropertyDetail", () => {
  it("offers no act the flags refuse", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property());

    renderDetail();

    await screen.findByRole("heading", { name: "UJI-001" });

    expect(screen.queryByRole("link", { name: "actions.edit" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "properties.archive" })).not.toBeInTheDocument();
  });

  /**
   * **No delete control exists at all**, and no un-archive either.
   * `properties.delete` and `properties.restore` are both absent from the 177-code
   * catalogue — `properties.archive` is the retirement path it does define, and it
   * soft-deletes (O-045). The buttons are absent rather than disabled.
   */
  it("offers no delete or restore control even to a fully capable actor", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(
      property({
        can_update: true,
        can_archive: true,
        can_view_ownership: true,
        can_update_ownership: true,
      }),
    );

    renderDetail();

    await screen.findByRole("heading", { name: "UJI-001" });

    for (const key of [
      "properties.delete",
      "properties.restore",
      "properties.unarchive",
      "actions.delete",
    ]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  /**
   * An archived parcel opens read-only. Retiring a record from the active list is not
   * making it unfindable — an office looking up an old certificate needs it — and the
   * flags turn every write control off.
   */
  it("opens an archived property read-only", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(
      property({ is_archived: true, archived_at: "2026-08-25T10:00:00+00:00" }),
    );

    renderDetail();

    expect(await screen.findByText("properties.archived")).toBeInTheDocument();
    expect(screen.getByText("properties.archivedNotice")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "properties.archive" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "actions.edit" })).not.toBeInTheDocument();
  });

  /**
   * **No Documents section and no Timeline section.** `property_documents` does not
   * exist (O-046), so that heading could never show anything. Timeline is unbuilt for a
   * reason that has since lapsed — M7.3 had no audit store, and M8.1 built one — so this
   * pins current behaviour, not a permanent ruling.
   */
  it("shows no documents or activity-timeline section", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property());

    renderDetail();

    await screen.findByRole("heading", { name: "UJI-001" });

    expect(screen.queryByText("properties.sections.documents")).not.toBeInTheDocument();
    expect(screen.queryByText("properties.sections.timeline")).not.toBeInTheDocument();
    expect(screen.queryByText("properties.sections.activity")).not.toBeInTheDocument();
  });

  /**
   * Reading a parcel is not reading its chain of title: the catalogue splits the two
   * capabilities, and the section is absent for a caller who holds only the first.
   */
  it("hides the ownership section from a caller who may not read the chain", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property({ can_view_ownership: false }));

    renderDetail();

    await screen.findByRole("heading", { name: "UJI-001" });

    expect(screen.queryByText("properties.ownership.title")).not.toBeInTheDocument();
  });

  it("shows the ownership section to a caller who may read the chain", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property({ can_view_ownership: true }));

    renderDetail();

    expect(await screen.findByText("properties.ownership.title")).toBeInTheDocument();
  });

  /**
   * `CLAUDE.md` §49: state must not rely on colour alone. The type badge carries its
   * translated word and the archived marker carries one beside its icon.
   */
  it("shows type and retirement as text, not colour alone", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(
      property({ property_type: "LAND_AND_BUILDING", is_archived: true }),
    );

    renderDetail();

    expect(
      await screen.findByText("properties.propertyTypes.LAND_AND_BUILDING"),
    ).toBeInTheDocument();
    expect(screen.getByText("properties.archived")).toBeInTheDocument();
  });

  /**
   * **`right_type` is rendered verbatim, never expanded.** The ERD calls its codes
   * examples, so no translation table exists, and expanding `HAK_MILIK` into an English
   * name would be the invented legal translation `CLAUDE.md` §9 forbids — and would be
   * simply wrong for a code the office typed that the ERD never listed.
   */
  it("renders a right type verbatim, including one the erd never listed", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property({ right_type: "HAK_ULAYAT" }));

    renderDetail();

    expect(await screen.findByText("HAK_ULAYAT")).toBeInTheDocument();
    expect(screen.queryByText(/Freehold/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Hak Milik Atas/i)).not.toBeInTheDocument();
  });

  it("shows an unnumbered parcel as unnumbered rather than blank", async () => {
    vi.mocked(services.getProperty).mockResolvedValue(property({ property_number: null }));

    renderDetail();

    expect(await screen.findByText("properties.unnumbered")).toBeInTheDocument();
  });

  it("maps a failed load onto a translated message, never server text", async () => {
    vi.mocked(services.getProperty).mockRejectedValue(
      axiosError(404, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderDetail();

    await waitFor(() => {
      expect(screen.getByText("properties.errors.notFound")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });
});
