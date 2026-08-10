"use client";

import type { ReactNode } from "react";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { can, canWithScope } from "@/lib/permissions/can";
import type { DataScope } from "@/types/auth";

type PermissionGuardProps = {
  /**
   * A `resource.action` permission name. Callers pass it in; no permission
   * name is ever hardcoded inside this component.
   */
  permission: string;
  /**
   * An exact Data Scope the permission must include. Used by deployment-global
   * controls, which require `ALL` specifically.
   *
   * Exact membership, never a comparison: `{OFFICE}` does not satisfy `ALL`,
   * and `{OFFICE, ALL}` does because `ALL` is present.
   */
  scope?: DataScope;
  children: ReactNode;
  /** Rendered instead of `children` when the permission is absent. */
  fallback?: ReactNode;
};

/**
 * Renders `children` only when the current user effectively holds `permission`.
 *
 * PERMISSION GUARDS ARE PRESENTATION ONLY. This keeps the interface honest about
 * what a person can do — it is not a security boundary and must never be treated
 * as one. Anyone can edit client state and reveal the markup; doing so grants no
 * capability, because every protected action is authorized again by a backend
 * Policy. See CLAUDE.md section 28.
 *
 * Checks effective permissions and scopes, never roles: authorization must not
 * depend on a role name (D-032, D-045).
 *
 * Since M1.7 the underlying data is the backend's own effective projection
 * (D-062), so a control shown here corresponds to access the resolver actually
 * grants — and one hidden here corresponds to access it actually refuses.
 *
 * While the current user is still loading, nothing is rendered — showing a
 * control and then withdrawing it reads as a bug.
 */
export function PermissionGuard({
  permission,
  scope,
  children,
  fallback = null,
}: PermissionGuardProps) {
  const { data: user, isPending } = useCurrentUser();

  const permitted = scope ? canWithScope(user, permission, scope) : can(user, permission);

  if (isPending || !permitted) {
    return <>{fallback}</>;
  }

  return <>{children}</>;
}
