import { apiClient } from "@/lib/api/client";
import type {
  MatterParty,
  MatterPartyCandidates,
  MatterPartyCreateInput,
  MatterPartyListPage,
  MatterPartyUpdateInput,
} from "@/types/matter-party";
import type { MatterDomain } from "@/types/matter";

/**
 * The participation root for one Matter (M4.5, D-101).
 *
 * The domain segment is not decoration: the backend selects the permission
 * namespace from it, so `notary.matters.parties.*` guards one root and
 * `ppat.matters.parties.*` the other. Every call carries the domain, and no
 * request body ever does.
 */
function root(domain: MatterDomain, matterId: string): string {
  const base = domain === "NOTARY" ? "/api/v1/notary/matters" : "/api/v1/ppat/matters";

  return `${base}/${matterId}`;
}

/**
 * Query keys for a Matter's participation.
 *
 * Nested under the Matter's own detail key — which is itself domain-first — so a
 * Notary Matter's participants and a PPAT Matter's can never share a cache
 * entry, and invalidating a Matter refreshes its participants without reaching
 * into a separate tree.
 *
 * The search term is part of the candidate key and is an ordinary name
 * fragment. Nothing sensitive goes in a query key (D-082, D-105).
 */
export const matterPartyQueryKeys = {
  all: (domain: MatterDomain, matterId: string) =>
    ["matters", domain, "detail", matterId, "parties"] as const,
  candidates: (domain: MatterDomain, matterId: string, search: string) =>
    ["matters", domain, "detail", matterId, "party-candidates", search] as const,
};

export async function getMatterParties(
  domain: MatterDomain,
  matterId: string,
): Promise<MatterPartyListPage> {
  const response = await apiClient.get<MatterPartyListPage>(`${root(domain, matterId)}/parties`);

  return response.data;
}

/**
 * Who may be linked to this Matter.
 *
 * Same Office as the Matter, not archived, and visible to the caller under the
 * Party-domain permission for that Party's own subtype. Holding
 * `*.matters.parties.manage` is not by itself a way to discover Parties, so this
 * list can be legitimately empty for somebody who may otherwise manage
 * participation (D-105).
 *
 * The parent Project's participants get no special standing here: Matter
 * participation is independent of Project participation.
 */
export async function getMatterPartyCandidates(
  domain: MatterDomain,
  matterId: string,
  search: string,
): Promise<MatterPartyCandidates> {
  const response = await apiClient.get<{ data: MatterPartyCandidates }>(
    `${root(domain, matterId)}/party-options`,
    { params: { search: search === "" ? undefined : search } },
  );

  return response.data.data;
}

export async function addMatterParty(
  domain: MatterDomain,
  matterId: string,
  input: MatterPartyCreateInput,
): Promise<MatterParty> {
  const response = await apiClient.post<{ data: MatterParty }>(
    `${root(domain, matterId)}/parties`,
    input,
  );

  return response.data.data;
}

export async function updateMatterParty(
  domain: MatterDomain,
  matterId: string,
  participationId: string,
  input: MatterPartyUpdateInput,
): Promise<MatterParty> {
  const response = await apiClient.patch<{ data: MatterParty }>(
    `${root(domain, matterId)}/parties/${participationId}`,
    input,
  );

  return response.data.data;
}

/**
 * Unlink a Party.
 *
 * Deletes the relationship row and nothing else — the Matter stays, the Party
 * stays, and neither is archived. There is no restore, because participation
 * keeps no history (D-105); the interface says so before asking for
 * confirmation.
 */
export async function removeMatterParty(
  domain: MatterDomain,
  matterId: string,
  participationId: string,
): Promise<void> {
  await apiClient.delete(`${root(domain, matterId)}/parties/${participationId}`);
}
