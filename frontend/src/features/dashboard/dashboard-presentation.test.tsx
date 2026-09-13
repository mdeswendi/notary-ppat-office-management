import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { DashboardHero } from "@/features/dashboard/dashboard-hero";
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

const auth = await import("@/features/auth/use-current-user");
const officePractice = await import("@/services/office-practice");

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
    const { container } = render(<DashboardHero />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("dashboard.welcome");
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

    render(<DashboardHero />);

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
    expect(screen.getByText(/dashboard\.relationshipTypes\.INTERNAL/)).toBeInTheDocument();
    expect(screen.queryByText("Riwayat Berakhir")).not.toBeInTheDocument();
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
