import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PpatDeedDetail } from "@/features/ppat/deed-detail";
import { renderWithProviders } from "@/test/render";
import type { PpatDeed } from "@/types/ppat";

vi.mock("@/services/ppat", () => ({
  ppatDeedKeys: {
    all: () => ["ppat", "deeds"],
    list: (query: unknown) => ["ppat", "deeds", "list", query],
    detail: (id: string) => ["ppat", "deeds", "detail", id],
    options: () => ["ppat", "deeds", "options"],
  },
  getPpatDeed: vi.fn(),
  getPpatDeeds: vi.fn(),
  getPpatDeedOptions: vi.fn(),
  createPpatDeed: vi.fn(),
  updatePpatDeed: vi.fn(),
  reviewPpatDeed: vi.fn(),
  approvePpatDeed: vi.fn(),
  finalizePpatDeed: vi.fn(),
  recordPpatDeedNumber: vi.fn(),
}));

// The deed page delegates its Warkah block to `WarkahSection`, which asks its own
// endpoint under `ppat.warkah.*` (M7.4). Mocked here so this file keeps testing the
// deed page rather than the network. A rejection is the ordinary "nothing started"
// answer, and a `warkah_started: false` list is the empty state beside it.
vi.mock("@/services/warkah", () => ({
  warkahKeys: {
    all: () => ["ppat", "warkah"],
    list: (query: unknown) => ["ppat", "warkah", "list", query],
    options: () => ["ppat", "warkah", "options"],
    forDeed: (id: string) => ["ppat", "deeds", id, "warkah"],
    items: (id: string) => ["ppat", "deeds", id, "warkah", "items"],
  },
  getWarkah: vi.fn().mockRejectedValue(new Error("no warkah")),
  getWarkahItems: vi.fn().mockResolvedValue({
    data: [],
    meta: {
      total: 0,
      can_manage: false,
      can_upload: false,
      collected: 0,
      completeness_percentage: 0,
      warkah_started: false,
    },
  }),
  setWarkahStatus: vi.fn(),
  verifyWarkah: vi.fn(),
  addWarkahItem: vi.fn(),
  removeWarkahItem: vi.fn(),
  attachWarkahDocument: vi.fn(),
  detachWarkahDocument: vi.fn(),
}));

const services = await import("@/services/ppat");

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

function deed(overrides: Partial<PpatDeed> = {}): PpatDeed {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    deed_number: null,
    deed_date: null,
    deed_type_code: null,
    title: "Akta PPAT Uji",
    status: "DRAFT",
    is_read_only: false,
    office: null,
    matter: null,
    final_document: null,
    reviewed_at: null,
    reviewed_by: null,
    approved_at: null,
    approved_by: null,
    finalized_at: null,
    finalized_by: null,
    locked_at: null,
    created_at: "2026-08-25T09:00:00+00:00",
    updated_at: null,
    can_update: false,
    can_review: false,
    can_approve: false,
    can_finalize: false,
    can_record_number: false,
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getPpatDeed).mockReset();
});

function renderDetail() {
  return renderWithProviders(<PpatDeedDetail deedId="01ARZ3NDEKTSV4RRFFQ69G5FAV" />);
}

/**
 * These are **presentation tests**. The backend is the security boundary
 * (`CLAUDE.md` §51): a passing assertion here never means an endpoint is authorized.
 * What they pin is that a control the actor may not use is *absent*, which is a real
 * defect class — an offered button that answers 403 or 422.
 *
 * Assertions name **translation keys, not sentences**, because the setup mock makes
 * `t()` return its key.
 */
