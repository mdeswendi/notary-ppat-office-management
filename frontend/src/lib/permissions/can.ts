import type { CurrentUser, DataScope } from "@/types/auth";

/**
 * Does this user effectively hold the given permission, at any scope?
 *
 * Exact string comparison against the effective permissions the backend
 * resolved. No wildcard expansion, no role fallback, no per-resource special
 * cases — anything cleverer would be a second authorization engine that could
 * disagree with the real one.
 *
 * **Presentation only.** Backend Policies remain the security boundary: hiding a
 * button grants nothing and revealing one takes nothing away.
 */
export function can(user: CurrentUser | null | undefined, permission: string): boolean {
  if (!user) {
    return false;
  }

  return user.permissions.includes(permission);
}

/**
 * Does this user hold the permission **at that exact scope**?
 *
 * Exact membership, never a comparison. `{OFFICE}` does not satisfy a required
 * `ALL`, and `{OFFICE, ALL}` does — because `ALL` is present, not because it
 * outranks anything (D-028). There is deliberately no "wide enough" helper.
 *
 * Needed because some capabilities are deployment-global: role and permission
 * administration require `ALL`, since a Role definition has no office or owner
 * for a narrower predicate to match (D-044, D-054).
 */
export function canWithScope(
  user: CurrentUser | null | undefined,
  permission: string,
  scope: DataScope,
): boolean {
  return scopesFor(user, permission).includes(scope);
}

/**
 * The effective scopes for a permission, or an empty list when it is not held.
 */
export function scopesFor(user: CurrentUser | null | undefined, permission: string): DataScope[] {
  if (!user || !can(user, permission)) {
    return [];
  }

  return user.permission_scopes[permission] ?? [];
}
