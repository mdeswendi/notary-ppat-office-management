import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DocumentRelationList } from "@/features/documents/document-relation-list";
import { renderWithProviders } from "@/test/render";
import type { DocumentRelations } from "@/types/document-relation";

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
  getDocumentRelations: vi.fn(),
  attachDocument: vi.fn(),
  detachDocument: vi.fn(),
  getRelationCandidates: vi.fn(),
}));

vi.mock("@/services/documents", () => ({
  documentQueryKeys: {
    all: () => ["documents"],
    list: (query: unknown) => ["documents", "list", query],
    detail: (id: string) => ["documents", "detail", id],
    options: () => ["documents", "options"],
  },
  getDocuments: vi.fn(),
}));

const relations = await import("@/services/document-relations");

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

function relationSet(overrides: Partial<DocumentRelations> = {}): DocumentRelations {
  return {
    parties: [],
    projects: [],
    matters: [],
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(relations.getDocumentRelations).mockReset();
  vi.mocked(relations.detachDocument).mockReset();
  vi.mocked(relations.getRelationCandidates).mockReset();
  vi.mocked(relations.getRelationCandidates).mockResolvedValue([]);
});

/**
 * **Presentation tests.** The backend is the security boundary (`CLAUDE.md` §28):
 * a passing assertion here never means an endpoint is authorized. What they pin is
 * that a control the actor may not use is *absent*, and that a link points where
 * the record actually lives.
 *
 * Assertions name **translation keys**, because the setup mock makes `t()` return
 * its key — that pins the component reaching for the right message and leaves the
 * translators free to reword.
 */
describe("DocumentRelationList", () => {
  it("offers no attach or detach control without can_update", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(
      relationSet({
        projects: [
          {
            id: "01PROJECT",
            entity_type: "project",
            label: "Proyek Uji",
            reference: "PRJ-2026-000001",
            attached_at: null,
          },
        ],
      }),
    );

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach={false} />);

    // The attachment is still shown — hiding it would make the list lie about
    // where the document sits.
    expect(await screen.findByText("Proyek Uji")).toBeInTheDocument();

    expect(
      screen.queryByRole("button", { name: "documents.relations.attach" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /documents\.relations\.detach/ }),
    ).not.toBeInTheDocument();
  });

  it("offers attach and detach when the flag allows it", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(
      relationSet({
        parties: [
          {
            id: "01PARTY",
            entity_type: "party",
            label: "Budi Santoso",
            reference: null,
            attached_at: null,
            party_type: "INDIVIDUAL",
          },
        ],
      }),
    );

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach />);

    // **Awaited, not queried synchronously.** The attach button renders outside
    // the query branches, so finding it proves nothing about the list having
    // loaded — asserting the detach button synchronously raced the relations
    // query and failed for timing rather than for behaviour.
    expect(
      await screen.findByRole("button", { name: "documents.relations.detach: Budi Santoso" }),
    ).toBeInTheDocument();

    expect(screen.getByRole("button", { name: "documents.relations.attach" })).toBeInTheDocument();
  });

  /**
   * The branch a type cannot express: a Matter's surface is chosen by its own
   * `domain`, which the API sends for exactly this reason. Sending the wrong one
   * would link a PPAT Matter into the Notary address, where it answers 404 by
   * design (D-101).
   */
  it("links each stub to the surface that owns the record", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(
      relationSet({
        parties: [
          {
            id: "01COMPANY",
            entity_type: "party",
            label: "PT Uji",
            reference: null,
            attached_at: null,
            party_type: "COMPANY",
          },
        ],
        matters: [
          {
            id: "01MATTER",
            entity_type: "matter",
            label: "Pekerjaan Uji",
            reference: "P-2026-000001",
            attached_at: null,
            domain: "PPAT",
          },
        ],
      }),
    );

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach={false} />);

    expect(await screen.findByRole("link", { name: "PT Uji" })).toHaveAttribute(
      "href",
      "/parties/companies/01COMPANY",
    );

    expect(screen.getByRole("link", { name: "Pekerjaan Uji" })).toHaveAttribute(
      "href",
      "/ppat/matters/01MATTER",
    );
  });

  it("routes an individual party and a notary matter to their own surfaces", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(
      relationSet({
        parties: [
          {
            id: "01IND",
            entity_type: "party",
            label: "Budi",
            reference: null,
            attached_at: null,
            party_type: "INDIVIDUAL",
          },
        ],
        matters: [
          {
            id: "01NOT",
            entity_type: "matter",
            label: "Notaris Uji",
            reference: "N-2026-000001",
            attached_at: null,
            domain: "NOTARY",
          },
        ],
      }),
    );

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach={false} />);

    expect(await screen.findByRole("link", { name: "Budi" })).toHaveAttribute(
      "href",
      "/parties/individuals/01IND",
    );

    expect(screen.getByRole("link", { name: "Notaris Uji" })).toHaveAttribute(
      "href",
      "/notary/matters/01NOT",
    );
  });

  it("maps a failed detach onto a translated message, never server text", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(
      relationSet({
        projects: [
          {
            id: "01PROJECT",
            entity_type: "project",
            label: "Proyek Uji",
            reference: "PRJ-2026-000001",
            attached_at: null,
          },
        ],
      }),
    );

    // 422 with no `file` error is the "not available from this state" branch,
    // which is what a stale detach answers.
    vi.mocked(relations.detachDocument).mockRejectedValue(
      axiosError(422, { message: "SQLSTATE[23503]: foreign key violation" }),
    );

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach />);

    const button = await screen.findByRole("button", {
      name: "documents.relations.detach: Proyek Uji",
    });

    await userEvent.click(button);

    await waitFor(() => {
      expect(screen.getByText("documents.errors.conflict")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("says so plainly when nothing is attached", async () => {
    vi.mocked(relations.getDocumentRelations).mockResolvedValue(relationSet());

    renderWithProviders(<DocumentRelationList documentId="01DOC" canAttach={false} />);

    expect(await screen.findByText("documents.relations.noRelations")).toBeInTheDocument();
  });
});
