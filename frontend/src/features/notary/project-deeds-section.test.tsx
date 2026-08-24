import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectDeedsSection } from "@/features/notary/project-deeds-section";
import { renderWithProviders } from "@/test/render";
import type { NotaryDeed } from "@/types/notary";

vi.mock("@/services/notary", () => ({
  notaryDeedKeys: {
    all: () => ["notary", "deeds"],
    minuta: (id: string) => ["notary", "deeds", "detail", id, "minuta"],
    list: (query: unknown) => ["notary", "deeds", "list", query],
    detail: (id: string) => ["notary", "deeds", "detail", id],
    options: () => ["notary", "deeds", "options"],
  },
  getNotaryDeeds: vi.fn(),
}));

vi.mock("@/services/matters", () => ({
  matterQueryKeys: {
    all: (domain: string) => ["matters", domain],
    list: (domain: string, query: unknown) => ["matters", domain, "list", query],
    detail: (domain: string, id: string) => ["matters", domain, "detail", id],
    assigneeOptions: (domain: string, id: string) => ["matters", domain, "detail", id, "assignees"],
    serviceTypeOptions: (domain: string) => ["matters", domain, "service-types"],
  },
  getMatters: vi.fn(),
}));

const notary = await import("@/services/notary");
const matters = await import("@/services/matters");

function deed(overrides: Partial<NotaryDeed> = {}): NotaryDeed {
  return {
    id: "01DEED",
    deed_number: "01/2026",
    deed_date: "2026-08-01",
    deed_type_code: "AKTA_PERUBAHAN",
    title: "Akta Perubahan",
    status: "FINALIZED",
    is_read_only: true,
    office: null,
    matter: {
      id: "01MATTER",
      matter_number: "N-2026-000001",
      title: "Pekerjaan Uji",
      domain: "NOTARY",
      project_id: "01PROJECT",
    },
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
    created_at: null,
    updated_at: null,
    can_update: false,
    can_review: false,
    can_approve: false,
    can_finalize: false,
    can_record_number: false,
    ...overrides,
  };
}

function page(rows: NotaryDeed[]) {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: rows.length },
  };
}

beforeEach(() => {
  vi.mocked(notary.getNotaryDeeds).mockReset();
  vi.mocked(matters.getMatters).mockReset();

  vi.mocked(matters.getMatters).mockResolvedValue({
    data: [
      {
        id: "01MATTER",
        matter_number: "N-2026-000001",
        title: "Pekerjaan Uji",
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  } as never);
});

function renderSection() {
  return renderWithProviders(<ProjectDeedsSection projectId="01PROJECT" />);
}

/**
 * Presentation tests. The backend is the security boundary (`CLAUDE.md` §51).
 */
describe("ProjectDeedsSection", () => {
  /**
   * **The filter is what makes this one surface rather than two.** D-118 refused a
   * nested `/{entity}/{id}/deeds` route for exactly this question, so the section
   * must reach the ordinary deed list with `project_id` — not a second endpoint.
   */
  it("asks the deed list filtered by project, not a nested route", async () => {
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    await waitFor(() => {
      expect(notary.getNotaryDeeds).toHaveBeenCalled();
    });

    const sent = vi.mocked(notary.getNotaryDeeds).mock.calls[0][0];

    expect(sent.project_id).toBe("01PROJECT");
  });

  it("asks only for NOTARY matters when building its filter", async () => {
    // PPAT deeds are a different table in M7, and a PPAT Matter cannot carry a
    // notarial deed at all — so offering one would be a control that finds nothing.
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([]));

    renderSection();

    await waitFor(() => {
      expect(matters.getMatters).toHaveBeenCalled();
    });

    const [domain, query] = vi.mocked(matters.getMatters).mock.calls[0];

    expect(domain).toBe("NOTARY");
    expect(query.project_id).toBe("01PROJECT");
  });

  /**
   * Rows span several Matters here, unlike the Matter page — so the list grows a
   * Matter column and a Matter dropdown that the single-Matter view does without.
   */
  it("shows the matter each deed came from", async () => {
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    const link = await screen.findByRole("link", { name: "N-2026-000001" });

    expect(link).toHaveAttribute("href", "/notary/matters/01MATTER");
  });

  it("offers a matter filter drawn from the project's own matters", async () => {
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    const control = await screen.findByLabelText("notary.matterLabel");

    expect(control).toBeInTheDocument();
    expect(await screen.findByRole("option", { name: /N-2026-000001/ })).toBeInTheDocument();
  });

  /**
   * A project with no deeds is the ordinary state of most projects, so it reads as
   * an empty state rather than a failure.
   */
  it("shows its own empty state rather than the deeds page one", async () => {
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([]));

    renderSection();

    expect(await screen.findByText("notary.projectDeedsEmptyTitle")).toBeInTheDocument();
    expect(screen.queryByText("notary.deedsEmptyTitle")).not.toBeInTheDocument();
  });

  /**
   * Creating a deed needs a Matter, and this page does not know which one — so the
   * control belongs on the deed page and the Matter page, not here.
   */
  it("offers no create control", async () => {
    vi.mocked(notary.getNotaryDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    await screen.findByText("Akta Perubahan");

    expect(screen.queryByRole("link", { name: "notary.newDeed" })).not.toBeInTheDocument();
  });
});
