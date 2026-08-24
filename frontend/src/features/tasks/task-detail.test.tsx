import { AxiosError, AxiosHeaders } from "axios";
import { screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { TaskDetail } from "@/features/tasks/task-detail";
import { renderWithProviders } from "@/test/render";
import type { Task } from "@/types/task";

vi.mock("@/services/tasks", () => ({
  taskQueryKeys: {
    all: () => ["tasks"],
    list: (query: unknown) => ["tasks", "list", query],
    detail: (id: string) => ["tasks", "detail", id],
    comments: (id: string) => ["tasks", "detail", id, "comments"],
    options: () => ["tasks", "options"],
  },
  getTask: vi.fn(),
  getTaskOptions: vi.fn(),
  completeTask: vi.fn(),
  reopenTask: vi.fn(),
  cancelTask: vi.fn(),
  deleteTask: vi.fn(),
  assignTask: vi.fn(),
  updateTask: vi.fn(),
  getTaskComments: vi.fn(),
  addTaskComment: vi.fn(),
}));

const services = await import("@/services/tasks");

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

function task(overrides: Partial<Task> = {}): Task {
  return {
    id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    title: "Siapkan draf akta",
    description: null,
    status: "OPEN",
    priority: "NORMAL",
    due_at: "2026-09-01T00:00:00+00:00",
    is_overdue: false,
    completed_at: null,
    completed_by: null,
    office: null,
    project: null,
    matter: null,
    created_by: { id: "01CREATOR", name: "Budi" },
    assigned_to: null,
    assigned_by: null,
    created_at: "2026-08-24T09:00:00+00:00",
    updated_at: null,
    can_update: false,
    can_assign: false,
    can_complete: false,
    can_reopen: false,
    can_cancel: false,
    can_delete: false,
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getTask).mockReset();
  vi.mocked(services.getTaskOptions).mockReset();
  vi.mocked(services.getTaskComments).mockReset();

  // The comment section fetches independently; an empty list keeps it quiet.
  vi.mocked(services.getTaskComments).mockResolvedValue([]);
  vi.mocked(services.getTaskOptions).mockResolvedValue({
    statuses: ["OPEN", "IN_PROGRESS", "WAITING", "COMPLETED", "CANCELLED"],
    settable_statuses: ["OPEN", "IN_PROGRESS", "WAITING"],
    priorities: ["LOW", "NORMAL", "HIGH", "URGENT"],
    assignees: [{ id: "01COLLEAGUE", name: "Siti" }],
  });
});

function renderDetail() {
  return renderWithProviders(<TaskDetail taskId="01ARZ3NDEKTSV4RRFFQ69G5FAV" />);
}

/**
 * These are **presentation tests**. The backend is the security boundary
 * (`CLAUDE.md` §51): a passing assertion here never means an endpoint is
 * authorized. What they pin is that a control the actor may not use is *absent*,
 * which is a real defect class — an offered button that answers 403 or 422.
 *
 * Assertions name **translation keys, not sentences**, because the setup mock
 * makes `t()` return its key. That pins the thing that matters — the component
 * reached for the right message — and leaves the translators free to reword.
 */
