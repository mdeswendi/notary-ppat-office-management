import { apiClient } from "@/lib/api/client";
import type { MatterDomain } from "@/types/matter";
import type {
  MatterStage,
  MatterStageMoveInput,
  MatterStageOptions,
  MatterWorkflowPage,
} from "@/types/matter-stage";

/**
 * The stage root for one Matter (M4.7, D-101).
 *
 * The domain segment selects the permission namespace on the backend, so every
 * call carries it and no request body ever does.
 */
function root(domain: MatterDomain, matterId: string): string {
  const base = domain === "NOTARY" ? "/api/v1/notary/matters" : "/api/v1/ppat/matters";

  return `${base}/${matterId}/stages`;
}

/**
 * Query keys for a Matter's running workflow.
 *
 * Nested under the Matter's own detail key, which is itself domain-first, so a
 * Notary Matter's stages and a PPAT Matter's can never share a cache entry.
 */
export const matterStageQueryKeys = {
  all: (domain: MatterDomain, matterId: string) =>
    ["matters", domain, "detail", matterId, "stages"] as const,
  options: (domain: MatterDomain, matterId: string) =>
    ["matters", domain, "detail", matterId, "stage-options"] as const,
};

/**
 * The whole run: stages, the current one, and the transition history.
 *
 * **A Matter with no workflow answers 200 with an empty run, not 404.** D-104
 * seeds no templates, so on a fresh deployment that is every Matter — the
 * interface says so rather than showing an error for the ordinary case.
 */
export async function getMatterWorkflow(
  domain: MatterDomain,
  matterId: string,
): Promise<MatterWorkflowPage> {
  const response = await apiClient.get<MatterWorkflowPage>(root(domain, matterId));

  return response.data;
}

/**
 * The stages this Matter may be moved to.
 *
 * Open stages only, and never the one already active. This is **not** a
 * transition matrix (D-104): it says a destination must be somewhere you can go,
 * not which destinations follow which origins — so a backward move is offered
 * exactly like a forward one.
 */
export async function getMatterStageOptions(
  domain: MatterDomain,
  matterId: string,
): Promise<MatterStageOptions> {
  const response = await apiClient.get<{ data: MatterStageOptions }>(
    `${root(domain, matterId)}/options`,
  );

  return response.data.data;
}

/**
 * Move to another stage.
 *
 * The stage moved away from becomes `COMPLETED`; stages jumped over stay
 * `PENDING` (D-112). The transition is recorded in append-only history.
 */
export async function moveMatterStage(
  domain: MatterDomain,
  matterId: string,
  input: MatterStageMoveInput,
): Promise<MatterStage> {
  const response = await apiClient.post<{ data: MatterStage }>(
    `${root(domain, matterId)}/move`,
    input,
  );

  return response.data.data;
}
