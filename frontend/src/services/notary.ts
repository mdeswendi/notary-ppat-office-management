import { apiClient } from "@/lib/api/client";
import type {
  NotaryDeed,
  NotaryDeedCreateInput,
  NotaryDeedListPage,
  NotaryDeedListQuery,
  NotaryDeedOptions,
  NotaryDeedUpdateInput,
  NotaryMinuta,
  NotaryMinutaInput,
} from "@/types/notary";

const ROOT = "/api/v1/notary/deeds";

/**
 * Query keys for the Notary Deed surface (M6.2, D-120).
 *
 * **Not keyed by domain**, unlike Matter: `notary.deeds.*` is a Notary-only
 * namespace and PPAT deeds are a different table in a different milestone, so there
 * is nothing for a key to separate.
 */
export const notaryDeedKeys = {
  all: () => ["notary", "deeds"] as const,
  minuta: (id: string) => ["notary", "deeds", "detail", id, "minuta"] as const,
  list: (query: NotaryDeedListQuery) => ["notary", "deeds", "list", query] as const,
  detail: (id: string) => ["notary", "deeds", "detail", id] as const,
  options: () => ["notary", "deeds", "options"] as const,
};

export async function getNotaryDeeds(query: NotaryDeedListQuery): Promise<NotaryDeedListPage> {
  const blank = (value: string | undefined) => (value === "" ? undefined : value);

  const response = await apiClient.get<NotaryDeedListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: blank(query.search),
      status: blank(query.status),
      matter_id: blank(query.matter_id),
      deed_type_code: blank(query.deed_type_code),
      deed_date_from: blank(query.deed_date_from),
      deed_date_to: blank(query.deed_date_to),
    },
  });

  return response.data;
}

export async function getNotaryDeed(id: string): Promise<NotaryDeed> {
  const response = await apiClient.get<{ data: NotaryDeed }>(`${ROOT}/${id}`);

  return response.data.data;
}

export async function getNotaryDeedOptions(): Promise<NotaryDeedOptions["data"]> {
  const response = await apiClient.get<NotaryDeedOptions>(`${ROOT}/options`);

  return response.data.data;
}

export async function createNotaryDeed(input: NotaryDeedCreateInput): Promise<NotaryDeed> {
  const response = await apiClient.post<{ data: NotaryDeed }>(ROOT, input);

  return response.data.data;
}

export async function updateNotaryDeed(
  id: string,
  input: NotaryDeedUpdateInput,
): Promise<NotaryDeed> {
  const response = await apiClient.patch<{ data: NotaryDeed }>(`${ROOT}/${id}`, input);

  return response.data.data;
}

export async function reviewNotaryDeed(id: string): Promise<NotaryDeed> {
  const response = await apiClient.patch<{ data: NotaryDeed }>(`${ROOT}/${id}/review`);

  return response.data.data;
}

export async function approveNotaryDeed(id: string): Promise<NotaryDeed> {
  const response = await apiClient.patch<{ data: NotaryDeed }>(`${ROOT}/${id}/approve`);

  return response.data.data;
}

/**
 * Finalize a deed.
 *
 * **Assigns no number and locks nothing.** Numbering answers to
 * `notary.deeds.number` on its own endpoint, because tying it to finalization would
 * assert *when* a deed is numbered — half of an open domain question (D-120).
 */
export async function finalizeNotaryDeed(id: string): Promise<NotaryDeed> {
  const response = await apiClient.patch<{ data: NotaryDeed }>(`${ROOT}/${id}/finalize`);

  return response.data.data;
}

/**
 * Record the legal number the office assigned.
 *
 * Its own capability and its own endpoint. The office supplies the number; the
 * software validates no format and generates nothing.
 */
export async function recordNotaryDeedNumber(id: string, deedNumber: string): Promise<NotaryDeed> {
  const response = await apiClient.patch<{ data: NotaryDeed }>(`${ROOT}/${id}/number`, {
    deed_number: deedNumber,
  });

  return response.data.data;
}

/**
 * The deed's filing record (M6.3, D-120).
 *
 * **Nested under the deed and singular** — one record or 404, never a collection.
 * There is deliberately no `/notary/minuta/{id}` address: a Minuta has no independent
 * existence, so an address that omitted the deed would name something the domain does
 * not have (the M4.5 convention, D-105).
 *
 * A 404 here means one of two things the caller cannot tell apart, which is by
 * design: nothing filed, or a deed the caller may not reach.
 */
export async function getMinuta(deedId: string): Promise<NotaryMinuta> {
  const response = await apiClient.get<{ data: NotaryMinuta }>(`${ROOT}/${deedId}/minuta`);

  return response.data.data;
}

export async function fileMinuta(deedId: string, input: NotaryMinutaInput): Promise<NotaryMinuta> {
  const response = await apiClient.post<{ data: NotaryMinuta }>(`${ROOT}/${deedId}/minuta`, input);

  return response.data.data;
}

/**
 * Correct the filing — the shelf reference, or the file itself.
 *
 * **Replacing `document_id` is the point**, not an edge case: a bad scan, a page
 * missed, a better copy. Both Documents keep their own version histories either side
 * of it. There is no delete — the catalogue defines no `notary.minuta.delete`, and a
 * Minuta filed against the wrong deed is corrected by replacing its file.
 */
export async function updateMinuta(
  deedId: string,
  input: NotaryMinutaInput,
): Promise<NotaryMinuta> {
  const response = await apiClient.patch<{ data: NotaryMinuta }>(`${ROOT}/${deedId}/minuta`, input);

  return response.data.data;
}
