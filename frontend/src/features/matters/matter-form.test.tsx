import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MatterForm } from "@/features/matters/matter-form";
import { renderWithProviders } from "@/test/render";

vi.mock("@/services/matters", () => ({
  matterQueryKeys: {
    all: (domain: string) => ["matters", domain],
    serviceTypeOptions: (domain: string) => ["matters", domain, "service-types"],
  },
  getMatterServiceTypeOptions: vi.fn().mockResolvedValue([]),
  createMatter: vi.fn(),
  updateMatter: vi.fn(),
}));

vi.mock("@/services/projects", () => ({
  projectQueryKeys: {
    list: (query: unknown) => ["projects", "list", query],
    detail: (id: string) => ["projects", "detail", id],
  },
  getProjects: vi.fn(),
  getProject: vi.fn(),
}));

import type { Project } from "@/types/project";

const projectServices = await import("@/services/projects");

function mockProject(overrides: Partial<Project> = {}): Project {
  return {
    id: "prj-1",
    project_number: "PRJ-2026-0001",
    title: "Proyek Test",
    description: null,
    status: "OPEN",
    priority: "NORMAL",
    opened_at: null,
    target_completion_date: null,
    completed_at: null,
    office: { id: "off-1", code: "JKT", name: "Jakarta" },
    pic: null,
    created_at: null,
    updated_at: null,
    can_update: true,
    can_assign: true,
    can_change_status: true,
    can_archive: true,
    ...overrides,
  };
}

describe("MatterForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("pre-selects parent project and displays linked indicator when initialProjectId is provided", async () => {
    vi.mocked(projectServices.getProjects).mockResolvedValue({
      data: [
        mockProject({
          id: "prj-other",
          project_number: "PRJ-2026-0099",
          title: "Proyek Lain",
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    });

    vi.mocked(projectServices.getProject).mockResolvedValue(
      mockProject({
        id: "prj-123",
        project_number: "PRJ-2026-0123",
        title: "Akuisisi Lahan PT Sinar",
      }),
    );

    renderWithProviders(<MatterForm domain="NOTARY" initialProjectId="prj-123" />);

    // Wait for the initial project to be loaded and indicator rendered
    expect(await screen.findByText("matters.linkedToProject:")).toBeInTheDocument();
    const matches = screen.getAllByText(/PRJ-2026-0123 — Akuisisi Lahan PT Sinar/);
    expect(matches.length).toBeGreaterThanOrEqual(2);

    const select = screen.getByLabelText("matters.projectLabel");
    expect(select).toHaveValue("prj-123");
  });

  it("renders with unselected project dropdown when initialProjectId is not provided", async () => {
    vi.mocked(projectServices.getProjects).mockResolvedValue({
      data: [
        mockProject({
          id: "prj-1",
          project_number: "PRJ-2026-0001",
          title: "Proyek Pertama",
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    });

    renderWithProviders(<MatterForm domain="NOTARY" />);

    const select = await screen.findByLabelText("matters.projectLabel");
    expect(select).toHaveValue("");
    expect(screen.queryByText("matters.linkedToProject:")).not.toBeInTheDocument();
  });
});
