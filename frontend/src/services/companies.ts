import { apiClient } from "@/lib/api/client";
import type {
  Company,
  CompanyCreateInput,
  CompanyIdentity,
  CompanyIdentityInput,
  CompanyListPage,
  CompanyListQuery,
  CompanyOptions,
  CompanyUpdateInput,
  RevealedTaxId,
} from "@/types/company";

/**
 * Query keys for the Company directory.
 *
 * There is deliberately **no key for a revealed tax identifier**. Reveal is a
 * mutation whose result lives in component state and nowhere else — giving it a
 * query key would put a raw identifier into a cache that outlives the component,
 * survives navigation, and is trivially inspectable (D-082).
 */
export const companyQueryKeys = {
  all: ["companies"] as const,
  list: (query: CompanyListQuery) => ["companies", "list", query] as const,
  detail: (id: string) => ["companies", "detail", id] as const,
  identity: (id: string) => ["companies", "identity", id] as const,
  options: ["companies", "options"] as const,
};

export async function getCompanies(query: CompanyListQuery): Promise<CompanyListPage> {
  const response = await apiClient.get<CompanyListPage>("/api/v1/companies", {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
    },
  });

  return response.data;
}

export async function getCompany(id: string): Promise<Company> {
  const response = await apiClient.get<{ data: Company }>(`/api/v1/companies/${id}`);

  return response.data.data;
}

export async function createCompany(input: CompanyCreateInput): Promise<Company> {
  const response = await apiClient.post<{ data: Company }>("/api/v1/companies", input);

  return response.data.data;
}

export async function updateCompany(id: string, input: CompanyUpdateInput): Promise<Company> {
  const response = await apiClient.patch<{ data: Company }>(`/api/v1/companies/${id}`, input);

  return response.data.data;
}

/**
 * Archive the aggregate. Not a deletion — the record is retired, not destroyed,
 * and there is no restore counterpart because no restore permission exists.
 */
export async function archiveCompany(id: string): Promise<void> {
  await apiClient.post(`/api/v1/companies/${id}/archive`);
}

export async function getCompanyOptions(): Promise<CompanyOptions> {
  const response = await apiClient.get<{ data: CompanyOptions }>("/api/v1/companies/options");

  return response.data.data;
}

/*
 * Sensitive tax identity. Two tiers, and the split is visible in the shapes: the
 * surface returns a mask, and only the reveal call returns a value.
 */

export async function getCompanyIdentity(id: string): Promise<CompanyIdentity> {
  const response = await apiClient.get<{ data: CompanyIdentity }>(
    `/api/v1/companies/${id}/identity`,
  );

  return response.data.data;
}

export async function updateCompanyIdentity(
  id: string,
  input: CompanyIdentityInput,
): Promise<CompanyIdentity> {
  const response = await apiClient.patch<{ data: CompanyIdentity }>(
    `/api/v1/companies/${id}/identity`,
    input,
  );

  return response.data.data;
}

/**
 * Reveal the raw tax identifier, deliberately.
 *
 * `POST` rather than `GET` so the value cannot land in a cached response, a
 * browser history entry, or a URL. The backend answers `no-store`. The caller
 * must hold `parties.identity.npwp.view_full` — the Company tax identifier is
 * the NPWP, and it answers to the same canonical permission an Individual's
 * does. The server decides regardless of what the interface believed.
 *
 * The returned value belongs in local component state and must never be written
 * to a query cache, `localStorage`, `sessionStorage`, or the URL.
 */
export async function revealCompanyTaxId(id: string): Promise<RevealedTaxId> {
  const response = await apiClient.post<{ data: RevealedTaxId }>(
    `/api/v1/companies/${id}/identity/tax-id/reveal`,
  );

  return response.data.data;
}
