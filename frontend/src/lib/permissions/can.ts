import type { CurrentUser } from "@/types/auth";

/**
 * Does this user hold the given permission?
 *
 * Exact string comparison against the effective permission names the backend
 * already resolved. No wildcard expansion, no role fallback, no per-resource
 * special cases — anything cleverer would be a second authorization engine
 * that could disagree with the real one.
 *
 * This is for presentation only. Backend policies and Form Requests remain the
 * security boundary; hiding a button grants nothing and revealing one takes
 * nothing away.
 */
export function can(user: CurrentUser | null | undefined, permission: string): boolean {
  if (!user) {
    return false;
  }

  return user.permissions.includes(permission);
}
