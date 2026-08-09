import { apiClient } from "@/lib/api/client";
import type { Role } from "@/types/role";

/**
 * Query keys for role data, following the convention in
 * docs/06_API_CONVENTIONS.md section 30.
 */
export const roleQueryKeys = {
  all: ["roles"] as const,
};

export async function getRoles(): Promise<Role[]> {
  const response = await apiClient.get<{ data: Role[] }>("/api/v1/roles");

  return response.data.data;
}

export async function createRole(name: string): Promise<Role> {
  const response = await apiClient.post<{ data: Role }>("/api/v1/roles", { name });

  return response.data.data;
}

export async function renameRole(id: number, name: string): Promise<Role> {
  const response = await apiClient.patch<{ data: Role }>(`/api/v1/roles/${id}`, { name });

  return response.data.data;
}

/**
 * Answers 409 when the role is still held by somebody. The backend refuses
 * rather than detaching people silently, so the interface reports the conflict
 * instead of retrying.
 */
export async function deleteRole(id: number): Promise<void> {
  await apiClient.delete(`/api/v1/roles/${id}`);
}
