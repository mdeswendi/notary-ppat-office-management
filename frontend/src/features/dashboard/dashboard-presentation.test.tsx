import { render, screen, waitFor, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardHero } from "@/features/dashboard/dashboard-hero";
import { LatestPpatMattersWidget } from "@/features/dashboard/latest-ppat-matters-widget";
import { ProfessionalCollaboratorsWidget } from "@/features/dashboard/professional-collaborators-widget";
import { SchedulePlaceholderWidget } from "@/features/dashboard/schedule-placeholder-widget";
import { renderWithProviders } from "@/test/render";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/features/auth/use-current-user", () => ({
  useCurrentUser: vi.fn(),
}));

vi.mock("@/services/office-practice", () => ({
  officePracticeKeys: { detail: ["office-practice", "detail"] },
  getOfficePractice: vi.fn(),
}));

vi.mock("@/services/dashboard", () => ({
  dashboardQueryKeys: { latestPpatMatters: () => ["dashboard", "latest-ppat-matters"] },
  getDashboardLatestPpatMatters: vi.fn(),
}));

const auth = await import("@/features/auth/use-current-user");
const officePractice = await import("@/services/office-practice");
const dashboardService = await import("@/services/dashboard");

const user: CurrentUser = {
  id: "01USER00000000000000000000",
  name: "Mila Widyahastuti",
  email: "mila@example.test",
  preferred_locale: "id",
  office: null,
  roles: [],
  permissions: ["offices.view"],
  permission_scopes: { "offices.view": ["OFFICE"] },
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(auth.useCurrentUser).mockReturnValue({
    data: user,
    isPending: false,
  } as unknown as ReturnType<typeof auth.useCurrentUser>);
});

describe("DashboardHero", () => {
  it("keeps the illustration decorative and the welcome text accessible", () => {
    const { container } = render(<DashboardHero currentDate="2026-09-14T00:00:00.000Z" />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("dashboard.welcome");
    expect(screen.getByText(/dashboard\.practiceMotto/)).toBeInTheDocument();
    expect(screen.getByText("dashboard.landMotto")).toBeInTheDocument();
    expect(container.querySelector("time")).toHaveAttribute("datetime", "2026-09-14T00:00:00.000Z");
    expect(container.querySelector("img")).toHaveAttribute("alt", "");
  });

  it("names the active PPAT practice below the welcome heading", () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: {
        ...user,
        office: {
          id: "01OFFICE0000000000000000000",
          code: "SBG-01",
          name: "Kantor Pusat - Subang",
          practice_type: "PPAT",
          jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
          organization: {
            id: "01ORG000000000000000000000",
            name: "Kantor Notaris & PPAT Mila Widyahastuti, S.H., M.Kn.",
          },
        },
      },
      isPending: false,
    } as unknown as ReturnType<typeof auth.useCurrentUser>);

    render(<DashboardHero currentDate="2026-09-14T00:00:00.000Z" />);

    expect(screen.getByText("Kantor PPAT Mila Widyahastuti, S.H., M.Kn.")).toBeInTheDocument();
    expect(screen.queryByText(/Kantor Notaris & PPAT/)).not.toBeInTheDocument();
  });
});

