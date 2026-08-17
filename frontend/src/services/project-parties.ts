import { apiClient } from "@/lib/api/client";
import type {
  ProjectParty,
  ProjectPartyCandidates,
  ProjectPartyCreateInput,
  ProjectPartyListPage,
  ProjectPartyUpdateInput,
} from "@/types/project-party";

/**
 * Query keys for a Project's participation.
 *
 * Nested under the Project's own detail key, because that is what the data
 * belongs to and what authorizes it — invalidating a Project should be able to
 * refresh its participants without reaching into a separate tree.
 */
export const projectPartyQueryKeys = {
  all: (projectId: string) => ["projects", "detail", projectId, "parties"] as const,
  candidates: (projectId: string, search: string) =>
    ["projects", "detail", projectId, "party-candidates", search] as const,
};

export async function getProjectParties(projectId: string): Promise<ProjectPartyListPage> {
  const response = await apiClient.get<ProjectPartyListPage>(
    `/api/v1/projects/${projectId}/parties`,
  );

  return response.data;
}

/**
 * Who may be linked to this Project.
 *
 * Same Office as the Project, not archived, and visible to the caller under the
 * Party-domain permission for that Party's own subtype. Holding
 * `projects.parties.manage` is not by itself a way to discover Parties, so this
 * list can be legitimately empty for somebody who may otherwise manage
 * participation (D-098).
 */
export async function getProjectPartyCandidates(
  projectId: string,
  search: string,
): Promise<ProjectPartyCandidates> {
  const response = await apiClient.get<{ data: ProjectPartyCandidates }>(
    `/api/v1/projects/${projectId}/party-options`,
    { params: { search: search === "" ? undefined : search } },
  );

  return response.data.data;
}

export async function addProjectParty(
  projectId: string,
  input: ProjectPartyCreateInput,
): Promise<ProjectParty> {
  const response = await apiClient.post<{ data: ProjectParty }>(
    `/api/v1/projects/${projectId}/parties`,
    input,
  );

  return response.data.data;
}

export async function updateProjectParty(
  projectId: string,
  participationId: string,
  input: ProjectPartyUpdateInput,
): Promise<ProjectParty> {
  const response = await apiClient.patch<{ data: ProjectParty }>(
    `/api/v1/projects/${projectId}/parties/${participationId}`,
    input,
  );

  return response.data.data;
}

/**
 * Unlink a Party.
 *
 * Deletes the relationship row and nothing else — the Project stays, the Party
 * stays, and neither is archived. There is no restore, because participation
 * keeps no history (D-098); the interface says so before asking for
 * confirmation.
 */
export async function removeProjectParty(
  projectId: string,
  participationId: string,
): Promise<void> {
  await apiClient.delete(`/api/v1/projects/${projectId}/parties/${participationId}`);
}
