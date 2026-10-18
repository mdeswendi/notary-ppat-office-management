import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectForm } from "@/features/projects/project-form";
import { renderWithProviders } from "@/test/render";
import type { PartyDirectoryEntry } from "@/types/party";
import type { Project } from "@/types/project";

vi.mock("@/services/projects", () => ({
  projectQueryKeys: {
    all: ["projects"],
    detail: (id: string) => ["projects", "detail", id],
  },
  createProject: vi.fn(),
  updateProject: vi.fn(),
}));

vi.mock("@/services/parties", () => ({
  partyDirectoryKeys: {
    all: ["parties"],
    list: (query: unknown) => ["parties", "directory", query],
  },
  getPartyDirectory: vi.fn(),
}));

vi.mock("@/services/project-parties", () => ({
  addProjectParty: vi.fn(),
}));

const projectServices = await import("@/services/projects");
const partyServices = await import("@/services/parties");
const projectPartyServices = await import("@/services/project-parties");

function mockClient(overrides: Partial<PartyDirectoryEntry> = {}): PartyDirectoryEntry {
  return {
    id: "party-client-1",
    party_type: "INDIVIDUAL",
    display_name: "Budi Santoso",
    primary_phone: "08123456789",
    primary_email: "budi@example.com",
    office: { id: "off-1", code: "JKT", name: "Jakarta" },
    individual: { full_name: "Budi Santoso" },
    company: null,
    created_at: "2026-01-01T00:00:00Z",
    ...overrides,
  };
}

function mockProject(overrides: Partial<Project> = {}): Project {
  return {
    id: "prj-1",
    project_number: "PRJ-2026-0001",
    title: "Proyek Test",
    description: "Keterangan proyek",
    status: "OPEN",
    priority: "NORMAL",
    opened_at: "2026-01-01",
    target_completion_date: "2026-02-01",
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

describe("ProjectForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders new project form with client picker when creating", () => {
    vi.mocked(partyServices.getPartyDirectory).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    });

    renderWithProviders(<ProjectForm />);

    expect(screen.getByLabelText("projects.titleLabel")).toBeInTheDocument();
    expect(screen.getByLabelText("projects.primaryClientLabel")).toBeInTheDocument();
    expect(screen.getByPlaceholderText("projects.searchClientPlaceholder")).toBeInTheDocument();
  });

  it("does not render client picker when editing an existing project", () => {
    renderWithProviders(<ProjectForm project={mockProject()} />);

    expect(screen.getByLabelText("projects.titleLabel")).toBeInTheDocument();
    expect(screen.queryByLabelText("projects.primaryClientLabel")).not.toBeInTheDocument();
  });

  it("allows searching and selecting a primary client", async () => {
    const user = userEvent.setup();
    const client = mockClient({ display_name: "Budi Santoso" });

    vi.mocked(partyServices.getPartyDirectory).mockResolvedValue({
      data: [client],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });

    renderWithProviders(<ProjectForm />);

    const searchInput = screen.getByPlaceholderText("projects.searchClientPlaceholder");
    await user.type(searchInput, "Budi");

    // Wait for debounced query and candidate list
    const candidateBtn = await screen.findByRole("button", { name: /Budi Santoso/i });
    expect(candidateBtn).toBeInTheDocument();

    // Click candidate
    await user.click(candidateBtn);

    // Selected client card should now be rendered
    expect(await screen.findByText("Budi Santoso")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "projects.clearClientAction" })).toBeInTheDocument();
  });

  it("allows clearing selected client", async () => {
    const user = userEvent.setup();
    const client = mockClient({ display_name: "Budi Santoso" });

    vi.mocked(partyServices.getPartyDirectory).mockResolvedValue({
      data: [client],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });

    renderWithProviders(<ProjectForm />);

    const searchInput = screen.getByPlaceholderText("projects.searchClientPlaceholder");
    await user.type(searchInput, "Budi");

    const candidateBtn = await screen.findByRole("button", { name: /Budi Santoso/i });
    await user.click(candidateBtn);

    const clearBtn = await screen.findByRole("button", { name: "projects.clearClientAction" });
    await user.click(clearBtn);

    expect(screen.queryByText("projects.clearClientAction")).not.toBeInTheDocument();
    expect(screen.getByPlaceholderText("projects.searchClientPlaceholder")).toBeInTheDocument();
  });

  it("creates project and links primary client on submission", async () => {
    const user = userEvent.setup();
    const client = mockClient({ id: "client-party-99", display_name: "PT Mega Properti" });

    vi.mocked(partyServices.getPartyDirectory).mockResolvedValue({
      data: [client],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });

    const savedProject = mockProject({ id: "new-prj-100", title: "AJB Mega Properti" });
    vi.mocked(projectServices.createProject).mockResolvedValue(savedProject);
    vi.mocked(projectPartyServices.addProjectParty).mockResolvedValue({
      id: "pp-1",
      role_code: "CLIENT",
      is_primary: true,
      notes: null,
      created_at: null,
      party: {
        id: "client-party-99",
        display_name: "PT Mega Properti",
        party_type: "COMPANY",
        is_archived: false,
        can_view_party: true,
      },
      can_manage: true,
    });

    renderWithProviders(<ProjectForm />);

    // Type title
    await user.type(screen.getByLabelText("projects.titleLabel"), "AJB Mega Properti");

    // Search and select client
    const searchInput = screen.getByPlaceholderText("projects.searchClientPlaceholder");
    await user.type(searchInput, "Mega");

    const candidateBtn = await screen.findByRole("button", { name: /PT Mega Properti/i });
    await user.click(candidateBtn);

    // Submit form
    const submitBtn = screen.getByRole("button", { name: "actions.save" });
    await user.click(submitBtn);

    await waitFor(() => {
      expect(projectServices.createProject).toHaveBeenCalledWith(
        expect.objectContaining({
          title: "AJB Mega Properti",
        }),
      );
    });

    await waitFor(() => {
      expect(projectPartyServices.addProjectParty).toHaveBeenCalledWith("new-prj-100", {
        party_id: "client-party-99",
        role_code: "CLIENT",
        is_primary: true,
      });
    });
  });

  it("pre-selects client when initialClientPartyId is provided and party directory loads", async () => {
    const client = mockClient({ id: "init-party-42", display_name: "Ibu Rahmawati" });

    vi.mocked(partyServices.getPartyDirectory).mockResolvedValue({
      data: [client],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });

    renderWithProviders(<ProjectForm initialClientPartyId="init-party-42" />);

    expect(await screen.findByText("Ibu Rahmawati")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "projects.clearClientAction" })).toBeInTheDocument();
  });
});