describe("TaskDetail", () => {
  it("offers no act the flags refuse", async () => {
    vi.mocked(services.getTask).mockResolvedValue(task());

    renderDetail();

    await screen.findByText("Siapkan draf akta");

    expect(screen.queryByRole("button", { name: "tasks.complete" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.reopen" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.cancel" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.delete" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText("tasks.status")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("tasks.assignedTo")).not.toBeInTheDocument();
  });

  /**
   * **Six capabilities, six controls, and they do not travel together.**
   *
   * `tasks.reopen` in particular is its own code — an office may well let more
   * people close work than un-close it — so a flag set for completing must not
   * light up the reopen button.
   */
  it("offers exactly the acts the flags allow, one capability at a time", async () => {
    vi.mocked(services.getTask).mockResolvedValue(task({ can_complete: true, can_cancel: true }));

    renderDetail();

    expect(await screen.findByRole("button", { name: "tasks.complete" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "tasks.cancel" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.reopen" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.delete" })).not.toBeInTheDocument();
  });

  /**
   * Status eligibility is folded into the flags, so a settled task offers the
   * acts that make sense from where it is and none of the ones that would 422.
   */
  it("offers reopen on settled work and complete on live work, never both", async () => {
    vi.mocked(services.getTask).mockResolvedValue(
      task({
        status: "COMPLETED",
        completed_at: "2026-08-24T10:00:00+00:00",
        can_reopen: true,
        can_complete: false,
      }),
    );

    renderDetail();

    expect(await screen.findByRole("button", { name: "tasks.reopen" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "tasks.complete" })).not.toBeInTheDocument();
  });

  /**
   * **The status control offers three values, not five.**
   *
   * `COMPLETED` and `CANCELLED` answer to their own capabilities and their own
   * endpoints. A dropdown that silently failed for two of its five options would
   * be dishonest, so those two are buttons and never options here.
   */
  it("lets an ordinary edit set only the three settable statuses", async () => {
    vi.mocked(services.getTask).mockResolvedValue(task({ can_update: true }));

    renderDetail();

    const control = await screen.findByLabelText("tasks.status");
    const options = within(control).getAllByRole("option");

    expect(options.map((option) => option.getAttribute("value"))).toEqual([
      "OPEN",
      "IN_PROGRESS",
      "WAITING",
    ]);
  });

  /**
   * Assignees come from the server, and only when the actor may reassign.
   *
   * The list is the actor's own active colleagues — the backend refuses anybody
   * else — so the browser never assembles it and never asks for it on a task it
   * may not reassign.
   */
  it("asks for assignees only when the actor may reassign", async () => {
    vi.mocked(services.getTask).mockResolvedValue(task({ can_assign: false }));

    renderDetail();

    await screen.findByText("Siapkan draf akta");

    expect(services.getTaskOptions).not.toHaveBeenCalled();

    vi.mocked(services.getTask).mockResolvedValue(task({ can_assign: true }));

    renderDetail();

    expect(await screen.findAllByLabelText("tasks.assignedTo")).toHaveLength(1);
    await waitFor(() => {
      expect(services.getTaskOptions).toHaveBeenCalled();
    });
    expect(await screen.findByRole("option", { name: "Siti" })).toBeInTheDocument();
  });

  it("maps a failed load onto a translated message, never server text", async () => {
    vi.mocked(services.getTask).mockRejectedValue(
      axiosError(404, { message: "SQLSTATE[42P01]: undefined_table" }),
    );

    renderDetail();

    await waitFor(() => {
      expect(screen.getByText("tasks.errors.notFound")).toBeInTheDocument();
    });

    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  /**
   * `CLAUDE.md` §49: status must not rely on colour alone. Every badge carries
   * its translated word, and overdue carries a word beside its icon.
   */
  it("shows status, priority and overdue as text, not colour alone", async () => {
    vi.mocked(services.getTask).mockResolvedValue(
      task({ status: "IN_PROGRESS", priority: "URGENT", is_overdue: true }),
    );

    renderDetail();

    expect(await screen.findByText("tasks.statuses.IN_PROGRESS")).toBeInTheDocument();
    expect(screen.getByText("tasks.priorities.URGENT")).toBeInTheDocument();
    expect(screen.getByText("tasks.overdue")).toBeInTheDocument();
  });

  it("renders no overdue marker on work that is on time", async () => {
    // The badge means something because it is absent the rest of the time.
    vi.mocked(services.getTask).mockResolvedValue(task({ is_overdue: false }));

    renderDetail();

    await screen.findByText("Siapkan draf akta");

    expect(screen.queryByText("tasks.overdue")).not.toBeInTheDocument();
  });

  /**
   * A Matter link must point at the surface that owns it.
   *
   * The domain travels on the stub rather than being guessed, because a PPAT
   * Matter linked into `/notary/matters` is a 404 the reader cannot explain.
   */
  it("routes a Matter link by its own domain", async () => {
    vi.mocked(services.getTask).mockResolvedValue(
      task({
        matter: {
          id: "01MATTER",
          matter_number: "P-2026-000001",
          title: "AJB Sertipikat",
          domain: "PPAT",
        },
      }),
    );

    renderDetail();

    const link = await screen.findByRole("link", { name: /P-2026-000001/ });

    expect(link).toHaveAttribute("href", "/ppat/matters/01MATTER");
  });

  /**
   * **Anybody who may read the task may comment.** The form has no capability
   * gate: commenting answers to `tasks.view`, the same code that got the reader
   * onto this page — and a settled task still takes remarks, because explaining
   * why something closed is the comment most worth having.
   */
  it("offers the comment form even to an actor who may change nothing", async () => {
    vi.mocked(services.getTask).mockResolvedValue(
      task({ status: "CANCELLED", can_update: false, can_complete: false }),
    );

    renderDetail();

    expect(await screen.findByLabelText("tasks.addComment")).toBeInTheDocument();
  });
});
