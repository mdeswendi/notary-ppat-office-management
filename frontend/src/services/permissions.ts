import { apiClient } from "@/lib/api/client";
import type {
  PermissionCatalogue,
  PermissionGrant,
  RolePermissions,
  UserRoles,
} from "@/types/permission";

export const permissionQueryKeys = {
  catalogue: ["permissions", "catalogue"] as const,
  rolePermissions: (roleId: number) => ["permissions", "role", roleId] as const,
  userRoles: (userId: string) => ["permissions", "user", userId] as const,
};

/**
 * The canonical catalogue, with the scopes each permission may be assigned at.
 *
 * The allowed scopes come from the same rules the write endpoint enforces, so
 * the matrix cannot offer something that would then be rejected.
 */
export async function getPermissionCatalogue(): Promise<PermissionCatalogue> {
  const response = await apiClient.get<{ data: PermissionCatalogue }>("/api/v1/permissions");

  return response.data.data;
}

export async function getRolePermissions(roleId: number): Promise<RolePermissions> {
  const response = await apiClient.get<{ data: RolePermissions }>(
    `/api/v1/roles/${roleId}/permissions`,
  );

  return response.data.data;
}

/**
 * Complete replacement: omitted permissions are revoked. Answers 409 if the save
 * would leave nobody able to administer authorization.
 */
export async function saveRolePermissions(
  roleId: number,
  permissions: PermissionGrant[],
): Promise<RolePermissions> {
  const response = await apiClient.put<{ data: RolePermissions }>(
    `/api/v1/roles/${roleId}/permissions`,
    { permissions },
  );

  return response.data.data;
}

export async function getUserRoles(userId: string): Promise<UserRoles> {
  const response = await apiClient.get<{ data: UserRoles }>(`/api/v1/users/${userId}/roles`);

  return response.data.data;
}

/**
 * Complete replacement of role membership. Guarded by `permissions.assign`, not
 * by the user-management capability — granting a role changes what somebody can
 * do. Answers 409 if it would remove the last authorization administrator.
 */
export async function saveUserRoles(userId: string, roleIds: number[]): Promise<UserRoles> {
  const response = await apiClient.put<{ data: UserRoles }>(`/api/v1/users/${userId}/roles`, {
    role_ids: roleIds,
  });

  return response.data.data;
}