describe("PpatDeedDetail", () => {
  it("offers no act the flags refuse", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(deed());

    renderDetail();

    await screen.findByText("Akta PPAT Uji");

    expect(screen.queryByRole("button", { name: "ppat.submitForReview" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "ppat.approve" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "ppat.finalize" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText("ppat.deedNumber")).not.toBeInTheDocument();
  });

  /**
   * **Five capabilities, five controls, and they do not travel together.**
   *
   * Approve in particular is gated on `ppat.deeds.approve` alone. The brief asked for
   * *"hanya PRINCIPAL/SUPER_ADMIN"*; that restriction is an office's grant of the
   * capability, never a role-name check in code (D-032, D-048) — so what this page
   * reads is a flag the Policy computed, and it knows no role names at all.
   */
  it("offers exactly the acts the flags allow, one capability at a time", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({ status: "UNDER_REVIEW", can_approve: true }),
    );

    renderDetail();

    expect(await screen.findByRole("button", { name: "ppat.approve" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "ppat.submitForReview" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "ppat.finalize" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText("ppat.deedNumber")).not.toBeInTheDocument();
  });

  it("offers numbering independently of finalizing", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({ can_record_number: true, can_finalize: false }),
    );

    renderDetail();

    expect(await screen.findByLabelText("ppat.deedNumber")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "ppat.finalize" })).not.toBeInTheDocument();
  });

  /**
   * Numbering stays available on a finalized deed, because *when* a deed is numbered
   * is the office's decision and not the software's — open question five, which D-121
   * refused to answer.
   */
  it("still offers numbering on a finalized deed", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({ status: "FINALIZED", is_read_only: true, can_record_number: true }),
    );

    renderDetail();

    expect(await screen.findByLabelText("ppat.deedNumber")).toBeInTheDocument();
    expect(screen.getByText("ppat.finalizedNotice")).toBeInTheDocument();
  });

  /**
   * **No delete, void or supersede control exists at all.** The catalogue has no
   * `ppat.deeds.delete`, `.void` or `.lock` code — the M7.2 brief conditioned its
   * `destroy` endpoint on one existing, and it does not — and the correction
   * mechanisms that would need them are open question nine. The buttons are absent
   * rather than disabled.
   */
  it("offers no delete, void or supersede control even to a fully capable actor", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({
        can_update: true,
        can_review: true,
        can_approve: true,
        can_finalize: true,
        can_record_number: true,
      }),
    );

    renderDetail();

    await screen.findByText("Akta PPAT Uji");

    for (const key of ["ppat.delete", "ppat.void", "ppat.supersede", "actions.delete"]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  /**
   * **One document slot, not three.** `ppat_deeds` carries `final_document_id` alone;
   * the draft and Minuta slots belong to the Notary deed page.
   *
   * **Narrowed at M7.4, not deleted.** This also asserted that nothing on the page
   * mentioned a Warkah, which was true while M7.4 had not shipped one. It now has, and
   * the page carries a `WarkahSection` that asks its own endpoint.
   *
   * The claim worth keeping is the one about the **payload**: a PPAT deed's supporting
   * material is its Warkah, and `PpatDeedResource` deliberately carries no Warkah key
   * and no completeness figure — reading a deed does not read which supporting legal
   * documents an office does or does not hold. The section is a separate request under
   * `ppat.warkah.view`, and it fails honestly for a reader who holds one capability and
   * not the other.
   */
  it("shows one document slot, and carries no warkah in the deed payload", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(deed());

    renderDetail();

    await screen.findByText("Akta PPAT Uji");

    expect(screen.getByText("ppat.documentSlots.final")).toBeInTheDocument();
    expect(screen.queryByText("ppat.documentSlots.draft")).not.toBeInTheDocument();
    expect(screen.queryByText("ppat.documentSlots.minuta")).not.toBeInTheDocument();

    // The payload the deed endpoint returns names no bundle and no percentage.
    const payload = vi.mocked(services.getPpatDeed).mock.results[0]?.value as Promise<unknown>;

    expect(await payload).not.toHaveProperty("warkah");
    expect(await payload).not.toHaveProperty("completeness_percentage");
  });

  it("shows an unnumbered deed as unnumbered rather than blank", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(deed({ deed_number: null }));

    renderDetail();

    expect(await screen.findByText("ppat.unnumbered")).toBeInTheDocument();
  });

  it("maps a failed load onto a translated message, never server text", async () => {
    vi.mocked(services.getPpatDeed).mockRejectedValue(
      axiosError(404, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderDetail();

    await waitFor(() => {
      expect(screen.getByText("ppat.errors.notFound")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  /**
   * `CLAUDE.md` §49: status must not rely on colour alone. Both badges carry words,
   * and the read-only marker carries a word beside its icon — which matters more here
   * than on the Notary page, because this is the first surface to use the PPAT teal.
   */
  it("shows status, type and read-only as text, not colour alone", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({ status: "FINALIZED", deed_type_code: "UJI-TYPE", is_read_only: true }),
    );

    renderDetail();

    expect(await screen.findByText("ppat.deedStatuses.FINALIZED")).toBeInTheDocument();
    expect(screen.getByText("UJI-TYPE")).toBeInTheDocument();
    expect(screen.getByText("ppat.readOnly")).toBeInTheDocument();
  });

  /**
   * A deed type code is rendered verbatim, never expanded into a legal name.
   * `CLAUDE.md` §9 forbids inventing legal translations, and no catalogue exists —
   * which bites hardest here, because `AJB` is the PPAT deed type everybody knows.
   */
  it("renders a deed type code verbatim", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(deed({ deed_type_code: "AJB" }));

    renderDetail();

    expect(await screen.findByText("AJB")).toBeInTheDocument();
    expect(screen.queryByText(/Akta Jual Beli/)).not.toBeInTheDocument();
  });

  it("links a matter to the ppat surface, never the notary one", async () => {
    vi.mocked(services.getPpatDeed).mockResolvedValue(
      deed({
        matter: {
          id: "01MATTER",
          matter_number: "P-2026-000001",
          title: "Pekerjaan Uji",
          domain: "PPAT",
          project_id: null,
        },
      }),
    );

    renderDetail();

    const link = await screen.findByRole("link", { name: /P-2026-000001/ });

    expect(link).toHaveAttribute("href", "/ppat/matters/01MATTER");
  });
});
