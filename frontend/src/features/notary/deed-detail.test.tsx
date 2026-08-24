import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DeedDetail } from "@/features/notary/deed-detail";
import { renderWithProviders } from "@/test/render";
import type { NotaryDeed } from "@/types/notary";

vi.mock("@/services/notary", () => ({
  notaryDeedKeys: {
    all: () => ["notary", "deeds"],
    list: (query: unknown) => ["notary", "deeds", "list", query],
    detail: (id: string) => ["notary", "deeds", "detail", id],
    options: () => ["notary", "deeds", "options"],
  },
  getNotaryDeed: vi.fn(),
  getNotaryDeeds: vi.fn(),
  getNotaryDeedOptions: vi.fn(),
  createNotaryDeed: vi.fn(),
  updateNotaryDeed: vi.fn(),
  reviewNotaryDeed: vi.fn(),
  approveNotaryDeed: vi.fn(),
  finalizeNotaryDeed: vi.fn(),
  recordNotaryDeedNumber: vi.fn(),
}));

const services = await import("@/services/notary");

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

function deed(overrides: Partial<NotaryDeed> = {}): NotaryDeed {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    deed_number: null,
    deed_date: null,
    deed_type_code: null,
    title: "Akta Uji",
    status: "DRAFT",
    is_read_only: false,
    office: null,
    matter: null,
    draft_document: null,
    final_document: null,
    minuta_document: null,
    reviewed_at: null,
    reviewed_by: null,
    approved_at: null,
    approved_by: null,
    finalized_at: null,
    finalized_by: null,
    locked_at: null,
    created_at: "2026-08-24T09:00:00+00:00",
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
  vi.mocked(services.getNotaryDeed).mockReset();
});

function renderDetail() {
  return renderWithProviders(<DeedDetail deedId="01ARZ3NDEKTSV4RRFFQ69G5FAV" />);
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
describe("DeedDetail", () => {
  it("offers no act the flags refuse", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(deed());

    renderDetail();

    await screen.findByText("Akta Uji");

    expect(
      screen.queryByRole("button", { name: "notary.submitForReview" }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "notary.approve" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "notary.finalize" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText("notary.deedNumber")).not.toBeInTheDocument();
  });

  /**
   * **Five capabilities, five controls, and they do not travel together.**
   *
   * `notary.deeds.number` in particular is its own code — the catalogue defined it
   * separately from `finalize`, and folding them would assert *when* a deed is
   * numbered (D-120).
   */
  it("offers exactly the acts the flags allow, one capability at a time", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({ status: "UNDER_REVIEW", can_approve: true }),
    );

    renderDetail();

    expect(await screen.findByRole("button", { name: "notary.approve" })).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "notary.submitForReview" }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "notary.finalize" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText("notary.deedNumber")).not.toBeInTheDocument();
  });

  it("offers numbering independently of finalizing", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({ can_record_number: true, can_finalize: false }),
    );

    renderDetail();

    expect(await screen.findByLabelText("notary.deedNumber")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "notary.finalize" })).not.toBeInTheDocument();
  });

  /**
   * Numbering stays available on a finalized deed, because *when* a deed is numbered
   * is the office's decision and not the software's — the open question D-120
   * refused to answer.
   */
  it("still offers numbering on a finalized deed", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({ status: "FINALIZED", is_read_only: true, can_record_number: true }),
    );

    renderDetail();

    expect(await screen.findByLabelText("notary.deedNumber")).toBeInTheDocument();
    expect(screen.getByText("notary.finalizedNotice")).toBeInTheDocument();
  });

  /**
   * **No delete, void or supersede control exists at all.** No canonical capability
   * authorizes any of them and the correction mechanisms that would need them are an
   * open domain question, so the buttons are absent rather than disabled.
   */
  it("offers no delete, void or supersede control even to a fully capable actor", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({
        can_update: true,
        can_review: true,
        can_approve: true,
        can_finalize: true,
        can_record_number: true,
      }),
    );

    renderDetail();

    await screen.findByText("Akta Uji");

    for (const key of ["notary.delete", "notary.void", "notary.supersede", "actions.delete"]) {
      expect(screen.queryByRole("button", { name: key })).not.toBeInTheDocument();
    }
  });

  it("shows an unnumbered deed as unnumbered rather than blank", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(deed({ deed_number: null }));

    renderDetail();

    expect(await screen.findByText("notary.unnumbered")).toBeInTheDocument();
  });

  it("maps a failed load onto a translated message, never server text", async () => {
    vi.mocked(services.getNotaryDeed).mockRejectedValue(
      axiosError(404, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderDetail();

    await waitFor(() => {
      expect(screen.getByText("notary.errors.notFound")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  /**
   * `CLAUDE.md` §49: status must not rely on colour alone. Both badges carry words,
   * and the read-only marker carries a word beside its icon.
   */
  it("shows status, type and read-only as text, not colour alone", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({ status: "FINALIZED", deed_type_code: "UJI-TYPE", is_read_only: true }),
    );

    renderDetail();

    expect(await screen.findByText("notary.deedStatuses.FINALIZED")).toBeInTheDocument();
    expect(screen.getByText("UJI-TYPE")).toBeInTheDocument();
    expect(screen.getByText("notary.readOnly")).toBeInTheDocument();
  });

  /**
   * A deed type code is rendered verbatim, never expanded into a legal name.
   * `CLAUDE.md` §9 forbids inventing legal translations, and no catalogue exists.
   */
  it("renders a deed type code verbatim", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(deed({ deed_type_code: "AJB" }));

    renderDetail();

    expect(await screen.findByText("AJB")).toBeInTheDocument();
    expect(screen.queryByText(/Akta Jual Beli/)).not.toBeInTheDocument();
  });

  it("links a matter to the notary surface", async () => {
    vi.mocked(services.getNotaryDeed).mockResolvedValue(
      deed({
        matter: {
          id: "01MATTER",
          matter_number: "N-2026-000001",
          title: "Pekerjaan Uji",
          domain: "NOTARY",
          project_id: null,
        },
      }),
    );

    renderDetail();

    const link = await screen.findByRole("link", { name: /N-2026-000001/ });

    expect(link).toHaveAttribute("href", "/notary/matters/01MATTER");
  });
});
