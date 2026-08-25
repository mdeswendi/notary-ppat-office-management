import { apiClient } from "@/lib/api/client";
import type {
  Warkah,
  WarkahItem,
  WarkahItemCreateInput,
  WarkahItemList,
  WarkahItemUpdateInput,
  WarkahListPage,
  WarkahListQuery,
  WarkahOptions,
  WarkahStatus,
} from "@/types/warkah";

const ROOT = "/api/v1/ppat/warkah";
const DEED_ROOT = "/api/v1/ppat/deeds";

/**
 * Query keys for the Warkah surface (M7.4, D-121).
 *
 * **Keyed under the deed**, because a bundle has no existence apart from one and no
 * address omits it. The top-level list is its own key root: it answers a different
 * question — *which bundles are still short?* — across deeds.
 */
export const warkahKeys = {
  all: () => ["ppat", "warkah"] as const,
  list: (query: WarkahListQuery) => ["ppat", "warkah", "list", query] as const,
  options: () => ["ppat", "warkah", "options"] as const,
  forDeed: (deedId: string) => ["ppat", "deeds", deedId, "warkah"] as const,
  items: (deedId: string) => ["ppat", "deeds", deedId, "warkah", "items"] as const,
};

/**
 * Every Warkah the caller may see.
 *
 * Scoped through the deed: a bundle whose deed the caller cannot open never appears,
 * and no filter can widen that.
 */
export async function getWarkahList(query: WarkahListQuery): Promise<WarkahListPage> {
  const blank = (value: string | undefined) => (value === "" ? undefined : value);

  const response = await apiClient.get<WarkahListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: blank(query.search),
      status: blank(query.status),
      incomplete_only: query.incomplete_only ? 1 : undefined,
    },
  });

  return response.data;
}

export async function getWarkahOptions(): Promise<WarkahOptions["data"]> {
  const response = await apiClient.get<WarkahOptions>(`${ROOT}/options`);

  return response.data.data;
}

/**
 * The bundle for one deed.
 *
 * **404 while the office has not started one**, and this does not create it. The M7.4
 * brief asked the read endpoint to *"create if not exists"*; a `view` capability that
 * silently writes is one nobody can reason about, and a read-only actor's page load
 * would insert a row. The bundle materialises on the first line added or the first
 * status set, under `ppat.warkah.update`.
 *
 * A 404 here means one of two things the caller cannot tell apart, by design: nothing
 * started, or a deed they may not reach — the M6.3 convention.
 */
export async function getWarkah(deedId: string): Promise<Warkah> {
  const response = await apiClient.get<{ data: Warkah }>(`${DEED_ROOT}/${deedId}/warkah`);

  return response.data.data;
}

/**
 * Set the bundle's status, starting it if the office has not yet.
 *
 * **Two values.** `COMPLETE` answers to {@link verifyWarkah} because it stamps a pair;
 * `FINALIZED` and `ARCHIVED` answer to nothing at all and are a 422 naming the field.
 */
export async function setWarkahStatus(
  deedId: string,
  status: WarkahStatus,
  notes?: string | null,
): Promise<Warkah> {
  const response = await apiClient.patch<{ data: Warkah }>(`${DEED_ROOT}/${deedId}/warkah/status`, {
    status,
    ...(notes === undefined ? {} : { notes }),
  });

  return response.data.data;
}

/**
 * Mark the bundle verified — `COMPLETE`, with `verified_at` and `verified_by`.
 *
 * **Nothing gates it.** Not completeness: a bundle at 40% may be verified and one at
 * 100% need not be, because *"100% does not mean complete in law"* — it means every
 * line this office listed has a file. Not the item statuses, which have no vocabulary.
 * Not the current status, because there is no transition matrix. Each of those would be
 * an invented rule against open question three.
 */
export async function verifyWarkah(deedId: string, notes?: string | null): Promise<Warkah> {
  const response = await apiClient.post<{ data: Warkah }>(`${DEED_ROOT}/${deedId}/warkah/verify`, {
    ...(notes === undefined ? {} : { notes }),
  });

  return response.data.data;
}

/*
|--------------------------------------------------------------------------
| The lines, and the documents filed against them
|--------------------------------------------------------------------------
*/

/**
 * The checklist, in the order the office arranged it.
 *
 * `meta.warkah_started` distinguishes *no bundle yet* from *an empty one*, so the
 * section can render an empty state rather than an error.
 */
export async function getWarkahItems(deedId: string): Promise<WarkahItemList> {
  const response = await apiClient.get<WarkahItemList>(`${DEED_ROOT}/${deedId}/warkah/items`);

  return response.data;
}

/**
 * Add a line, starting the bundle if the office has not yet.
 *
 * There is no `ppat.warkah.create` in the catalogue, so composing the checklist is what
 * brings the bundle into existence.
 */
export async function addWarkahItem(
  deedId: string,
  input: WarkahItemCreateInput,
): Promise<WarkahItem> {
  const response = await apiClient.post<{ data: WarkahItem }>(
    `${DEED_ROOT}/${deedId}/warkah/items`,
    input,
  );

  return response.data.data;
}

export async function updateWarkahItem(
  deedId: string,
  itemId: string,
  input: WarkahItemUpdateInput,
): Promise<WarkahItem> {
  const response = await apiClient.patch<{ data: WarkahItem }>(
    `${DEED_ROOT}/${deedId}/warkah/items/${itemId}`,
    input,
  );

  return response.data.data;
}

/**
 * Take a line off the checklist.
 *
 * **A hard delete**, because `ppat_warkah_items` has no `deleted_at` in the ERD. The
 * Documents themselves are untouched — only the office's assertion that this file
 * satisfied this line goes. The confirmation says so rather than implying an undo.
 */
export async function removeWarkahItem(deedId: string, itemId: string): Promise<void> {
  await apiClient.delete(`${DEED_ROOT}/${deedId}/warkah/items/${itemId}`);
}

/**
 * File a Document against a line.
 *
 * The one act that moves completeness up. **Attaching is not reading**: opening the
 * file still answers to `documents.view` and downloading to `documents.download`, each
 * with its own Data Scope (D-115).
 */
export async function attachWarkahDocument(
  deedId: string,
  itemId: string,
  documentId: string,
): Promise<WarkahItem> {
  const response = await apiClient.post<{ data: WarkahItem }>(
    `${DEED_ROOT}/${deedId}/warkah/items/${itemId}/documents`,
    { document_id: documentId },
  );

  return response.data.data;
}

/**
 * Stop treating a Document as satisfying a line.
 *
 * The junction row only — never the Document, never the line. `ppat.warkah.upload` is
 * the same code that attaches: there is no `ppat.warkah.detach`, and removing a
 * misfiled document is the correction of the upload rather than a different act.
 */
export async function detachWarkahDocument(
  deedId: string,
  itemId: string,
  documentId: string,
): Promise<void> {
  await apiClient.delete(`${DEED_ROOT}/${deedId}/warkah/items/${itemId}/documents/${documentId}`);
}
