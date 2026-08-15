import { apiClient } from "@/lib/api/client";
import type {
  CompanyDuplicateCheckInput,
  DuplicateCandidate,
  DuplicateCandidateResult,
  IndividualDuplicateCheckInput,
} from "@/types/party-duplicate";

/**
 * Advisory duplicate checks (D-084, D-086).
 *
 * **There are deliberately no query keys in this file.** A duplicate check
 * carries the values being typed — on the identity surfaces that includes a NIK,
 * an NPWP, or a tax identifier — so every call here is a mutation whose result
 * lives in component state and nowhere else. A TanStack key would put the
 * submitted identifier into a cache key that outlives the component, survives
 * navigation, and is trivially inspectable; the same reasoning that kept reveal
 * out of the cache (D-082).
 *
 * **`POST` for every variant, including the ones that only read.** A `GET` would
 * put the identifier in a URL, a browser history entry, a proxy log, and a
 * cached response. Nothing here mutates anything, and the backend answers
 * `no-store`.
 *
 * **Advisory, never blocking.** These calls sit beside the create and update
 * surfaces, not inside them. No lifecycle Action refuses because a candidate
 * exists, nothing here merges, archives, or reuses a record, and a caller that
 * skips the check entirely still saves normally. The software surfaces evidence;
 * a person decides.
 *
 * **`exclude_party_id` is not sent and must never be added.** The record being
 * edited is excluded server-side from the route's own subject; a client-supplied
 * exclusion would let a caller suppress an inconvenient candidate from somebody
 * else's review, and the backend rejects the field outright.
 */

async function post(url: string, body: unknown): Promise<DuplicateCandidate[]> {
  const response = await apiClient.post<DuplicateCandidateResult>(url, body);

  return response.data.data;
}

/**
 * Candidates for an Individual about to be created in a chosen Office.
 *
 * `office_id` is required here: the record does not exist yet, so the Office it
 * is destined for is what bounds the comparison. The check never reaches beyond
 * that Office, including for an `ALL`-scoped actor.
 */
export function checkIndividualDuplicatesForCreate(
  input: IndividualDuplicateCheckInput & { office_id: string },
): Promise<DuplicateCandidate[]> {
  return post("/api/v1/individuals/duplicate-candidates", input);
}

/**
 * Candidates for an Individual being edited.
 *
 * No `office_id`: the backend takes it from the record and rejects the field.
 * The subject itself is excluded server-side, so a record never matches itself.
 */
export function checkIndividualDuplicatesForUpdate(
  individualId: string,
  input: IndividualDuplicateCheckInput,
): Promise<DuplicateCandidate[]> {
  return post(`/api/v1/individuals/${individualId}/duplicate-candidates`, input);
}

export function checkCompanyDuplicatesForCreate(
  input: CompanyDuplicateCheckInput & { office_id: string },
): Promise<DuplicateCandidate[]> {
  return post("/api/v1/companies/duplicate-candidates", input);
}

export function checkCompanyDuplicatesForUpdate(
  companyId: string,
  input: CompanyDuplicateCheckInput,
): Promise<DuplicateCandidate[]> {
  return post(`/api/v1/companies/${companyId}/duplicate-candidates`, input);
}
