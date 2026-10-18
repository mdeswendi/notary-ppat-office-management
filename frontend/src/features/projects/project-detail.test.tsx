import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectDetail } from "@/features/projects/project-detail";
import { renderWithProviders } from "@/test/render";
import type { Project } from "@/types/project";

vi.mock("@/services/projects", () => ({
  projectQueryKeys: {
    all: ["projects"] as const,
    detail: (id: string) => ["projects", "detail", id] as const,
  },
  getProject: vi.fn(),
  changeProjectStatus: vi.fn(),
  archiveProject: vi.fn(),
}));

vi.mock("@/features/projects/project-setup-guide", () => ({
  ProjectSetupGuide: () => <div data-testid="mock-setup-guide" />,
}));

vi.mock("@/features/projects/project-assignment-section", () => ({
  ProjectAssignmentSection: () => <div data-testid="mock-assignment-section" />,
}));

vi.mock("@/features/projects/project-parties-section", () => ({
  ProjectPartiesSection: () => <div data-testid="mock-parties-section" />,
}));

vi.mock("@/features/projects/project-matters-section", () => ({
  ProjectMattersSection: () => <div data-testid="mock-matters-section" />,
}));

vi.mock("@/features/notary/project-deeds-section", () => ({
  ProjectDeedsSection: () => <div data-testid="mock-notary-deeds-section" />,
}));

vi.mock("@/features/ppat/project-deeds-section", () => ({
  PpatProjectDeedsSection: () => <div data-testid="mock-ppat-deeds-section" />,
}));

vi.mock("@/features/properties/project-properties-section", () => ({
  ProjectPropertiesSection: () => <div data-testid="mock-properties-section" />,
}));

vi.mock("@/features/documents/document-relation-section", () => ({
  DocumentRelationSection: () => <div data-testid="mock-documents-section" />,
}));

vi.mock("@/features/tasks/entity-task-section", () => ({
  EntityTaskSection: () => <div data-testid="mock-tasks-section" />,
}));

const projectServices = await import("@/services/projects");

const sampleProject: Project = {
  id: "PRJ-001",
  project_number: "PRJ-2026-0001",
  title: "Proyek Pembelian Lahan PT ABC",
  description: "Akuisisi 5 bidang tanah",
  status: "OPEN",
  priority: "NORMAL",
  opened_at: "2026-01-10",
  target_completion_date: "2026-06-30",
  completed_at: null,
  created_at: "2026-01-10T08:00:00Z",
  updated_at: "2026-01-10T08:00:00Z",
  office: { id: "OFF-1", code: "JKT-01", name: "Kantor Jakarta Pusat" },
  pic: null,
  can_update: true,
  can_assign: true,
  can_change_status: true,
  can_archive: false,
};

describe("ProjectDetail tabs", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.location.hash = "";
  });

  it("renders tab triggers and displays the overview tab by default", async () => {
    vi.mocked(projectServices.getProject).mockResolvedValue(sampleProject);

    renderWithProviders(<ProjectDetail projectId="PRJ-001" />);

    expect(await screen.findByText(sampleProject.title)).toBeInTheDocument();

    expect(screen.getByRole("tab", { name: /projects\.tabs\.overview/i })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /projects\.tabs\.parties/i })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /projects\.tabs\.matters/i })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /projects\.tabs\.documents/i })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /projects\.tabs\.tasks/i })).toBeInTheDocument();

    expect(screen.getByTestId("mock-assignment-section")).toBeInTheDocument();
    expect(screen.getByText("projects.overviewSection")).toBeInTheDocument();
  });

  it("switches to parties tab when parties trigger is clicked", async () => {
    const user = userEvent.setup();
    vi.mocked(projectServices.getProject).mockResolvedValue(sampleProject);

    renderWithProviders(<ProjectDetail projectId="PRJ-001" />);

    expect(await screen.findByText(sampleProject.title)).toBeInTheDocument();

    const partiesTab = screen.getByRole("tab", { name: /projects\.tabs\.parties/i });
    await user.click(partiesTab);

    expect(await screen.findByTestId("mock-parties-section")).toBeInTheDocument();
  });

  it("switches to matters tab and renders legal matters, deeds, and properties", async () => {
    const user = userEvent.setup();
    vi.mocked(projectServices.getProject).mockResolvedValue(sampleProject);

    renderWithProviders(<ProjectDetail projectId="PRJ-001" />);

    expect(await screen.findByText(sampleProject.title)).toBeInTheDocument();

    const mattersTab = screen.getByRole("tab", { name: /projects\.tabs\.matters/i });
    await user.click(mattersTab);

    expect(await screen.findByTestId("mock-matters-section")).toBeInTheDocument();
    expect(screen.getByTestId("mock-notary-deeds-section")).toBeInTheDocument();
    expect(screen.getByTestId("mock-ppat-deeds-section")).toBeInTheDocument();
    expect(screen.getByTestId("mock-properties-section")).toBeInTheDocument();
  });

  it("switches to documents tab when documents trigger is clicked", async () => {
    const user = userEvent.setup();
    vi.mocked(projectServices.getProject).mockResolvedValue(sampleProject);

    renderWithProviders(<ProjectDetail projectId="PRJ-001" />);

    expect(await screen.findByText(sampleProject.title)).toBeInTheDocument();

    const documentsTab = screen.getByRole("tab", { name: /projects\.tabs\.documents/i });
    await user.click(documentsTab);

    expect(await screen.findByTestId("mock-documents-section")).toBeInTheDocument();
  });

  it("switches to tasks tab when tasks trigger is clicked", async () => {
    const user = userEvent.setup();
    vi.mocked(projectServices.getProject).mockResolvedValue(sampleProject);

    renderWithProviders(<ProjectDetail projectId="PRJ-001" />);

    expect(await screen.findByText(sampleProject.title)).toBeInTheDocument();

    const tasksTab = screen.getByRole("tab", { name: /projects\.tabs\.tasks/i });
    await user.click(tasksTab);

    expect(await screen.findByTestId("mock-tasks-section")).toBeInTheDocument();
  });
});
