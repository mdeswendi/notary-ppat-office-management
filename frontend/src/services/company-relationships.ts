import { apiClient } from "@/lib/api/client";
import type {
  AddManagementInput,
  AddShareholderInput,
  CompanyManagementRelationship,
  CompanyRelationshipOptions,
  CompanyShareholderRelationship,
  EndRelationshipInput,
  ManagementRelationshipType,
  OwnershipRelationshipType,
} from "@/types/company-relationship";

/**
 * Query keys for the two relationship surfaces.
 *
 * **Deliberately separate trees.** `companies.management.*` and
 * `companies.shareholders.*` are independent capabilities, so a component that
 * may read one must not cause the other to be fetched — and a shared key would
 * make invalidating one refetch the other, sending a request the caller may not
 * be authorized for (D-083).
 */
export const companyManagementKeys = {
  all: (companyId: string) => ["companies", companyId, "management"] as const,
  list: (companyId: string) => ["companies", companyId, "management", "list"] as const,
  options: (companyId: string) => ["companies", companyId, "management", "options"] as const,
};

export const companyShareholderKeys = {
  all: (companyId: string) => ["companies", companyId, "shareholders"] as const,
  list: (companyId: string) => ["companies", companyId, "shareholders", "list"] as const,
  options: (companyId: string) => ["companies", companyId, "shareholders", "options"] as const,
};

/*
 * Management — who acts for the organization.
 */

export async function getCompanyManagement(
  companyId: string,
): Promise<CompanyManagementRelationship[]> {
  const response = await apiClient.get<{ data: CompanyManagementRelationship[] }>(
    `/api/v1/companies/${companyId}/management`,
  );

  return response.data.data;
}

export async function getCompanyManagementOptions(
  companyId: string,
  search?: string,
): Promise<CompanyRelationshipOptions<ManagementRelationshipType>> {
  const response = await apiClient.get<{
    data: CompanyRelationshipOptions<ManagementRelationshipType>;
  }>(`/api/v1/companies/${companyId}/management/options`, {
    params: { search: search === "" ? undefined : search },
  });

  return response.data.data;
}

export async function addCompanyManagement(
  companyId: string,
  input: AddManagementInput,
): Promise<CompanyManagementRelationship> {
  const response = await apiClient.post<{ data: CompanyManagementRelationship }>(
    `/api/v1/companies/${companyId}/management`,
    input,
  );

  return response.data.data;
}

/**
 * Close a management relationship.
 *
 * Not a deletion: the row stays and keeps its Company, person, and type. There
 * is no delete endpoint to call instead — `company_people` is history, and "who
 * was the director in March" must stay answerable (D-083).
 */
export async function endCompanyManagement(
  companyId: string,
  relationshipId: string,
  input: EndRelationshipInput,
): Promise<CompanyManagementRelationship> {
  const response = await apiClient.post<{ data: CompanyManagementRelationship }>(
    `/api/v1/companies/${companyId}/management/${relationshipId}/end`,
    input,
  );

  return response.data.data;
}

/*
 * Ownership — who owns the organization.
 */

export async function getCompanyShareholders(
  companyId: string,
): Promise<CompanyShareholderRelationship[]> {
  const response = await apiClient.get<{ data: CompanyShareholderRelationship[] }>(
    `/api/v1/companies/${companyId}/shareholders`,
  );

  return response.data.data;
}

export async function getCompanyShareholderOptions(
  companyId: string,
  search?: string,
): Promise<CompanyRelationshipOptions<OwnershipRelationshipType>> {
  const response = await apiClient.get<{
    data: CompanyRelationshipOptions<OwnershipRelationshipType>;
  }>(`/api/v1/companies/${companyId}/shareholders/options`, {
    params: { search: search === "" ? undefined : search },
  });

  return response.data.data;
}

export async function addCompanyShareholder(
  companyId: string,
  input: AddShareholderInput,
): Promise<CompanyShareholderRelationship> {
  const response = await apiClient.post<{ data: CompanyShareholderRelationship }>(
    `/api/v1/companies/${companyId}/shareholders`,
    input,
  );

  return response.data.data;
}

/**
 * Close an ownership relationship. As above: the row stays.
 */
export async function endCompanyShareholder(
  companyId: string,
  relationshipId: string,
  input: EndRelationshipInput,
): Promise<CompanyShareholderRelationship> {
  const response = await apiClient.post<{ data: CompanyShareholderRelationship }>(
    `/api/v1/companies/${companyId}/shareholders/${relationshipId}/end`,
    input,
  );

  return response.data.data;
}
