import { apiClient } from "@/lib/api/client";
import type {
  IndividualManagementCompany,
  IndividualOwnershipCompany,
} from "@/types/individual-company";

/**
 * Query keys for the two reverse relationship surfaces.
 *
 * **Deliberately separate trees**, exactly as they are on the Company side:
 * `companies.management.view` and `companies.shareholders.view` are independent
 * capabilities, so a component that may read one must not cause the other to be
 * fetched — and a shared key would make invalidating one refetch the other,
 * sending a request the caller may not be authorized for (D-083).
 *
 * Nested under the Individual so the person's own invalidation reaches them.
 */
export const individualCompanyKeys = {
  management: (individualId: string) =>
    ["individuals", individualId, "companies", "management"] as const,
  shareholders: (individualId: string) =>
    ["individuals", individualId, "companies", "shareholders"] as const,
};

/**
 * The management roles this person holds or has held.
 *
 * Read-only. There is no add, end, edit, or delete counterpart in this file and
 * there must never be: relationship mutation lives on the Company, and a second
 * write path for `company_people` would be two ways to change one history
 * (D-085). No such route exists under `individuals` to call.
 */
export async function getIndividualManagementCompanies(
  individualId: string,
): Promise<IndividualManagementCompany[]> {
  const response = await apiClient.get<{ data: IndividualManagementCompany[] }>(
    `/api/v1/individuals/${individualId}/companies/management`,
  );

  return response.data.data;
}

/**
 * The ownership interests this person holds or has held. Read-only, as above.
 */
export async function getIndividualOwnershipCompanies(
  individualId: string,
): Promise<IndividualOwnershipCompany[]> {
  const response = await apiClient.get<{ data: IndividualOwnershipCompany[] }>(
    `/api/v1/individuals/${individualId}/companies/shareholders`,
  );

  return response.data.data;
}
