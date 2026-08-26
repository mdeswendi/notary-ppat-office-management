import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ActivityWidget } from "@/features/dashboard/activity-widget";
import { StatsCards } from "@/features/dashboard/stats-cards";
import { WorkloadWidget } from "@/features/dashboard/workload-widget";
import { renderWithProviders } from "@/test/render";
import type { DashboardStats } from "@/types/dashboard";

vi.mock("@/services/dashboard", () => ({
  dashboardQueryKeys: {
    all: () => ["dashboard"],
    stats: () => ["dashboard", "stats"],
    tasks: () => ["dashboard", "tasks"],
    needsAttention: () => ["dashboard", "needs-attention"],
    workload: () => ["dashboard", "workload"],
    activity: (limit: number) => ["dashboard", "activity", limit],
    deeds: () => ["dashboard", "deeds"],
  },
  getDashboardStats: vi.fn(),
  getDashboardTasks: vi.fn(),
  getDashboardNeedsAttention: vi.fn(),
  getDashboardWorkload: vi.fn(),
  getDashboardActivity: vi.fn(),
  getDashboardDeeds: vi.fn(),
}));

const services = await import("@/services/dashboard");

const NOTHING_PERMITTED: DashboardStats = {
  active_projects: null,
  active_matters: null,
  pending_reviews: null,
  overdue_tasks: null,
  total_deeds_this_month: null,
};

beforeEach(() => {
  vi.clearAllMocks();
});

/**
 * The distinction this whole surface turns on (M8.1, D-122): `null` is "you may
 * not see this", `0` is "you may, and it is empty". A type cannot express the
 * difference in behaviour, so it is tested.
 */
