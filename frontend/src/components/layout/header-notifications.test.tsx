import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { HeaderNotifications } from "@/components/layout/header-notifications";
import { renderWithProviders } from "@/test/render";

vi.mock("@/services/dashboard", () => ({
  dashboardQueryKeys: { tasks: () => ["dashboard", "tasks"] },
  getDashboardTasks: vi.fn(),
}));

const dashboard = await import("@/services/dashboard");

beforeEach(() => {
  vi.clearAllMocks();
});

describe("HeaderNotifications", () => {
  it("shows the real actionable-task count and links to the user's task queue", async () => {
    vi.mocked(dashboard.getDashboardTasks).mockResolvedValue({
      today: [
        {
          id: "today-1",
          title: "Tanda tangan hari ini",
          status: "OPEN",
          priority: "NORMAL",
          due_at: null,
          is_overdue: false,
        },
      ],
      overdue: [
        {
          id: "late-1",
          title: "Terlambat",
          status: "IN_PROGRESS",
          priority: "HIGH",
          due_at: null,
          is_overdue: true,
        },
      ],
      upcoming: [],
    });

    const { container } = renderWithProviders(<HeaderNotifications enabled />);

    const link = await screen.findByRole(
      "link",
      {
        name: "dashboard.notifications.withCount",
      },
      { timeout: 3_000 },
    );
    expect(link).toHaveAttribute("href", "/tasks/my");
    expect(container).toHaveTextContent("2");
  });

  it("renders nothing when task access is unavailable", () => {
    const { container } = renderWithProviders(<HeaderNotifications enabled={false} />);

    expect(container).toBeEmptyDOMElement();
    expect(dashboard.getDashboardTasks).not.toHaveBeenCalled();
  });
});
