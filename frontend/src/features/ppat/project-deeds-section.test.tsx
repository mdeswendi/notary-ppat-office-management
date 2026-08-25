import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { PpatProjectDeedsSection } from "@/features/ppat/project-deeds-section";
import { renderWithProviders } from "@/test/render";
import type { PpatDeed } from "@/types/ppat";

vi.mock("@/services/ppat", () => ({
  ppatDeedKeys: {
    all: () => ["ppat", "deeds"],
    list: (query: unknown) => ["ppat", "deeds", "list", query],
    detail: (id: string) => ["ppat", "deeds", "detail", id],
    options: () => ["ppat", "deeds", "options"],
  },
  getPpatDeeds: vi.fn(),
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

const ppat = await import("@/services/ppat");
const matters = await import("@/services/matters");

function deed(overrides: Partial<PpatDeed> = {}): PpatDeed {
  return {
    id: "01DEED",
    deed_number: "01/2026",
    deed_date: "2026-08-01",
    deed_type_code: "AJB",
    title: "Akta Jual Beli Uji",
    status: "FINALIZED",
    is_read_only: true,
    office: null,
    matter: {
      id: "01MATTER",
      matter_number: "P-2026-000001",
      title: "Pekerjaan Uji",
      domain: "PPAT",
      project_id: "01PROJECT",
    },
    final_document: null,
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

function page(rows: PpatDeed[]) {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: rows.length },
  };
}

beforeEach(() => {
  vi.mocked(ppat.getPpatDeeds).mockReset();
  vi.mocked(matters.getMatters).mockReset();

  vi.mocked(matters.getMatters).mockResolvedValue({
    data: [
      {
        id: "01MATTER",
        matter_number: "P-2026-000001",
        title: "Pekerjaan Uji",
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  } as never);
});

function renderSection() {
  return renderWithProviders(<PpatProjectDeedsSection projectId="01PROJECT" />);
}

/**
 * Presentation tests. The backend is the security boundary (`CLAUDE.md` §51).
 */
describe("PpatProjectDeedsSection", () => {
  /**
   * **The filter is what makes this one surface rather than two.** D-118 refused a
   * nested `/{entity}/{id}/deeds` route for exactly this question, so the section must
   * reach the ordinary deed list with `project_id` — not a second endpoint.
   */
  it("asks the deed list filtered by project, not a nested route", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    await waitFor(() => {
      expect(ppat.getPpatDeeds).toHaveBeenCalled();
    });

    const sent = vi.mocked(ppat.getPpatDeeds).mock.calls[0][0];

    expect(sent.project_id).toBe("01PROJECT");
  });

  it("asks only for PPAT matters when building its filter", async () => {
    // A Notary Matter cannot carry a PPAT deed at all — the Policy refuses the
    // parent — so offering one would be a control that finds nothing.
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([]));

    renderSection();

    await waitFor(() => {
      expect(matters.getMatters).toHaveBeenCalled();
    });

    const [domain, query] = vi.mocked(matters.getMatters).mock.calls[0];

    expect(domain).toBe("PPAT");
    expect(query.project_id).toBe("01PROJECT");
  });

  /**
   * Rows span several Matters here, unlike the Matter page — so the list grows a
   * Matter column and a Matter dropdown that the single-Matter view does without.
   */
  it("shows the matter each deed came from, linked to the ppat surface", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    const link = await screen.findByRole("link", { name: "P-2026-000001" });

    expect(link).toHaveAttribute("href", "/ppat/matters/01MATTER");
  });

  it("offers a matter filter drawn from the project's own matters", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    const control = await screen.findByLabelText("ppat.matterLabel");

    expect(control).toBeInTheDocument();
    expect(await screen.findByRole("option", { name: /P-2026-000001/ })).toBeInTheDocument();
  });

  /**
   * A project with no PPAT deeds is the ordinary state of most projects — including
   * every Notary-only one — so it reads as an empty state rather than a failure.
   */
  it("shows its own empty state rather than the deeds page one", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([]));

    renderSection();

    expect(await screen.findByText("ppat.projectDeedsEmptyTitle")).toBeInTheDocument();
    expect(screen.queryByText("ppat.deedsEmptyTitle")).not.toBeInTheDocument();
  });

  /**
   * Creating a deed needs a Matter, and this page does not know which one — so the
   * control belongs on the deed page and the Matter page, not here.
   */
  it("offers no create control", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([deed()]));

    renderSection();

    await screen.findByText("Akta Jual Beli Uji");

    expect(screen.queryByRole("link", { name: "ppat.newDeed" })).not.toBeInTheDocument();
  });

  /**
   * **The deed type code is shown verbatim, in the one place it is most tempting not
   * to.** `AJB` is the PPAT deed type everybody in an office knows the expansion of,
   * and expanding it here would be exactly the invented legal translation `CLAUDE.md`
   * §9 forbids — no canonical catalogue of type codes exists.
   */
  it("renders a deed type code verbatim in the row", async () => {
    vi.mocked(ppat.getPpatDeeds).mockResolvedValue(page([deed({ deed_type_code: "AJB" })]));

    renderSection();

    expect(await screen.findByText("AJB")).toBeInTheDocument();
    expect(screen.queryByText(/Deed of Sale and Purchase/)).not.toBeInTheDocument();
  });
});