describe("StatsCards", () => {
  it("renders nothing when the caller may see no figure at all", async () => {
    vi.mocked(services.getDashboardStats).mockResolvedValue(NOTHING_PERMITTED);

    const { container } = renderWithProviders(<StatsCards />);

    // Not an empty card, not a "no permission" message — nothing. A panel that is
    // permanently blank for a whole role is dead UI, and announcing the absence
    // tells somebody what they are missing without letting them act on it.
    await waitFor(() => expect(container.querySelector("[aria-busy]")).toBeNull());

    expect(container.textContent).toBe("");
  });

  it("renders a zero, because permitted-and-empty is a real answer", async () => {
    vi.mocked(services.getDashboardStats).mockResolvedValue({
      ...NOTHING_PERMITTED,
      active_projects: 0,
    });

    renderWithProviders(<StatsCards />);

    expect(await screen.findByText("0")).toBeInTheDocument();
  });

  it("omits only the cards the caller may not see", async () => {
    vi.mocked(services.getDashboardStats).mockResolvedValue({
      ...NOTHING_PERMITTED,
      active_projects: 12,
      overdue_tasks: 3,
    });

    renderWithProviders(<StatsCards />);

    expect(await screen.findByText("12")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();

    // Three of the five figures were null; only two cards exist.
    expect(screen.getAllByText(/^\d+$/)).toHaveLength(2);
  });

  it("shows a generic message rather than raw server text on failure", async () => {
    vi.mocked(services.getDashboardStats).mockRejectedValue(
      new Error("SQLSTATE[42S02]: Base table not found"),
    );

    renderWithProviders(<StatsCards />);

    // A bilingual, generic message — no raw server text ever reaches a user
    // (CLAUDE.md §48).
    expect(await screen.findByText("dashboard.panelUnavailable")).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });
});

describe("WorkloadWidget", () => {
  it("renders nothing when the caller may not read users", async () => {
    vi.mocked(services.getDashboardWorkload).mockResolvedValue(null);

    const { container } = renderWithProviders(<WorkloadWidget />);

    await waitFor(() => expect(container.querySelector("[aria-busy]")).toBeNull());

    expect(container.textContent).toBe("");
  });

  it("renders the panel with an empty state when nobody holds live work", async () => {
    vi.mocked(services.getDashboardWorkload).mockResolvedValue([]);

    renderWithProviders(<WorkloadWidget />);

    // The panel exists this time: `[]` means permitted and empty.
    expect(await screen.findByRole("heading", { level: 2 })).toBeInTheDocument();
  });

  it("lists whoever holds work, with the counts as text beside the bar", async () => {
    vi.mocked(services.getDashboardWorkload).mockResolvedValue([
      { user_id: "u1", user_name: "Rina", matter_count: 12, task_count: 8 },
    ]);

    renderWithProviders(<WorkloadWidget />);

    expect(await screen.findByText("Rina")).toBeInTheDocument();

    // The counts reach the message layer as text beside the bar, so a reader who
    // cannot see the bar loses nothing (CLAUDE.md §49). The setup mock returns
    // the key rather than the rendered sentence, so the key is what is asserted —
    // pinning that the component asked for the right message, and leaving the
    // wording to translators.
    expect(screen.getByText("dashboard.workloadCounts")).toBeInTheDocument();
  });
});

/**
 * One branch here is deliberately not covered: `ActivityWidget` falls back to the
 * raw event code when the messages do not know a type, and `vitest.setup.tsx`
 * hardcodes `t.has = () => true`. A test for it would exercise the mock rather
 * than the component, so it is left out instead of written misleadingly.
 */
describe("ActivityWidget", () => {
  it("says the record starts here rather than looking broken when empty", async () => {
    vi.mocked(services.getDashboardActivity).mockResolvedValue([]);

    renderWithProviders(<ActivityWidget />);

    // Nothing is backfilled (D-123), so an empty feed is expected on day one and
    // the copy has to say so — both locales carry that sentence under this key.
    expect(await screen.findByText("dashboard.noActivity")).toBeInTheDocument();
  });

  it("renders a row from its translation key, not from server prose", async () => {
    vi.mocked(services.getDashboardActivity).mockResolvedValue([
      {
        id: "a1",
        activity_type: "DEED_APPROVED",
        description_key: "activity.types.DEED_APPROVED",
        metadata: { title: "AJB Budi" },
        subject_type: "NotaryDeed",
        subject_id: "d1",
        project_id: null,
        matter_id: null,
        actor: { id: "u1", name: "Rina" },
        created_at: "2026-08-26T10:00:00+00:00",
      },
    ]);

    renderWithProviders(<ActivityWidget />);

    expect(await screen.findByText("Rina")).toBeInTheDocument();

    // The row is built from the event's key, never from prose the server chose:
    // picking the language is the client's job in a bilingual product (§6).
    expect(screen.getByText("activity.types.DEED_APPROVED")).toBeInTheDocument();
  });

  it("names the system when an event had no actor", async () => {
    vi.mocked(services.getDashboardActivity).mockResolvedValue([
      {
        id: "a2",
        activity_type: "DEED_FINALIZED",
        description_key: "activity.types.DEED_FINALIZED",
        metadata: {},
        subject_type: "NotaryDeed",
        subject_id: "d2",
        project_id: null,
        matter_id: null,
        actor: null,
        created_at: "2026-08-26T10:00:00+00:00",
      },
    ]);

    renderWithProviders(<ActivityWidget />);

    // A row survives its actor being absent — the point of a timeline.
    expect(await screen.findByText("dashboard.systemActor")).toBeInTheDocument();
  });

  it("drops a null metadata value rather than throwing on it", async () => {
    vi.mocked(services.getDashboardActivity).mockResolvedValue([
      {
        id: "a3",
        activity_type: "PROPERTY_CREATED",
        description_key: "activity.types.PROPERTY_CREATED",
        metadata: { reference: null },
        subject_type: "Property",
        subject_id: "p1",
        project_id: null,
        matter_id: null,
        actor: { id: "u1", name: "Rina" },
        created_at: "2026-08-26T10:00:00+00:00",
      },
    ]);

    renderWithProviders(<ActivityWidget />);

    // The row renders; the absent value leaves its placeholder rather than
    // taking the whole panel down.
    expect(await screen.findByText("Rina")).toBeInTheDocument();
  });
});
