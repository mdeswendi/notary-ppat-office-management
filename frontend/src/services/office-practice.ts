import { apiClient } from "@/lib/api/client";
import type {
  AddProfessionalAppointmentInput,
  OfficePractice,
  OfficePracticeOptions,
  ProfessionalAppointment,
  UpdateOfficePracticeInput,
} from "@/types/office-practice";

export const officePracticeKeys = {
  all: ["office-practice"] as const,
  detail: ["office-practice", "detail"] as const,
  options: ["office-practice", "options"] as const,
};

export async function getOfficePractice(): Promise<OfficePractice> {
  const response = await apiClient.get<{ data: OfficePractice }>("/api/v1/office-practice");
  return response.data.data;
}

export async function updateOfficePractice(
  input: UpdateOfficePracticeInput,
): Promise<OfficePractice> {
  const response = await apiClient.patch<{ data: OfficePractice }>(
    "/api/v1/office-practice",
    input,
  );
  return response.data.data;
}

export async function getOfficePracticeOptions(): Promise<OfficePracticeOptions> {
  const response = await apiClient.get<{ data: OfficePracticeOptions }>(
    "/api/v1/office-practice/options",
  );
  return response.data.data;
}

export async function addProfessionalAppointment(
  input: AddProfessionalAppointmentInput,
): Promise<ProfessionalAppointment> {
  const response = await apiClient.post<{ data: ProfessionalAppointment }>(
    "/api/v1/office-practice/professionals",
    input,
  );
  return response.data.data;
}

export async function endProfessionalAppointment(
  appointmentId: string,
  endedAt: string,
): Promise<ProfessionalAppointment> {
  const response = await apiClient.post<{ data: ProfessionalAppointment }>(
    `/api/v1/office-practice/professionals/${appointmentId}/end`,
    { ended_at: endedAt },
  );
  return response.data.data;
}