describe("ProfessionalCollaboratorsWidget", () => {
  it("does not probe office practice when the actor cannot view Offices", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: { ...user, permissions: [], permission_scopes: {} },
      isPending: false,
    } as unknown as ReturnType<typeof auth.useCurrentUser>);

    const { container } = renderWithProviders(<ProfessionalCollaboratorsWidget />);

    await waitFor(() => expect(container.textContent).toBe(""));
    expect(officePractice.getOfficePractice).not.toHaveBeenCalled();
  });

  it("shows only active professionals and names their relationship", async () => {
    vi.mocked(officePractice.getOfficePractice).mockResolvedValue({
      id: "01OFFICE0000000000000000000",
      code: "SBG-01",
      name: "Kantor Pusat - Subang",
      practice_type: "PPAT",
      jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
      can_update: true,
      professionals: [
        {
          id: "01ACTIVE000000000000000000",
          profession_type: "PPAT",
          relationship_type: "INTERNAL",
          registration_number: null,
          appointed_at: null,
          ended_at: null,
          professional_office_name: "Kantor PPAT Mila Widyahastuti",
          office_city: "Subang",
          province: "Jawa Barat",
          jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
          is_active: true,
          individual: {
            id: "01PERSON000000000000000000",
            display_name: "Mila",
            is_archived: false,
          },
        },
        {
          id: "01EXTERNALNOTARY00000000000",
          profession_type: "NOTARY",
          relationship_type: "EXTERNAL",
          registration_number: "AHU-001",
          appointed_at: "2017-06-16",
          ended_at: null,
          professional_office_name: "Kantor Notaris & PPAT Siska",
          office_city: "Kabupaten Subang",
          province: "Jawa Barat",
          jurisdiction: "Provinsi Jawa Barat",
          is_active: true,
          individual: {
            id: "01PERSON300000000000000000",
            display_name: "Siska Berlianti",
            is_archived: false,
          },
        },
        {
          id: "01EXTERNALPPAT000000000000",
          profession_type: "PPAT",
          relationship_type: "EXTERNAL",
          registration_number: "417/KEP",
          appointed_at: "2017-11-02",
          ended_at: null,
          professional_office_name: "Kantor Notaris & PPAT Siska",
          office_city: "Kabupaten Subang",
          province: "Jawa Barat",
          jurisdiction: "Kabupaten Subang",
          is_active: true,
          individual: {
            id: "01PERSON300000000000000000",
            display_name: "Siska Berlianti",
            is_archived: false,
          },
        },
        {
          id: "01ENDED0000000000000000000",
          profession_type: "NOTARY",
          relationship_type: "EXTERNAL",
          registration_number: null,
          appointed_at: null,
          ended_at: "2026-09-01",
          professional_office_name: null,
          office_city: null,
          province: null,
          jurisdiction: null,
          is_active: false,
          individual: {
            id: "01PERSON200000000000000000",
            display_name: "Riwayat Berakhir",
            is_archived: false,
          },
        },
      ],
    });

    renderWithProviders(<ProfessionalCollaboratorsWidget />);

    expect(await screen.findByText("Mila")).toBeInTheDocument();
    expect(screen.getByText("Siska Berlianti")).toBeInTheDocument();
    expect(screen.getAllByText("Siska Berlianti")).toHaveLength(1);
    expect(screen.queryByText("Riwayat Berakhir")).not.toBeInTheDocument();

    const internal = screen.getByRole("region", {
      name: "dashboard.internalProfessionalsGroup",
    });
    const external = screen.getByRole("region", {
      name: "dashboard.externalProfessionalsGroup",
    });

    expect(internal).toHaveClass("bg-gradient-to-br");
    expect(external).toHaveClass("bg-gradient-to-br");
    expect(within(internal).getByText("Mila")).toBeInTheDocument();
    expect(within(external).getByText("Siska Berlianti")).toBeInTheDocument();
    expect(screen.getByText("dashboard.collaborationMotto")).toBeInTheDocument();
  });
});

describe("LatestPpatMattersWidget", () => {
  it("renders nothing when the scoped Dashboard endpoint returns null", async () => {
    vi.mocked(dashboardService.getDashboardLatestPpatMatters).mockResolvedValue(null);

    const { container } = renderWithProviders(<LatestPpatMattersWidget />);

    await waitFor(() => expect(container.textContent).toBe(""));
  });

  it("places the complete recent PPAT matter summary in the main dashboard table", async () => {
    vi.mocked(dashboardService.getDashboardLatestPpatMatters).mockResolvedValue([
      {
        id: "01MATTER000000000000000000",
        matter_number: "P-2026-000001",
        title: "Peralihan Hak",
        status: "OPEN",
        opened_at: "2026-09-14",
        target_completion_date: "2026-10-14",
        primary_parties: ["Saman", "Ria Oktaviani"],
      },
    ]);

    renderWithProviders(<LatestPpatMattersWidget />);

    expect(await screen.findByText("Peralihan Hak")).toBeInTheDocument();
    expect(screen.getByText("P-2026-000001")).toBeInTheDocument();
    expect(screen.getByText("Saman, Ria Oktaviani")).toBeInTheDocument();
    expect(screen.getByText("dashboard.matterParties")).toBeInTheDocument();
    expect(screen.getByLabelText("matters.statusLabel: matters.statuses.OPEN")).toHaveClass(
      "bg-info/10",
      "min-w-16",
    );
    expect(screen.getByRole("table", { name: "dashboard.latestMatters" })).toHaveClass(
      "text-[13px]",
    );
    expect(screen.getByRole("link", { name: "dashboard.openMatter" })).toHaveAttribute(
      "href",
      "/ppat/matters/01MATTER000000000000000000",
    );
  });
});

describe("SchedulePlaceholderWidget", () => {
  it("reserves the schedule position only for an actor who may read tasks", () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: {
        ...user,
        permissions: ["tasks.view"],
        permission_scopes: { "tasks.view": ["OWN"] },
      },
      isPending: false,
    } as unknown as ReturnType<typeof auth.useCurrentUser>);

    render(<SchedulePlaceholderWidget />);

    expect(screen.getByText("dashboard.scheduleToday")).toBeInTheDocument();
    expect(screen.getByText("dashboard.notAvailable")).toBeInTheDocument();
  });

  it("reveals no schedule panel without task visibility", () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: { ...user, permissions: [], permission_scopes: {} },
      isPending: false,
    } as unknown as ReturnType<typeof auth.useCurrentUser>);

    const { container } = render(<SchedulePlaceholderWidget />);

    expect(container.textContent).toBe("");
  });
});
