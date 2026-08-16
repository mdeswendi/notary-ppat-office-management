import { apiClient } from "@/lib/api/client";
import type { PartyDirectoryPage, PartyDirectoryQuery } from "@/types/party";

/**
 * Query keys for the unified Party Directory.
 *
 * The key carries the ordinary filters and nothing else. There is deliberately
 * **no identifier in any key here**, and no directory endpoint that would accept
 * one: a cache entry keyed by a NIK would outlive the component, survive
 * navigation, and be trivially inspectable, and the search itself would make the
 * directory the existence oracle the Office-scoped duplicate rules exist to
 * prevent (D-084).
 *
 * Kept clear of `individualQueryKeys` and `companyQueryKeys` so invalidating a
 * subtype list does not silently refetch a directory the caller may be reading
 * under a different scope.
 */
export const partyDirectoryKeys = {
  all: ["parties"] as const,
  list: (query: PartyDirectoryQuery) => ["parties", "directory", query] as const,
};

/**
 * One page of the directory.
 *
 * Read-only, and the only generic Party call that exists. There is no create,
 * update, or archive counterpart in this file and there must never be:
 * Individual and Company own their lifecycles, each with its own permissions,
 * validation, and aggregate rules (D-078).
 *
 * Rows are whatever the API returns. The backend evaluates `parties.view` and
 * `companies.view` independently, at their own scopes, so a caller may
 * legitimately receive people from one Office and organizations from several —
 * nothing here filters, ranks, or reconciles the two.
 */
export async function getPartyDirectory(query: PartyDirectoryQuery): Promise<PartyDirectoryPage> {
  const response = await apiClient.get<PartyDirectoryPage>("/api/v1/parties", {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
      party_type: query.party_type === "" ? undefined : query.party_type,
      office_id: query.office_id === "" ? undefined : query.office_id,
    },
  });

  return response.data;
}
