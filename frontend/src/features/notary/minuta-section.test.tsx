import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MinutaSection } from "@/features/notary/minuta-section";
import { renderWithProviders } from "@/test/render";
import type { NotaryMinuta } from "@/types/notary";

vi.mock("@/services/notary", () => ({
  notaryDeedKeys: {
    all: () => ["notary", "deeds"],
    minuta: (id: string) => ["notary", "deeds", "detail", id, "minuta"],
    list: (query: unknown) => ["notary", "deeds", "list", query],
    detail: (id: string) => ["notary", "deeds", "detail", id],
    options: () => ["notary", "deeds", "options"],
  },
  getMinuta: vi.fn(),
  fileMinuta: vi.fn(),
  updateMinuta: vi.fn(),
}));

// The picker's candidate list is the ordinary document list, mocked here so this
// file keeps testing the section rather than the network.
vi.mock("@/services/documents", () => ({
  documentQueryKeys: {
    all: () => ["documents"],
    list: (query: unknown) => ["documents", "list", query],
    detail: (id: string) => ["documents", "detail", id],
    options: () => ["documents", "options"],
  },
  getDocuments: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
  }),
}));

const services = await import("@/services/notary");

function axiosError(status: number): AxiosError {
  const headers = new AxiosHeaders();
  const config = { headers };

  return new AxiosError("Request failed", String(status), config, null, {
    status,
    statusText: "",
    data: {},
    headers,
    config,
  });
}

function minuta(overrides: Partial<NotaryMinuta> = {}): NotaryMinuta {
  return {
    id: "01MINUTA",
    notary_deed_id: "01DEED",
    document: {
      id: "01DOC",
      document_number: "DOC-2026-000001",
      title: "Pindaian Minuta",
      status: "VERIFIED",
      is_sensitive: false,
    },
    archive_location: "Lemari 3",
    volume_number: "VIII",
    bundle_number: "12",
    notes: null,
    release_status: null,
    archived_at: null,
    created_at: "2026-08-24T09:00:00+00:00",
    updated_at: null,
    can_update: false,
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getMinuta).mockReset();
});

function renderSection() {
  return renderWithProviders(<MinutaSection deedId="01DEED" />);
}

/**
 * Presentation tests. The backend is the security boundary (`CLAUDE.md` §51).
 */
describe("MinutaSection", () => {
  /**
   * **A 404 is the ordinary empty state, not an error.** The endpoint answers one
   * record or nothing, and "nothing filed yet" is what most deeds look like — showing
   * a failure there would tell somebody a normal situation is broken.
   */
  it("treats a 404 as nothing filed rather than a failure", async () => {
    vi.mocked(services.getMinuta).mockRejectedValue(axiosError(404));

    renderSection();

    expect(await screen.findByText("notary.minuta.noMinuta")).toBeInTheDocument();
    expect(screen.queryByText("notary.errors.notFound")).not.toBeInTheDocument();
  });

  it("shows a real failure as a failure", async () => {
    vi.mocked(services.getMinuta).mockRejectedValue(axiosError(500));

    renderSection();

    await waitFor(() => {
      expect(screen.getByText("notary.errors.server")).toBeInTheDocument();
    });

    expect(screen.queryByText("notary.minuta.noMinuta")).not.toBeInTheDocument();
  });

  it("renders the filing record and links its document", async () => {
    vi.mocked(services.getMinuta).mockResolvedValue(minuta());

    renderSection();

    expect(await screen.findByText("Lemari 3")).toBeInTheDocument();
    expect(screen.getByText("VIII")).toBeInTheDocument();

    const link = screen.getByRole("link", { name: "Pindaian Minuta" });

    expect(link).toHaveAttribute("href", "/documents/01DOC");
  });

  /**
   * The canonical columns nothing writes are rendered as unset rather than hidden, so
   * a reader can see the field exists and is empty rather than wondering whether it
   * was dropped (D-120).
   */
  it("shows release status and archived-at as unset, never as a control", async () => {
    vi.mocked(services.getMinuta).mockResolvedValue(minuta({ can_update: true }));

    renderSection();

    await screen.findByText("Lemari 3");

    expect(screen.getByText("notary.minuta.releaseStatus")).toBeInTheDocument();
    expect(screen.getAllByText("notary.minuta.unset").length).toBeGreaterThan(0);
    expect(screen.getByText("notary.minuta.lifecycleHint")).toBeInTheDocument();
  });

  /**
   * **No delete, archive or release control exists at all.** The catalogue defines no
   * `notary.minuta.delete`; `archive` and `release` exist and are unimplemented
   * because their trigger is an open domain question. Absent, not disabled.
   */
  it("offers no delete, archive or release control even to an actor who may update", async () => {
    vi.mocked(services.getMinuta).mockResolvedValue(minuta({ can_update: true }));

    renderSection();

    await screen.findByText("Lemari 3");

    for (const key of [
      "notary.minuta.delete",
      "notary.minuta.archive",
      "notary.minuta.release",
      "actions.delete",
    ]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  it("offers the update control only when the flag allows it", async () => {
    vi.mocked(services.getMinuta).mockResolvedValue(minuta({ can_update: false }));

    renderSection();

    await screen.findByText("Lemari 3");

    expect(screen.queryByRole("button", { name: "notary.minuta.update" })).not.toBeInTheDocument();
  });

  it("shows the update control when it does", async () => {
    vi.mocked(services.getMinuta).mockResolvedValue(minuta({ can_update: true }));

    renderSection();

    expect(await screen.findByRole("button", { name: "notary.minuta.update" })).toBeInTheDocument();
  });
});
