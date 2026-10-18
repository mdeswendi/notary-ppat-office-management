import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectMattersSection } from "@/features/projects/project-matters-section";
import { renderWithProviders } from "@/test/render";
import type { Matter } from "@/types/matter";

vi.mock("@/features/auth/use-current-user", () => ({
  useCurrentUser: () => ({
    data: {
      id: "usr-1",
      name: "Budi Santoso",
      email: "budi@example.com",
      preferred_locale: "id",
      office: null,
      roles: ["NOTARY", "PPAT"],
      permissions: ["notary.matters.create", "ppat.matters.create"],
      permission_scopes: {
        "notary.matters.create": ["ALL"],
        "ppat.matters.create": ["ALL"],
      },
    },
    isPending: false,
  }),
}));

vi.mock("@/services/matters", () => ({
  matterQueryKeys: {
    list: (domain: string, query: unknown) => ["matters", domain, "list", query],
  },
  getMatters: vi.fn(),
}));

const matterServices = await import("@/services/matters");

const mockNotaryMatter: Matter = {
  id: "matter-notary-1",
  matter_number: "NOT-2026-0001",
  domain: "NOTARY",
  title: "Akta Pendirian PT Berkah",
  status: "OPEN",
  priority: "NORMAL",
  notes: null,
  opened_at: "2026-03-01",
  target_completion_date: "2026-03-15",
  completed_at: null,
  office: { id: "off-1", code: "JKT", name: "Jakarta Pusat" },
  project: { id: "prj-1", project_number: "PRJ-2026-0001", title: "Proyek Alpha" },
  service_type: {
    id: "st-1",
    code: "AKTA_PENDIRIAN",
    name_id: "Pendirian PT",
    name_en: "Company Establishment",
    is_active: true,
  },
  pic: { id: "usr-1", name: "Budi Santoso" },
  created_at: "2026-03-01T10:00:00Z",
  updated_at: "2026-03-01T10:00:00Z",
};

const mockPpatMatter: Matter = {
  id: "matter-ppat-1",
  matter_number: "PPAT-2026-0001",
  domain: "PPAT",
  title: "AJB Tanah & Bangunan Menteng",
  status: "IN_PROGRESS",
  priority: "HIGH",
  notes: null,
  opened_at: "2026-03-02",
  target_completion_date: "2026-03-20",
  completed_at: null,
  office: { id: "off-1", code: "JKT", name: "Jakarta Pusat" },
  project: { id: "prj-1", project_number: "PRJ-2026-0001", title: "Proyek Alpha" },
  service_type: {
    id: "st-2",
    code: "AJB",
    name_id: "Akta Jual Beli",
    name_en: "Deed of Sale and Purchase",
    is_active: true,
  },
  pic: { id: "usr-2", name: "Siti Rahma" },
  created_at: "2026-03-02T10:00:00Z",
  updated_at: "2026-03-02T10:00:00Z",
};

describe("ProjectMattersSection", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders empty state with create buttons when project has no matters", async () => {
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    });

    renderWithProviders(<ProjectMattersSection projectId="prj-1" />);

    expect(await screen.findByText("projects.mattersEmptyTitle")).toBeInTheDocument();
    expect(screen.getByText("projects.mattersEmptyDescription")).toBeInTheDocument();

    const notaryCreateLinks = await screen.findAllByRole("link", {
      name: "projects.addNotaryMatter",
    });
    expect(notaryCreateLinks[0]).toHaveAttribute("href", "/notary/matters/new?project_id=prj-1");

    const ppatCreateLinks = screen.getAllByRole("link", {
      name: "projects.addPpatMatter",
    });
    expect(ppatCreateLinks[0]).toHaveAttribute("href", "/ppat/matters/new?project_id=prj-1");
  });

  it("renders table with both Notary and PPAT matters and allows filtering by domain", async () => {
    vi.mocked(matterServices.getMatters).mockImplementation(async (domain) => {
      if (domain === "NOTARY") {
        return {
          data: [mockNotaryMatter],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
        };
      }
      return {
        data: [mockPpatMatter],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      };
    });

    renderWithProviders(<ProjectMattersSection projectId="prj-1" />);

    expect(await screen.findByText("NOT-2026-0001")).toBeInTheDocument();
    expect(screen.getByText("PPAT-2026-0001")).toBeInTheDocument();
    expect(screen.getByText("Akta Pendirian PT Berkah")).toBeInTheDocument();
    expect(screen.getByText("AJB Tanah & Bangunan Menteng")).toBeInTheDocument();

    // Verify filter buttons exist
    const allBtn = screen.getByRole("button", { name: /projects\.allDomains/ });
    const notaryBtn = screen.getByRole("button", { name: /projects\.notaryDomain/ });
    const ppatBtn = screen.getByRole("button", { name: /projects\.ppatDomain/ });

    expect(allBtn).toBeInTheDocument();
    expect(notaryBtn).toBeInTheDocument();
    expect(ppatBtn).toBeInTheDocument();

    const user = userEvent.setup();

    // Filter to PPAT only
    await user.click(ppatBtn);
    expect(screen.queryByText("NOT-2026-0001")).not.toBeInTheDocument();
    expect(screen.getByText("PPAT-2026-0001")).toBeInTheDocument();

    // Filter to Notary only
    await user.click(notaryBtn);
    expect(screen.getByText("NOT-2026-0001")).toBeInTheDocument();
    expect(screen.queryByText("PPAT-2026-0001")).not.toBeInTheDocument();

    // Return to All
    await user.click(allBtn);
    expect(screen.getByText("NOT-2026-0001")).toBeInTheDocument();
    expect(screen.getByText("PPAT-2026-0001")).toBeInTheDocument();
  });

  it("handles one domain error gracefully while displaying available domain data", async () => {
    vi.mocked(matterServices.getMatters).mockImplementation(async (domain) => {
      if (domain === "NOTARY") {
        throw new Error("403 Forbidden");
      }
      return {
        data: [mockPpatMatter],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      };
    });

    renderWithProviders(<ProjectMattersSection projectId="prj-1" />);

    // PPAT matter still displays
    expect(await screen.findByText("PPAT-2026-0001")).toBeInTheDocument();
    expect(screen.getByText("AJB Tanah & Bangunan Menteng")).toBeInTheDocument();
  });

  it("shows error state when both domain queries fail", async () => {
    vi.mocked(matterServices.getMatters).mockRejectedValue(new Error("Network Error"));

    renderWithProviders(<ProjectMattersSection projectId="prj-1" />);

    expect(await screen.findByText("matters.errorTitle")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "actions.retry" })).toBeInTheDocument();
  });
});
