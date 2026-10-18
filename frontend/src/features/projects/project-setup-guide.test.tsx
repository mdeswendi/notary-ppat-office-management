import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ProjectSetupGuide } from "@/features/projects/project-setup-guide";
import { renderWithProviders } from "@/test/render";

vi.mock("@/services/project-parties", () => ({
  projectPartyQueryKeys: {
    all: (projectId: string) => ["project-parties", projectId],
  },
  getProjectParties: vi.fn(),
}));

vi.mock("@/services/matters", () => ({
  getMatters: vi.fn(),
}));

vi.mock("@/services/documents", () => ({
  getDocuments: vi.fn(),
}));

const partyServices = await import("@/services/project-parties");
const matterServices = await import("@/services/matters");
const documentServices = await import("@/services/documents");

describe("ProjectSetupGuide", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders with step 1 completed and steps 2-4 pending for a fresh project", async () => {
    vi.mocked(partyServices.getProjectParties).mockResolvedValue({
      data: [],
      meta: { total: 0, can_manage: true },
    });
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });
    vi.mocked(documentServices.getDocuments).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });

    renderWithProviders(<ProjectSetupGuide projectId="PRJ-001" />);

    expect(screen.getByText("projects.setupGuide.title")).toBeInTheDocument();
    expect(screen.getByText("projects.setupGuide.progress")).toBeInTheDocument();

    expect(screen.getByText("projects.setupGuide.step1Title")).toBeInTheDocument();
    expect(screen.getByText("projects.setupGuide.step2Title")).toBeInTheDocument();
    expect(screen.getByText("projects.setupGuide.step3Title")).toBeInTheDocument();
    expect(screen.getByText("projects.setupGuide.step4Title")).toBeInTheDocument();

    // Action buttons for incomplete steps
    expect(
      screen.getByRole("button", { name: "projects.setupGuide.step2Action" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "projects.setupGuide.step3NotaryAction" }),
    ).toHaveAttribute("href", "/notary/matters/new?project_id=PRJ-001");
    expect(
      screen.getByRole("link", { name: "projects.setupGuide.step3PpatAction" }),
    ).toHaveAttribute("href", "/ppat/matters/new?project_id=PRJ-001");
    expect(
      screen.getByRole("button", { name: "projects.setupGuide.step4Action" }),
    ).toBeInTheDocument();
  });

  it("calls onAddPartyClick when Connect Party button is clicked", async () => {
    const user = userEvent.setup();
    const onAddParty = vi.fn();

    vi.mocked(partyServices.getProjectParties).mockResolvedValue({
      data: [],
      meta: { total: 0, can_manage: true },
    });
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });
    vi.mocked(documentServices.getDocuments).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });

    renderWithProviders(<ProjectSetupGuide projectId="PRJ-001" onAddPartyClick={onAddParty} />);

    const button = screen.getByRole("button", { name: "projects.setupGuide.step2Action" });
    await user.click(button);

    expect(onAddParty).toHaveBeenCalledTimes(1);
  });

  it("calls onNavigateTab when step action is clicked", async () => {
    const user = userEvent.setup();
    const onNavigateTab = vi.fn();

    vi.mocked(partyServices.getProjectParties).mockResolvedValue({
      data: [],
      meta: { total: 0, can_manage: true },
    });
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });
    vi.mocked(documentServices.getDocuments).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });

    renderWithProviders(<ProjectSetupGuide projectId="PRJ-001" onNavigateTab={onNavigateTab} />);

    const step4Button = screen.getByRole("button", { name: "projects.setupGuide.step4Action" });
    await user.click(step4Button);

    expect(onNavigateTab).toHaveBeenCalledWith("documents", "project-documents");
  });

  it("reflects 4/4 completed state when parties, matters, and documents exist", async () => {
    vi.mocked(partyServices.getProjectParties).mockResolvedValue({
      data: [
        {
          id: "PARTY-1",
          role_code: "CLIENT",
          is_primary: true,
          notes: null,
          created_at: null,
          can_manage: true,
          party: {
            id: "P1",
            display_name: "PT Maju",
            party_type: "COMPANY",
            is_archived: false,
            can_view_party: true,
          },
        },
      ],
      meta: { total: 1, can_manage: true },
    });
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [
        {
          id: "MAT-1",
          matter_number: "M-001",
          domain: "NOTARY",
          title: "Akta Pendirian",
          status: "OPEN",
          priority: "NORMAL",
          opened_at: null,
          target_completion_date: null,
          completed_at: null,
          notes: null,
          created_at: null,
          updated_at: null,
          office: null,
          pic: null,
          project: null,
          service_type: null,
          can_update: true,
          can_assign: true,
          can_complete: false,
          can_cancel: false,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    });
    vi.mocked(documentServices.getDocuments).mockResolvedValue({
      data: [
        {
          id: "DOC-1",
          document_number: "DOC-001",
          title: "KTP Direktur",
          document_type_code: "KTP",
          status: "RECEIVED",
          is_sensitive: false,
          document_date: null,
          expiry_date: null,
          notes: null,
          office: null,
          created_by: null,
          archived_at: null,
          archived_by: null,
          created_at: null,
          updated_at: null,
          related: { parties: [], projects: [], matters: [] },
          can_update: true,
          can_upload: true,
          can_download: true,
          can_verify: true,
          can_archive: true,
          can_delete: true,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    });

    renderWithProviders(<ProjectSetupGuide projectId="PRJ-001" />);

    await waitFor(() => {
      expect(screen.getByText("projects.setupGuide.allCompletedTitle")).toBeInTheDocument();
    });

    expect(screen.getByText("projects.setupGuide.progress")).toBeInTheDocument();
    expect(screen.getByText("projects.setupGuide.allCompletedDescription")).toBeInTheDocument();
  });

  it("can be collapsed and expanded", async () => {
    const user = userEvent.setup();

    vi.mocked(partyServices.getProjectParties).mockResolvedValue({
      data: [],
      meta: { total: 0, can_manage: true },
    });
    vi.mocked(matterServices.getMatters).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });
    vi.mocked(documentServices.getDocuments).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
    });

    renderWithProviders(<ProjectSetupGuide projectId="PRJ-001" />);

    expect(screen.getByText("projects.setupGuide.step1Title")).toBeInTheDocument();

    const toggleButton = screen.getByRole("button", { name: "projects.setupGuide.collapseAction" });
    await user.click(toggleButton);

    expect(screen.queryByText("projects.setupGuide.step1Title")).not.toBeInTheDocument();

    const expandButton = screen.getByRole("button", { name: "projects.setupGuide.expandAction" });
    await user.click(expandButton);

    expect(screen.getByText("projects.setupGuide.step1Title")).toBeInTheDocument();
  });
});
