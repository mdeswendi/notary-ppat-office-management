"use client";

import type { ReactNode } from "react";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { can } from "@/lib/permissions/can";

type PermissionGuardProps = {
  /**
   * A `resource.action` permission name. Callers pass it in; no permission
   * name is ever hardcoded inside this component.
   */
  permission: string;
  children: ReactNode;
  /** Rendered instead of `children` when the permission is absent. */
  fallback?: ReactNode;
};

/**
 * Renders `children` only when the current user holds `permission`.
 *
 * PERMISSION GUARDS ARE PRESENTATION ONLY. This is a convenience for keeping
 * the interface honest about what a person can do — it is not a security
 * boundary and must never be treated as one. Anyone can edit client state and
 * reveal the markup; doing so grants no capability, because every protected
 * action is authorized again by a backend policy or Form Request. See
 * CLAUDE.md section 28 and docs/10_M0_FOUNDATION.md section 38.
 *
 * Checks permissions, never roles: `docs/02_MENU_AND_PERMISSIONS.md` section 1
 * requires that authorization not depend on role names.
 *
 * While the current user is still loading, nothing is rendered — showing a
 * control and then withdrawing it reads as a bug.
 */
export function PermissionGuard({ permission, children, fallback = null }: PermissionGuardProps) {
  const { data: user, isPending } = useCurrentUser();

  if (isPending || !can(user, permission)) {
    return <>{fallback}</>;
  }

  return <>{children}</>;
}
