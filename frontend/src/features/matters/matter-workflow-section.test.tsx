import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, vi } from "vitest";

import { MatterWorkflowSection } from "@/features/matters/matter-workflow-section";
import { renderWithProviders } from "@/test/render";
import {
  MATTER_STAGE_STATUSES,
  type MatterStage,
  type MatterWorkflowPage,
} from "@/types/matter-stage";

vi.mock("@/services/matter-stages", () => ({
  matterStageQueryKeys: {
    all: (domain: string, matterId: string) => ["matters", domain, "detail", matterId, "stages"],
    options: (domain: string, matterId: string) => [
      "matters",
      domain,
      "detail",
      matterId,
      "stage-options",
    ],
  },
  getMatterWorkflow: vi.fn(),
  getMatterStageOptions: vi.fn(),
  moveMatterStage: vi.fn(),
}));

const services = await import("@/services/matter-stages");

function stage(overrides: Partial<MatterStage> = {}): MatterStage {
  return {
    id: `stage-${overrides.sequence_no ?? 1}`,
    stage_code: `TAHAP_${overrides.sequence_no ?? 1}`,
    stage_name_id: `Tahap ${overrides.sequence_no ?? 1}`,
    stage_name_en: `Stage ${overrides.sequence_no ?? 1}`,
    sequence_no: 1,
    status: "PENDING",
    started_at: null,
    completed_at: null,
    assignee: null,
    approved_at: null,
    ...overrides,
  };
}

function run(
  stages: MatterStage[],
  canChangeStage = true,
  completedAt: string | null = null,
): MatterWorkflowPage {
  return {
    data: {
      workflow: {
        id: "01WORKFLOW",
        workflow_version: 3,
        started_at: "2026-08-21T00:00:00+00:00",
        completed_at: completedAt,
      },
      current_stage: stages.find((s) => s.status === "ACTIVE") ?? null,
      stages,
      history: [
        {
          id: "h1",
          from_stage_code: null,
          to_stage_code: "TAHAP_1",
          reason: null,
          changed_at: "2026-08-21T00:00:00+00:00",
          changed_by: { id: "u1", name: "Budi" },
        },
      ],
    },
    meta: { has_workflow: true, can_change_stage: canChangeStage },
  };
}

/**
 * A genuine `AxiosError`, because the error mapper narrows with `instanceof`
 * and a shaped plain object would silently fall through to the generic message.
 */
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

const threeStages = [
  stage({ sequence_no: 1, status: "COMPLETED" }),
  stage({ sequence_no: 2, status: "ACTIVE" }),
  stage({ sequence_no: 3, status: "PENDING" }),
];

beforeEach(() => {
  vi.mocked(services.getMatterWorkflow).mockReset();
  vi.mocked(services.getMatterStageOptions).mockReset();
  vi.mocked(services.moveMatterStage).mockReset();
});

function renderSection(domain: "NOTARY" | "PPAT" = "NOTARY") {
  return renderWithProviders(<MatterWorkflowSection domain={domain} matterId="01MATTER" />);
}

/**
 * The M4.7 workflow stepper (O-032).
 *
 * The branches that matter here are the ones D-112 and D-104 decided and that no
 * type can express: that a Matter with no configured workflow reads as a
 * configuration fact rather than an error, that every status can be drawn
 * including the two nothing sets, and that the move control is gated on the
 * backend's flag.
 */
