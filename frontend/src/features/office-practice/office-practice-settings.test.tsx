import userEvent from "@testing-library/user-event";
import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { OfficePracticeSettings } from "@/features/office-practice/office-practice-settings";
import { renderWithProviders } from "@/test/render";
import type { OfficePractice } from "@/types/office-practice";

vi.mock("@/services/office-practice", () => ({
  officePracticeKeys: {
    all: ["office-practice"],
    detail: ["office-practice", "detail"],
    options: ["office-practice", "options"],
  },
  getOfficePractice: vi.fn(),
  updateOfficePractice: vi.fn(),
  getOfficePracticeOptions: vi.fn(),
  addProfessionalAppointment: vi.fn(),
  endProfessionalAppointment: vi.fn(),
}));

const services = await import("@/services/office-practice");

function office(overrides: Partial<OfficePractice> = {}): OfficePractice {
  return {
    id: "01OFFICE",
    code: "SBW",
    name: "Kantor Mila",
    practice_type: "PPAT",
    jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
    can_update: false,
    professionals: [
      {
        id: "01APPOINTMENT",
        profession_type: "PPAT",
        relationship_type: "INTERNAL",
        registration_number: null,
        appointed_at: null,
        ended_at: null,
        professional_office_name: null,
        office_city: null,
        province: null,
        jurisdiction: "Kabupaten Sambas, Kalimantan Barat",
        is_active: true,
        individual: { id: "01PERSON", display_name: "Mila Widyahastuti", is_archived: false },
      },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(services.getOfficePractice).mockReset();
  vi.mocked(services.getOfficePracticeOptions).mockReset();
  vi.mocked(services.updateOfficePractice).mockReset();
  vi.mocked(services.addProfessionalAppointment).mockReset();
  vi.mocked(services.endProfessionalAppointment).mockReset();
});

describe("OfficePracticeSettings", () => {
  it("shows current identity and history without edit controls to a read-only user", async () => {
    vi.mocked(services.getOfficePractice).mockResolvedValue(office());

    renderWithProviders(<OfficePracticeSettings />);

    expect(await screen.findByText("Mila Widyahastuti")).toBeInTheDocument();
    expect(screen.getAllByText("Kabupaten Sambas, Kalimantan Barat")).toHaveLength(2);
    expect(
      screen.queryByRole("button", { name: "officePractice.addProfessional" }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "actions.save" })).not.toBeInTheDocument();
  });

  it("saves the verified practice identity for an editor", async () => {
    const editable = office({ can_update: true });
    vi.mocked(services.getOfficePractice).mockResolvedValue(editable);
    vi.mocked(services.updateOfficePractice).mockResolvedValue({
      ...editable,
      jurisdiction: "Kabupaten Sambas",
    });
    const user = userEvent.setup();

    renderWithProviders(<OfficePracticeSettings />);

    const jurisdiction = await screen.findByLabelText("officePractice.jurisdiction");
    await user.clear(jurisdiction);
    await user.type(jurisdiction, "Kabupaten Sambas");
    await user.click(screen.getByRole("button", { name: "actions.save" }));

    await waitFor(() =>
      expect(vi.mocked(services.updateOfficePractice).mock.calls[0]?.[0]).toEqual({
        practice_type: "PPAT",
        jurisdiction: "Kabupaten Sambas",
      }),
    );
  });

  it("loads only the server-provided people when adding a professional", async () => {
    vi.mocked(services.getOfficePractice).mockResolvedValue(office({ can_update: true }));
    vi.mocked(services.getOfficePracticeOptions).mockResolvedValue({
      individuals: [{ id: "01COLLABORATOR", display_name: "Notaris Rekan" }],
      profession_types: ["NOTARY", "PPAT"],
      relationship_types: ["INTERNAL", "EXTERNAL"],
    });
    const user = userEvent.setup();

    renderWithProviders(<OfficePracticeSettings />);
    await user.click(await screen.findByRole("button", { name: "officePractice.addProfessional" }));

    expect(await screen.findByRole("option", { name: "Notaris Rekan" })).toHaveValue(
      "01COLLABORATOR",
    );
    expect(screen.queryByText(/email|nik|npwp/i)).not.toBeInTheDocument();
  });
});
