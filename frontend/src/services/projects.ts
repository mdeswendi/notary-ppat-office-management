import { apiClient } from "@/lib/api/client";
import type {
  ArchivedProjectListPage,
  Project,
  ProjectAssigneeOptions,
  ProjectAssignmentInput,
  ProjectCreateInput,
  ProjectListPage,
  ProjectListQuery,
  ProjectStatusInput,
  ProjectUpdateInput,
} from "@/types/project";

/**
 * Query keys for the Project surface.
 *
 * **Archived Projects get their own tree, deliberately.** They are read through
 * `projects.restore` rather than `projects.view` (D-093), so a caller may hold
 * one capability and not the other — and a shared key would make invalidating the
 * live list refetch a surface the caller may not be authorized for, which is the
 * same reasoning that split the Company relationship keys at M2.4.
 */
export const projectQueryKeys = {
  all: ["projects"] as const,
  list: (query: ProjectListQuery) => ["projects", "list", query] as const,
  detail: (id: string) => ["projects", "detail", id] as const,
  assigneeOptions: (id: string) => ["projects", "detail", id, "assignees"] as const,
  archived: ["projects", "archived"] as const,
  archivedList: (query: ProjectListQuery) => ["projects", "archived", "list", query] as const,
};

export async function getProjects(query: ProjectListQuery): Promise<ProjectListPage> {
  const response = await apiClient.get<ProjectListPage>("/api/v1/projects", {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
      status: query.status === "" ? undefined : query.status,
      priority: query.priority === "" ? undefined : query.priority,
    },
  });

  return response.data;
}

export async function getProject(id: string): Promise<Project> {
  const response = await apiClient.get<{ data: Project }>(`/api/v1/projects/${id}`);

  return response.data.data;
}

/**
 * Create a Project in the caller's own Office.
 *
 * The payload carries ordinary fields only. Office, reference, status, and PIC
 * are all system-controlled, and sending any of them is a 422 rather than a
 * silent no-op — so an interface cannot appear to accept a choice it never made.
 */
export async function createProject(input: ProjectCreateInput): Promise<Project> {
  const response = await apiClient.post<{ data: Project }>("/api/v1/projects", input);

  return response.data.data;
}

/**
 * Ordinary attributes only. Status and PIC have their own calls below, because
 * they have their own permissions (D-091).
 */
export async function updateProject(id: string, input: ProjectUpdateInput): Promise<Project> {
  const response = await apiClient.patch<{ data: Project }>(`/api/v1/projects/${id}`, input);

  return response.data.data;
}

/**
 * Set or clear the person in charge.
 *
 * `pic_user_id: null` unassigns and must be sent explicitly — the backend
 * requires the field to be present, so an empty body cannot silently unassign.
 */
export async function assignProjectPic(
  id: string,
  input: ProjectAssignmentInput,
): Promise<Project> {
  const response = await apiClient.patch<{ data: Project }>(
    `/api/v1/projects/${id}/assignment`,
    input,
  );

  return response.data.data;
}

/**
 * Who may be put in charge — active users of the Project's own Office.
 *
 * Authorized by `projects.assign` on this Project, not by a User Management
 * permission: populating a picker is not a reason to widen access to the user
 * directory.
 */
export async function getProjectAssigneeOptions(id: string): Promise<ProjectAssigneeOptions> {
  const response = await apiClient.get<{ data: ProjectAssigneeOptions }>(
    `/api/v1/projects/${id}/assignment/options`,
  );

  return response.data.data;
}

export async function changeProjectStatus(id: string, input: ProjectStatusInput): Promise<Project> {
  const response = await apiClient.patch<{ data: Project }>(`/api/v1/projects/${id}/status`, input);

  return response.data.data;
}

/**
 * Archive the record. Not a deletion — the Project is filed away, keeps its
 * reference, and keeps its business status. `DELETE` is the verb; destruction is
 * not the meaning.
 */
export async function archiveProject(id: string): Promise<void> {
  await apiClient.delete(`/api/v1/projects/${id}`);
}

/**
 * Archived Projects, read through `projects.restore`.
 *
 * A separate surface because it answers to a separate capability: widening
 * ordinary view to include soft-deleted rows would expose archived work to
 * everyone who can read Projects at all (D-093).
 */
export async function getArchivedProjects(
  query: ProjectListQuery,
): Promise<ArchivedProjectListPage> {
  const response = await apiClient.get<ArchivedProjectListPage>("/api/v1/projects/archived", {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: query.search === "" ? undefined : query.search,
    },
  });

  return response.data;
}

/**
 * Put an archived Project back.
 *
 * Restores the record and nothing else: the business status, the reference, the
 * Office, and the PIC are all exactly as they were. In particular a Project whose
 * status read `ARCHIVED` still reads `ARCHIVED` afterwards — restoring is not
 * reopening (D-093).
 */
export async function restoreProject(id: string): Promise<Project> {
  const response = await apiClient.post<{ data: Project }>(`/api/v1/projects/${id}/restore`);

  return response.data.data;
}
