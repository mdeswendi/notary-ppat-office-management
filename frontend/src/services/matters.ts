import { apiClient } from "@/lib/api/client";
import type {
  Matter,
  MatterAssigneeOptions,
  MatterAssignmentInput,
  MatterCreateInput,
  MatterDomain,
  MatterListPage,
  MatterListQuery,
  MatterServiceTypeOptions,
  MatterUpdateInput,
} from "@/types/matter";

/**
 * The API root for a Matter domain (M4.4, D-101).
 *
 * `/api/v1/notary/matters` and `/api/v1/ppat/matters` are separate address
 * spaces, and the segment is not decoration: the backend selects the permission
 * namespace from it. Every call therefore carries the domain, and no request body
 * ever does.
 */
function root(domain: MatterDomain): string {
  return domain === "NOTARY" ? "/api/v1/notary/matters" : "/api/v1/ppat/matters";
}

/**
 * Query keys for the Matter surface.
 *
 * **Keyed by domain first, deliberately.** Notary and PPAT answer to independent
 * capabilities, so an actor may hold one and not the other; a shared key would
 * make invalidating one list refetch a surface the caller may not be authorized
 * for — the same reasoning that split the archived Project keys at M3.3 and the
 * Company relationship keys at M2.4.
 */
export const matterQueryKeys = {
  all: (domain: MatterDomain) => ["matters", domain] as const,
  list: (domain: MatterDomain, query: MatterListQuery) =>
    ["matters", domain, "list", query] as const,
  detail: (domain: MatterDomain, id: string) => ["matters", domain, "detail", id] as const,
  assigneeOptions: (domain: MatterDomain, id: string) =>
    ["matters", domain, "detail", id, "assignees"] as const,
  serviceTypeOptions: (domain: MatterDomain) => ["matters", domain, "service-types"] as const,
};

export async function getMatters(
  domain: MatterDomain,
  query: MatterListQuery,
): Promise<MatterListPage> {
  const response = await apiClient.get<MatterListPage>(root(domain), {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
      status: query.status === "" ? undefined : query.status,
      priority: query.priority === "" ? undefined : query.priority,
      project_id: query.project_id === "" ? undefined : query.project_id,
    },
  });

  return response.data;
}

export async function getMatter(domain: MatterDomain, id: string): Promise<Matter> {
  const response = await apiClient.get<{ data: Matter }>(`${root(domain)}/${id}`);

  return response.data.data;
}

/**
 * Create a Matter under a Project.
 *
 * The payload carries ordinary fields only. Domain, Office, reference, status and
 * PIC are all decided elsewhere, and sending any of them is a 422.
 */
export async function createMatter(
  domain: MatterDomain,
  input: MatterCreateInput,
): Promise<Matter> {
  const response = await apiClient.post<{ data: Matter }>(root(domain), input);

  return response.data.data;
}

export async function updateMatter(
  domain: MatterDomain,
  id: string,
  input: MatterUpdateInput,
): Promise<Matter> {
  const response = await apiClient.patch<{ data: Matter }>(`${root(domain)}/${id}`, input);

  return response.data.data;
}

/**
 * Set or clear the person in charge.
 *
 * Its own endpoint because it is its own capability: ordinary update cannot
 * reassign work, and a caller holding only `update` gets a 403 here.
 */
export async function assignMatterPic(
  domain: MatterDomain,
  id: string,
  input: MatterAssignmentInput,
): Promise<Matter> {
  const response = await apiClient.patch<{ data: Matter }>(
    `${root(domain)}/${id}/assignment`,
    input,
  );

  return response.data.data;
}

export async function getMatterAssigneeOptions(
  domain: MatterDomain,
  id: string,
): Promise<MatterAssigneeOptions["data"]["users"]> {
  const response = await apiClient.get<MatterAssigneeOptions>(
    `${root(domain)}/${id}/assignment/options`,
  );

  return response.data.data.users;
}

/**
 * The classifications this domain's Matters may use.
 *
 * Bounded to the caller's own Office, this domain, and active entries. A retired
 * Service Type stays valid on Matters that already reference it and is simply not
 * offered for new selection (D-106).
 */
export async function getMatterServiceTypeOptions(
  domain: MatterDomain,
): Promise<MatterServiceTypeOptions["data"]["service_types"]> {
  const response = await apiClient.get<MatterServiceTypeOptions>(
    `${root(domain)}/service-type-options`,
  );

  return response.data.data.service_types;
}

/**
 * Complete a Matter.
 *
 * One of exactly two status transitions the product offers. There is no generic
 * status control, because the canonical registry gives Matter no `change_status`
 * capability (D-109).
 */
export async function completeMatter(domain: MatterDomain, id: string): Promise<Matter> {
  const response = await apiClient.post<{ data: Matter }>(`${root(domain)}/${id}/complete`);

  return response.data.data;
}

export async function cancelMatter(domain: MatterDomain, id: string): Promise<Matter> {
  const response = await apiClient.post<{ data: Matter }>(`${root(domain)}/${id}/cancel`);

  return response.data.data;
}
