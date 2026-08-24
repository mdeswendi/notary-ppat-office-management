import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DocumentDetail } from "@/features/documents/document-detail";
import { renderWithProviders } from "@/test/render";
import type { Document } from "@/types/document";

vi.mock("@/services/documents", () => ({
  documentQueryKeys: {
    all: () => ["documents"],
    detail: (id: string) => ["documents", "detail", id],
    list: (query: unknown) => ["documents", "list", query],
    options: () => ["documents", "options"],
  },
  getDocument: vi.fn(),
  getDocuments: vi.fn(),
  verifyDocument: vi.fn(),
  archiveDocument: vi.fn(),
  deleteDocument: vi.fn(),
  downloadDocument: vi.fn(),
}));

// The detail page delegates its relations block to `DocumentRelationList`, which
// asks its own endpoint (M5.3). Mocked here so this file keeps testing the detail
// page rather than the network.
vi.mock("@/services/document-relations", () => ({
  documentRelationKeys: {
    all: (id: string) => ["documents", "detail", id, "relations"],
    candidates: (type: string, search: string) => [
      "documents",
      "relation-candidates",
      type,
      search,
    ],
  },
  getDocumentRelations: vi.fn().mockResolvedValue({ parties: [], projects: [], matters: [] }),
  attachDocument: vi.fn(),
  detachDocument: vi.fn(),
  getRelationCandidates: vi.fn().mockResolvedValue([]),
}));

const services = await import("@/services/documents");

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

function document(overrides: Partial<Document> = {}): Document {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    document_number: "DOC-2026-000001",
    title: "Sertipikat Uji",
    document_type_code: "SERTIPIKAT",
    status: "RECEIVED",
    is_sensitive: false,
    document_date: null,
    expiry_date: null,
    notes: null,
    office: null,
    created_by: { id: "01USER", name: "Budi" },
    archived_at: null,
    archived_by: null,
    created_at: "2026-08-24T09:00:00+00:00",
    updated_at: null,
    current_version: null,
    versions: [],
    related: { parties: [], projects: [], matters: [] },
    can_update: false,
    can_upload: false,
    can_download: false,
    can_verify: false,
    can_archive: false,
    can_delete: false,
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getDocument).mockReset();
  vi.mocked(services.getDocuments).mockReset();
  vi.mocked(services.downloadDocument).mockReset();

  // The relation section fetches independently; an empty page keeps it quiet.
  vi.mocked(services.getDocuments).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
  });
});

function renderDetail() {
  return renderWithProviders(<DocumentDetail documentId="01ARZ3NDEKTSV4RRFFQ69G5FAV" />);
}

/**
 * These are **presentation tests**. The backend is the security boundary
 * (`CLAUDE.md` §28): a passing assertion here never means an endpoint is
 * authorized. What they pin is that a control the actor may not use is *absent*,
 * which is a real defect class — an offered button that 403s or 422s.
 *
 * Assertions name **translation keys, not sentences**, because the setup mock
 * makes `t()` return its key. That pins the thing that matters — the component
 * reached for the right message — and leaves the translators free to reword.
 */
describe("DocumentDetail", () => {
  it("offers no act the flags refuse", async () => {
    vi.mocked(services.getDocument).mockResolvedValue(document());

    renderDetail();

    await screen.findByText("Sertipikat Uji");

    expect(screen.queryByRole("button", { name: "documents.verify" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "documents.archive" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "documents.delete" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "documents.download" })).not.toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "actions.edit" })).not.toBeInTheDocument();
  });

  it("offers exactly the acts the flags allow", async () => {
    vi.mocked(services.getDocument).mockResolvedValue(
      document({ can_verify: true, can_delete: true }),
    );

    renderDetail();

    expect(await screen.findByRole("button", { name: "documents.verify" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "documents.delete" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "documents.archive" })).not.toBeInTheDocument();
  });

  /**
   * The D-115 gate, seen from the interface.
   *
   * A sensitive document is readable and its file is not. The screen must say so
   * rather than leave a person to wonder why the button vanished — a silently
   * missing control reads as a bug.
   */
  it("explains why a sensitive document cannot be downloaded", async () => {
    vi.mocked(services.getDocument).mockResolvedValue(
      document({ is_sensitive: true, can_download: false }),
    );

    renderDetail();

    await screen.findByText("Sertipikat Uji");

    expect(screen.queryByRole("button", { name: "documents.download" })).not.toBeInTheDocument();
    expect(screen.getByText("documents.sensitiveDownloadUnavailable")).toBeInTheDocument();
  });

  it("says nothing about downloads for an ordinary document the actor may not read", async () => {
    // Only the sensitive case gets an explanation. An ordinary document the actor
    // simply lacks `documents.download` for shows no message, because "you do not
    // have that permission" is not something a detail page should announce.
    vi.mocked(services.getDocument).mockResolvedValue(
      document({ is_sensitive: false, can_download: false }),
    );

    renderDetail();

    await screen.findByText("Sertipikat Uji");

    expect(screen.queryByText("documents.sensitiveDownloadUnavailable")).not.toBeInTheDocument();
  });

  it("maps a failed load onto a translated message, never server text", async () => {
    vi.mocked(services.getDocument).mockRejectedValue(
      axiosError(404, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderDetail();

    await waitFor(() => {
      expect(screen.getByText("documents.errors.notFound")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("shows status and sensitivity as text, not colour alone", async () => {
    vi.mocked(services.getDocument).mockResolvedValue(
      document({ status: "VERIFIED", is_sensitive: true }),
    );

    renderDetail();

    // `CLAUDE.md` §49: status must not rely on colour. Both badges carry words.
    expect(await screen.findByText("documents.statuses.VERIFIED")).toBeInTheDocument();
    expect(screen.getByText("documents.sensitive")).toBeInTheDocument();
  });

  /**
   * **Moved at M5.3, not deleted.** This file used to assert that the detail
   * page's own `RelatedRecords` block linked each stub to the surface that owns
   * it. That block is gone: M5.3 replaced it with `DocumentRelationList`, which
   * asks its own endpoint and carries attach and detach.
   *
   * The assertion still exists and is stronger — it now covers all four routing
   * branches instead of two — in `document-relation-list.test.tsx`. What belongs
   * here is what this page still decides: that it hands the list the right
   * document and the right capability flag.
   */
  it("delegates relations to the list, gated on can_update", async () => {
    vi.mocked(services.getDocument).mockResolvedValue(document({ can_update: false }));

    renderDetail();

    await screen.findByText("Sertipikat Uji");

    // The list renders; the attach control does not, because `can_update` is
    // false. Attaching answers to `documents.update` (D-118).
    expect(await screen.findByText("documents.relations.noRelations")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "documents.relations.attach" }),
    ).not.toBeInTheDocument();
  });
});
