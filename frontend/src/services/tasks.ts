import { apiClient } from "@/lib/api/client";
import type {
  Task,
  TaskComment,
  TaskCreateInput,
  TaskListPage,
  TaskListQuery,
  TaskOptions,
  TaskUpdateInput,
} from "@/types/task";

const ROOT = "/api/v1/tasks";

/**
 * Query keys for the Task surface.
 *
 * **Not keyed by domain**, unlike Matter: `tasks.*` is a single canonical
 * namespace with no Notary/PPAT split, so there is nothing for a key to separate.
 *
 * Comments live under the task's own detail branch, so invalidating one task's
 * conversation never refetches another's.
 */
export const taskQueryKeys = {
  all: () => ["tasks"] as const,
  list: (query: TaskListQuery) => ["tasks", "list", query] as const,
  detail: (id: string) => ["tasks", "detail", id] as const,
  comments: (id: string) => ["tasks", "detail", id, "comments"] as const,
  options: () => ["tasks", "options"] as const,
};

export async function getTasks(query: TaskListQuery): Promise<TaskListPage> {
  const blank = (value: string | undefined) => (value === "" ? undefined : value);

  const response = await apiClient.get<TaskListPage>(ROOT, {
    params: {
      page: query.page,
      per_page: query.per_page,
      search: blank(query.search),
      status: blank(query.status),
      priority: blank(query.priority),
      assigned_to: blank(query.assigned_to),
      created_by: blank(query.created_by),
      project_id: blank(query.project_id),
      matter_id: blank(query.matter_id),
      open: blank(query.open),
      overdue: blank(query.overdue),
      due_from: blank(query.due_from),
      due_to: blank(query.due_to),
      sort_by: query.sort_by,
      sort_direction: query.sort_direction,
    },
  });

  return response.data;
}

export async function getTask(id: string): Promise<Task> {
  const response = await apiClient.get<{ data: Task }>(`${ROOT}/${id}`);

  return response.data.data;
}

export async function getTaskOptions(): Promise<TaskOptions["data"]> {
  const response = await apiClient.get<TaskOptions>(`${ROOT}/options`);

  return response.data.data;
}

export async function createTask(input: TaskCreateInput): Promise<Task> {
  const response = await apiClient.post<{ data: Task }>(ROOT, input);

  return response.data.data;
}

export async function updateTask(id: string, input: TaskUpdateInput): Promise<Task> {
  const response = await apiClient.patch<{ data: Task }>(`${ROOT}/${id}`, input);

  return response.data.data;
}

/**
 * Hand the work over, or take it back.
 *
 * **`null` unassigns, and the key is always sent.** The endpoint requires it to be
 * present rather than merely nullable, so a malformed payload cannot quietly take
 * a task off somebody.
 */
export async function assignTask(id: string, assignedTo: string | null): Promise<Task> {
  const response = await apiClient.patch<{ data: Task }>(`${ROOT}/${id}/assignment`, {
    assigned_to: assignedTo,
  });

  return response.data.data;
}

export async function completeTask(id: string): Promise<Task> {
  const response = await apiClient.post<{ data: Task }>(`${ROOT}/${id}/complete`);

  return response.data.data;
}

/**
 * Put a completed Task back to work.
 *
 * Its own capability, `tasks.reopen`, separate from completing — an office may
 * well let more people close work than un-close it.
 */
export async function reopenTask(id: string): Promise<Task> {
  const response = await apiClient.post<{ data: Task }>(`${ROOT}/${id}/reopen`);

  return response.data.data;
}

/**
 * Call the work off.
 *
 * Answers to `tasks.delete`, because cancelling is what makes deletion available:
 * nothing still live may be removed.
 */
export async function cancelTask(id: string): Promise<Task> {
  const response = await apiClient.post<{ data: Task }>(`${ROOT}/${id}/cancel`);

  return response.data.data;
}

export async function deleteTask(id: string): Promise<void> {
  await apiClient.delete(`${ROOT}/${id}`);
}

export async function getTaskComments(id: string): Promise<TaskComment[]> {
  const response = await apiClient.get<{ data: TaskComment[] }>(`${ROOT}/${id}/comments`);

  return response.data.data;
}

/**
 * Record a remark.
 *
 * The author comes from the session; sending `user_id` is a 422, because a
 * comment must never be signable in somebody else's name.
 */
export async function addTaskComment(id: string, comment: string): Promise<TaskComment> {
  const response = await apiClient.post<{ data: TaskComment }>(`${ROOT}/${id}/comments`, {
    comment,
  });

  return response.data.data;
}