describe("MatterWorkflowSection", () => {
  it("states plainly that no workflow is configured, without looking broken", async () => {
    // The **ordinary** case on a fresh deployment: D-104 seeds no templates, so
    // until an office configures one this is every Matter. An error state here
    // would report a working product as faulty.
    vi.mocked(services.getMatterWorkflow).mockResolvedValue({
      data: { workflow: null, current_stage: null, stages: [], history: [] },
      meta: { has_workflow: false, can_change_stage: true },
    });

    renderSection();

    expect(await screen.findByText("matterStages.noWorkflow")).toBeInTheDocument();
    expect(screen.queryByText("matterStages.errorTitle")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "matterStages.moveAction" }),
    ).not.toBeInTheDocument();
  });

  it("renders each stage with its snapshot name and a translated status", async () => {
    // Names come from the instance's own copied columns, so a template renamed
    // since must not change what a running Matter shows (CLAUDE.md section 18).
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));

    renderSection();

    expect(await screen.findByText("1. Tahap 1")).toBeInTheDocument();
    expect(screen.getByText("2. Tahap 2")).toBeInTheDocument();
    expect(screen.getByText("matterStages.statuses.ACTIVE")).toBeInTheDocument();
    expect(screen.getByText("matterStages.statuses.COMPLETED")).toBeInTheDocument();
  });

  it("draws every canonical status, including the two nothing can set", async () => {
    // `SKIPPED` and `BLOCKED` are unreachable in M4 (D-112). Rendering them
    // anyway means the stepper never meets a status it cannot express, and the
    // backend is entitled to return them one day.
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(
      run(
        MATTER_STAGE_STATUSES.map((status, index) =>
          stage({ sequence_no: index + 1, status, id: `s-${status}` }),
        ),
      ),
    );

    renderSection();

    for (const status of MATTER_STAGE_STATUSES) {
      expect(await screen.findByText(`matterStages.statuses.${status}`)).toBeInTheDocument();
    }
  });

  it("gives each step an icon with an accessible name, not colour alone", async () => {
    // CLAUDE.md section 49: status must not rely on colour. The icon carries an
    // aria-label and the text repeats it.
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));

    renderSection();

    const list = await screen.findByRole("list", { name: "" }).catch(() => null);
    void list;

    expect(await screen.findAllByLabelText("matterStages.statuses.COMPLETED")).not.toHaveLength(0);
    expect(screen.getAllByLabelText("matterStages.statuses.ACTIVE")).not.toHaveLength(0);
  });

  it("reports the template version the run was started from", async () => {
    // The half of the snapshot that lives on the run rather than the stages
    // (D-111): which iteration of the template this Matter is on.
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));

    renderSection();

    expect(await screen.findByText("matterStages.versionLabel")).toBeInTheDocument();
  });

  it("hides the move control from a reader who may not change the stage", async () => {
    // Reading answers to `*.matters.view` and moving to `*.matters.change_stage`;
    // the two are separate, so a reader must not be offered a control that 403s.
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages, false));

    renderSection();

    await screen.findByText("1. Tahap 1");

    expect(
      screen.queryByRole("button", { name: "matterStages.moveAction" }),
    ).not.toBeInTheDocument();
  });

  it("hides the move control once the workflow is complete", async () => {
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(
      run(threeStages, true, "2026-08-21T10:00:00+00:00"),
    );

    renderSection();

    expect(await screen.findByText("matterStages.workflowCompleted")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "matterStages.moveAction" }),
    ).not.toBeInTheDocument();
  });

  it("offers every open stage from the backend rather than only the next one", async () => {
    // M4 has no transition matrix (D-104). Offering only "next" would be that
    // matrix invented by an interface, so the dialog shows what the backend
    // returned — backward moves included.
    const user = userEvent.setup();

    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));
    vi.mocked(services.getMatterStageOptions).mockResolvedValue({
      stages: [
        {
          stage_code: "TAHAP_1",
          stage_name_id: "Tahap 1",
          stage_name_en: "Stage 1",
          sequence_no: 1,
          status: "PENDING",
        },
        {
          stage_code: "TAHAP_3",
          stage_name_id: "Tahap 3",
          stage_name_en: "Stage 3",
          sequence_no: 3,
          status: "PENDING",
        },
      ],
    });

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterStages.moveAction" }));

    const select = await screen.findByLabelText("matterStages.targetLabel");
    const options = within(select).getAllByRole("option");

    // The placeholder plus both open stages, one of which is *behind* the
    // current one.
    expect(options).toHaveLength(3);
    expect(options[1]).toHaveTextContent("1. Tahap 1");
    expect(options[2]).toHaveTextContent("3. Tahap 3");
  });

  it("keeps the submit disabled until a target is chosen", async () => {
    const user = userEvent.setup();

    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));
    vi.mocked(services.getMatterStageOptions).mockResolvedValue({ stages: [] });

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterStages.moveAction" }));

    expect(await screen.findByRole("button", { name: "matterStages.moveConfirm" })).toBeDisabled();
  });

  it("sends the chosen stage and an optional reason", async () => {
    const user = userEvent.setup();

    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));
    vi.mocked(services.getMatterStageOptions).mockResolvedValue({
      stages: [
        {
          stage_code: "TAHAP_3",
          stage_name_id: "Tahap 3",
          stage_name_en: "Stage 3",
          sequence_no: 3,
          status: "PENDING",
        },
      ],
    });
    vi.mocked(services.moveMatterStage).mockResolvedValue(
      stage({ sequence_no: 3, status: "ACTIVE" }),
    );

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterStages.moveAction" }));

    await user.selectOptions(await screen.findByLabelText("matterStages.targetLabel"), "TAHAP_3");
    await user.type(screen.getByLabelText("matterStages.reasonLabel"), "Berkas lengkap");
    await user.click(screen.getByRole("button", { name: "matterStages.moveConfirm" }));

    await waitFor(() => {
      expect(services.moveMatterStage).toHaveBeenCalledWith("NOTARY", "01MATTER", {
        target_stage_code: "TAHAP_3",
        reason: "Berkas lengkap",
      });
    });
  });

  it("sends null rather than an empty string when no reason is given", async () => {
    // `reason` is nullable on the backend; an empty string would persist a blank
    // note where the column means "none".
    const user = userEvent.setup();

    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));
    vi.mocked(services.getMatterStageOptions).mockResolvedValue({
      stages: [
        {
          stage_code: "TAHAP_3",
          stage_name_id: "Tahap 3",
          stage_name_en: "Stage 3",
          sequence_no: 3,
          status: "PENDING",
        },
      ],
    });
    vi.mocked(services.moveMatterStage).mockResolvedValue(
      stage({ sequence_no: 3, status: "ACTIVE" }),
    );

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterStages.moveAction" }));
    await user.selectOptions(await screen.findByLabelText("matterStages.targetLabel"), "TAHAP_3");
    await user.click(screen.getByRole("button", { name: "matterStages.moveConfirm" }));

    await waitFor(() => {
      expect(services.moveMatterStage).toHaveBeenCalledWith("NOTARY", "01MATTER", {
        target_stage_code: "TAHAP_3",
        reason: null,
      });
    });
  });

  it("shows a translated message when a move is refused, not the server text", async () => {
    const user = userEvent.setup();

    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));
    vi.mocked(services.getMatterStageOptions).mockResolvedValue({
      stages: [
        {
          stage_code: "TAHAP_3",
          stage_name_id: "Tahap 3",
          stage_name_en: "Stage 3",
          sequence_no: 3,
          status: "PENDING",
        },
      ],
    });
    vi.mocked(services.moveMatterStage).mockRejectedValue(
      axiosError(422, { message: "Stage [X] is not open to move to." }),
    );

    renderSection();

    await user.click(await screen.findByRole("button", { name: "matterStages.moveAction" }));
    await user.selectOptions(await screen.findByLabelText("matterStages.targetLabel"), "TAHAP_3");
    await user.click(screen.getByRole("button", { name: "matterStages.moveConfirm" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("matterStages.errors.validation");
  });

  it("reads the domain it was given rather than inferring one", async () => {
    vi.mocked(services.getMatterWorkflow).mockResolvedValue(run(threeStages));

    renderSection("PPAT");

    await waitFor(() => {
      expect(services.getMatterWorkflow).toHaveBeenCalledWith("PPAT", "01MATTER");
    });
  });
});
