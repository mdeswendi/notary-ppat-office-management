import { apiClient } from "@/lib/api/client";
import type {
  Individual,
  IndividualCreateInput,
  IndividualIdentity,
  IndividualIdentityInput,
  IndividualListPage,
  IndividualListQuery,
  IndividualOptions,
  IndividualUpdateInput,
  RevealedIdentifier,
} from "@/types/individual";

/**
 * Query keys for the Individual directory.
 *
 * There is deliberately **no key for a revealed identifier**. Reveal is a
 * mutation whose result lives in component state and nowhere else — giving it a
 * query key would put a raw NIK into a cache that outlives the component,
 * survives navigation, and is trivially inspectable (D-082).
 */
export const individualQueryKeys = {
  all: ["individuals"] as const,
  list: (query: IndividualListQuery) => ["individuals", "list", query] as const,
  detail: (id: string) => ["individuals", "detail", id] as const,
  identity: (id: string) => ["individuals", "identity", id] as const,
  options: ["individuals", "options"] as const,
};

export async function getIndividuals(query: IndividualListQuery): Promise<IndividualListPage> {
  const response = await apiClient.get<IndividualListPage>("/api/v1/individuals", {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
    },
  });

  return response.data;
}

export async function getIndividual(id: string): Promise<Individual> {
  const response = await apiClient.get<{ data: Individual }>(`/api/v1/individuals/${id}`);

  return response.data.data;
}

export async function createIndividual(input: IndividualCreateInput): Promise<Individual> {
  const response = await apiClient.post<{ data: Individual }>("/api/v1/individuals", input);

  return response.data.data;
}

export async function updateIndividual(
  id: string,
  input: IndividualUpdateInput,
): Promise<Individual> {
  const response = await apiClient.patch<{ data: Individual }>(`/api/v1/individuals/${id}`, input);

  return response.data.data;
}

/**
 * Archive the aggregate. Not a deletion — the record is retired, not destroyed,
 * and there is no restore counterpart because no restore permission exists.
 */
export async function archiveIndividual(id: string): Promise<void> {
  await apiClient.post(`/api/v1/individuals/${id}/archive`);
}

export async function getIndividualOptions(): Promise<IndividualOptions> {
  const response = await apiClient.get<{ data: IndividualOptions }>("/api/v1/individuals/options");

  return response.data.data;
}

/*
 * Sensitive identity. Two tiers, and the split is visible in the shapes: the
 * surface returns masks, and only the reveal calls return a value.
 */

export async function getIndividualIdentity(id: string): Promise<IndividualIdentity> {
  const response = await apiClient.get<{ data: IndividualIdentity }>(
    `/api/v1/individuals/${id}/identity`,
  );

  return response.data.data;
}

export async function updateIndividualIdentity(
  id: string,
  input: IndividualIdentityInput,
): Promise<IndividualIdentity> {
  const response = await apiClient.patch<{ data: IndividualIdentity }>(
    `/api/v1/individuals/${id}/identity`,
    input,
  );

  return response.data.data;
}

/**
 * Reveal one raw identifier, deliberately.
 *
 * `POST` rather than `GET` so the value cannot land in a cached response, a
 * browser history entry, or a URL. The backend answers `no-store`. The caller
 * must hold that field's own permission — holding the other one is not enough,
 * and the server decides regardless of what the interface believed.
 *
 * The returned value belongs in local component state and must never be written
 * to a query cache, `localStorage`, `sessionStorage`, or the URL.
 */
export async function revealIndividualNik(id: string): Promise<RevealedIdentifier> {
  const response = await apiClient.post<{ data: RevealedIdentifier }>(
    `/api/v1/individuals/${id}/identity/nik/reveal`,
  );

  return response.data.data;
}

export async function revealIndividualNpwp(id: string): Promise<RevealedIdentifier> {
  const response = await apiClient.post<{ data: RevealedIdentifier }>(
    `/api/v1/individuals/${id}/identity/npwp/reveal`,
  );

  return response.data.data;
}
