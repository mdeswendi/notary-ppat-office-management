import { apiClient } from "@/lib/api/client";
import type {
  PpatDeed,
  PpatDeedCreateInput,
  PpatDeedListPage,
  PpatDeedListQuery,
  PpatDeedOptions,
  PpatDeedUpdateInput,
} from "@/types/ppat";

const ROOT = "/api/v1/ppat/deeds";

/**
 * Query keys for the PPAT Deed surface (M7.2, D-121).
 *
 * **Not keyed by domain**, unlike Matter: `ppat.deeds.*` is a PPAT-only namespace and
 * a Notarial Deed is a different table behind a different key root, so there is
 * nothing for a key to separate. The two roots being distinct is what keeps one
 * domain's cache from ever answering the other's query.
 */
export const ppatDeedKeys = {
  all: () => ["ppat", "deeds"] as const,
  list: (query: PpatDeedListQuery) => ["ppat", "deeds", "list", query] as const,
  detail: (id: string) => ["ppat", "deeds", "detail", id] as const,
  options: () => ["ppat", "deeds", "options"] as const,
};

export async function getPpatDeeds(query: PpatDeedListQuery): Promise<PpatDeedListPage> {
  const blank = (value: string | undefined) => (value === "" ? undefined : value);

  const response = await apiClient.get<PpatDeedListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: blank(query.search),
      status: blank(query.status),
      matter_id: blank(query.matter_id),
      project_id: blank(query.project_id),
      deed_type_code: blank(query.deed_type_code),
      deed_date_from: blank(query.deed_date_from),
      deed_date_to: blank(query.deed_date_to),
    },
  });

  return response.data;
}

export async function getPpatDeed(id: string): Promise<PpatDeed> {
  const response = await apiClient.get<{ data: PpatDeed }>(`${ROOT}/${id}`);

  return response.data.data;
}

export async function getPpatDeedOptions(): Promise<PpatDeedOptions["data"]> {
  const response = await apiClient.get<PpatDeedOptions>(`${ROOT}/options`);

  return response.data.data;
}

export async function createPpatDeed(input: PpatDeedCreateInput): Promise<PpatDeed> {
  const response = await apiClient.post<{ data: PpatDeed }>(ROOT, input);

  return response.data.data;
}

export async function updatePpatDeed(id: string, input: PpatDeedUpdateInput): Promise<PpatDeed> {
  const response = await apiClient.patch<{ data: PpatDeed }>(`${ROOT}/${id}`, input);

  return response.data.data;
}

export async function reviewPpatDeed(id: string): Promise<PpatDeed> {
  const response = await apiClient.patch<{ data: PpatDeed }>(`${ROOT}/${id}/review`);

  return response.data.data;
}

/**
 * Approve a deed.
 *
 * **Who may call this is decided by `ppat.deeds.approve` and by nothing else.** The
 * brief specified *"hanya PRINCIPAL/SUPER_ADMIN"*; restricting approval to the
 * Principal is done by granting that capability to that role alone through the
 * Permission Matrix, which is office configuration. No role name appears here or in
 * the backend (D-032, D-048).
 */
export async function approvePpatDeed(id: string): Promise<PpatDeed> {
  const response = await apiClient.patch<{ data: PpatDeed }>(`${ROOT}/${id}/approve`);

  return response.data.data;
}

/**
 * Finalize a deed.
 *
 * **Assigns no number, creates no register entry, files no tax record and locks
 * nothing.** Numbering answers to `ppat.deeds.number` on its own endpoint; the deed
 * register format and its finalization period are open question six with no table to
 * write to; and tax obligations are open question four with no capability at all
 * (D-121, O-040, O-042).
 */
export async function finalizePpatDeed(id: string): Promise<PpatDeed> {
  const response = await apiClient.patch<{ data: PpatDeed }>(`${ROOT}/${id}/finalize`);

  return response.data.data;
}

/**
 * Record the legal number the office assigned.
 *
 * Its own capability and its own endpoint. The office supplies the number; the
 * software validates no format and generates nothing, because *"what are the deed
 * numbering rules, and who assigns the number?"* is open question five.
 */
export async function recordPpatDeedNumber(id: string, deedNumber: string): Promise<PpatDeed> {
  const response = await apiClient.patch<{ data: PpatDeed }>(`${ROOT}/${id}/number`, {
    deed_number: deedNumber,
  });

  return response.data.data;
}
