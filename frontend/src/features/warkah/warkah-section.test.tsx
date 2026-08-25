import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { WarkahSection } from "@/features/warkah/warkah-section";
import { renderWithProviders } from "@/test/render";
import type { Warkah, WarkahItem, WarkahItemList } from "@/types/warkah";

vi.mock("@/services/warkah", () => ({
  warkahKeys: {
    all: () => ["ppat", "warkah"],
    list: (query: unknown) => ["ppat", "warkah", "list", query],
    options: () => ["ppat", "warkah", "options"],
    forDeed: (id: string) => ["ppat", "deeds", id, "warkah"],
    items: (id: string) => ["ppat", "deeds", id, "warkah", "items"],
  },
  getWarkah: vi.fn(),
  getWarkahItems: vi.fn(),
  getWarkahList: vi.fn(),
  getWarkahOptions: vi.fn(),
  setWarkahStatus: vi.fn(),
  verifyWarkah: vi.fn(),
  addWarkahItem: vi.fn(),
  updateWarkahItem: vi.fn(),
  removeWarkahItem: vi.fn(),
  attachWarkahDocument: vi.fn(),
  detachWarkahDocument: vi.fn(),
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

const services = await import("@/services/warkah");

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

function warkah(overrides: Partial<Warkah> = {}): Warkah {
  return {
    id: "01WARKAH",
    ppat_deed_id: "01DEED",
    status: "INCOMPLETE",
    completeness_percentage: 0,
    items_count: 0,
    archive_location: null,
    notes: null,
    verified_at: null,
    verified_by: null,
    finalized_at: null,
    finalized_by: null,
    deed: null,
    created_at: null,
    updated_at: null,
    can_manage: false,
    can_verify: false,
    can_upload: false,
    ...overrides,
  };
}

function item(overrides: Partial<WarkahItem> = {}): WarkahItem {
  return {
    id: "01ITEM",
    warkah_id: "01WARKAH",
    requirement_code: null,
    title_id: "Fotokopi KTP Penjual",
    title_en: "Copy of seller identity card",
    status: null,
    has_document: false,
    sequence_no: 1,
    notes: null,
    party: null,
    documents: [],
    created_at: null,
    updated_at: null,
    can_manage: false,
    can_upload: false,
    ...overrides,
  };
}

function itemList(items: WarkahItem[], meta: Partial<WarkahItemList["meta"]> = {}): WarkahItemList {
  return {
    data: items,
    meta: {
      total: items.length,
      can_manage: false,
      can_upload: false,
      collected: items.filter((line) => line.has_document).length,
      completeness_percentage: 0,
      warkah_started: true,
      ...meta,
    },
  };
}

beforeEach(() => {
  vi.mocked(services.getWarkahItems).mockReset();
  vi.mocked(services.getWarkah).mockReset();
});

function renderSection() {
  return renderWithProviders(<WarkahSection deedId="01DEED" />);
}

/**
 * These are **presentation tests**. The backend is the security boundary
 * (`CLAUDE.md` §51): a passing assertion here never means an endpoint is authorized.
 * What they pin is that a control the actor may not use is *absent*, and that the
 * rulings the M7 lock made about Warkah survive contact with the interface.
 *
 * Assertions name **translation keys, not sentences**, because the setup mock makes
 * `t()` return its key.
 */
describe("WarkahSection", () => {
  /**
   * **The M7 lock section 8.2, at the interface.** *"100% does not mean complete in
   * law. It means every item this office listed has a document. The interface must say
   * so rather than implying legal sufficiency."*
   */
  it("says what the percentage counts, rather than implying legal sufficiency", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah({ completeness_percentage: 100 }));
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([item({ has_document: true })], { completeness_percentage: 100, collected: 1 }),
    );

    renderSection();

    await screen.findByText("warkah.title");

    expect(screen.getByText("warkah.completenessHint")).toBeInTheDocument();
    expect(screen.getByText("100%")).toBeInTheDocument();
    // The fraction the percentage came from, so a reader sees what is being counted.
    expect(screen.getByText("warkah.collectedOf")).toBeInTheDocument();
  });

  /**
   * **No Finalize and no Archive control, ever.** Both codes are canonical and both
   * stay unimplemented, because their trigger is open question eight — *"what are the
   * binding/archiving requirements for deeds and supporting Warkah?"* (D-064, O-041).
   */
  it("offers no finalize or archive control even to a fully capable actor", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(
      warkah({ can_manage: true, can_verify: true, can_upload: true }),
    );
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([item({ can_manage: true, can_upload: true })], {
        can_manage: true,
        can_upload: true,
      }),
    );

    renderSection();

    await screen.findByText("warkah.title");

    for (const key of [
      "warkah.actions.finalize",
      "warkah.actions.archive",
      "warkah.statuses.FINALIZED",
      "warkah.statuses.ARCHIVED",
    ]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  /**
   * **A line's state is `has_document`, not a status.** `ppat_warkah_items.status` has
   * no vocabulary in the ERD; the M7.4 brief proposed six values, and an item-status
   * vocabulary *is* the verification rule (O-041). So there is no status control and no
   * status badge — only the fact that is observable.
   */
  it("shows a line as collected or not, and offers no item status control", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah({ can_manage: true }));
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([item({ has_document: false, can_manage: true })], { can_manage: true }),
    );

    renderSection();

    expect(await screen.findByText("warkah.notCollected")).toBeInTheDocument();

    for (const key of [
      "warkah.itemStatuses.MISSING",
      "warkah.itemStatuses.VERIFIED",
      "warkah.itemStatuses.NOT_APPLICABLE",
    ]) {
      expect(screen.queryByText(key)).not.toBeInTheDocument();
    }
  });

  it("shows a collected line as collected", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah());
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([item({ has_document: true })]));

    renderSection();

    expect(await screen.findByText("warkah.collected")).toBeInTheDocument();
  });

  /**
   * Both titles render, because `title_id` and `title_en` are bilingual **database**
   * fields rather than UI strings (`CLAUDE.md` §10) — the office wrote both, and a
   * reader in either language should see what was written.
   */
  it("renders both stored titles, not a translated one", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah());
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([item()]));

    renderSection();

    expect(await screen.findByText("Fotokopi KTP Penjual")).toBeInTheDocument();
    expect(screen.getByText("Copy of seller identity card")).toBeInTheDocument();
  });

  /**
   * A bundle nobody has started is an **empty state, not a failure**: the API answers
   * 200 with `warkah_started: false`, and the section renders its checklist empty.
   */
  it("treats a bundle nobody started as an empty state", async () => {
    vi.mocked(services.getWarkah).mockRejectedValue(axiosError(404));
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([], { warkah_started: false }));

    renderSection();

    expect(await screen.findByText("warkah.notStartedTitle")).toBeInTheDocument();
    // Not an error state.
    expect(screen.queryByText("warkah.errorTitle")).not.toBeInTheDocument();
  });

  /**
   * A **403** is a different thing and reads as one. Warkah is its own capability
   * family, so a reader who can open the deed and not its supporting bundle sees an
   * honest failure rather than a fabricated empty section.
   */
  it("maps a forbidden bundle onto a translated message, never server text", async () => {
    vi.mocked(services.getWarkah).mockRejectedValue(axiosError(403));
    vi.mocked(services.getWarkahItems).mockRejectedValue(
      axiosError(403, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderSection();

    await waitFor(() => {
      expect(screen.getByText("warkah.errors.forbidden")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("offers no write control to a view-only caller", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah());
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([item()]));

    renderSection();

    await screen.findByText("warkah.title");

    expect(screen.queryByRole("button", { name: "warkah.addItem" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "warkah.removeItem" })).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "warkah.actions.sendForReview" }),
    ).not.toBeInTheDocument();
  });

  /**
   * **`update` and `upload` are separate capabilities**, and an office may grant one
   * without the other: writing down which documents a transaction needs is a different
   * job from producing them.
   */
  it("separates composing the checklist from attaching to it", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah({ can_manage: true }));
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([item({ can_manage: true, can_upload: false })], {
        can_manage: true,
        can_upload: false,
      }),
    );

    renderSection();

    expect(await screen.findByRole("button", { name: "warkah.addItem" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "warkah.attachDocument" })).not.toBeInTheDocument();
  });

  it("offers verification only to a holder of the verify capability", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(
      warkah({ can_manage: true, can_verify: false }),
    );
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([item()], { can_manage: true }));

    renderSection();

    await screen.findByText("warkah.title");

    expect(screen.queryByRole("button", { name: "warkah.actions.verify" })).not.toBeInTheDocument();
  });

  /**
   * A Party the reader cannot open renders as plain text rather than a link that
   * answers 403 — the M4.5 rule. The line itself always renders: it is the office's own
   * checklist.
   */
  it("renders an unreachable party as text rather than a broken link", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah());
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([
        item({
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

    renderSection();

    expect(await screen.findByText("Budi Santoso")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "Budi Santoso" })).not.toBeInTheDocument();
  });

  /**
   * **A sensitive document is marked and never offered for download here.** Opening
   * answers to `documents.view` and downloading to `documents.download`, each with its
   * own Data Scope; a sensitive one additionally answers to
   * `documents.sensitive.download`, which D-115 leaves authorizing nothing until the
   * audit store exists. A Warkah capability is never a way past any of those.
   */
  it("marks a sensitive attachment and offers no download", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(warkah());
    vi.mocked(services.getWarkahItems).mockResolvedValue(
      itemList([
        item({
          has_document: true,
          documents: [
            {
              id: "01DOC",
              document_number: "DOC-2026-000001",
              title: "KTP Penjual",
              status: "VERIFIED",
              is_sensitive: true,
              attached_at: "2026-08-25T09:00:00+00:00",
            },
          ],
        }),
      ]),
    );

    renderSection();

    expect(await screen.findByText("warkah.sensitive")).toBeInTheDocument();

    const link = screen.getByRole("link", { name: "KTP Penjual" });

    expect(link).toHaveAttribute("href", "/documents/01DOC");
    expect(screen.queryByRole("button", { name: /download/i })).not.toBeInTheDocument();
  });

  /**
   * The status badge renders `FINALIZED` when a row carries it — stored vocabulary no
   * code path produces (D-121 §12) — while still offering no control that sets it.
   */
  it("renders an unreachable status without offering a control for it", async () => {
    vi.mocked(services.getWarkah).mockResolvedValue(
      warkah({ status: "FINALIZED", can_manage: true, can_verify: true }),
    );
    vi.mocked(services.getWarkahItems).mockResolvedValue(itemList([item()], { can_manage: true }));

    renderSection();

    expect(await screen.findByText("warkah.statuses.FINALIZED")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "warkah.actions.finalize" }),
    ).not.toBeInTheDocument();
  });
});
