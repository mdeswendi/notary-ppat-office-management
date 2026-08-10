/**
 * The five canonical Data Scopes (`docs/02_MENU_AND_PERMISSIONS.md` section 22).
 *
 * One union for the whole frontend — a second, slightly different copy is how
 * the interface and the backend start disagreeing about what a scope is.
 *
 * These are **predicates, not levels**. There is no ordering here and no
 * comparison helper anywhere: `OFFICE` does not "include" `OWN`, and asking
 * whether a scope is wide enough is not a question this codebase answers.
 *
 * `TEAM` is present because the vocabulary is fixed. M1.6 forbids assigning it,
 * but legacy data can still carry it, and it is reported as itself rather than
 * reinterpreted as anything else.
 */
export type DataScope = "OWN" | "ASSIGNED" | "TEAM" | "OFFICE" | "ALL";

/**
 * Identity and effective authorization, from `GET /api/v1/me`.
 */
export type CurrentUser = {
  /**
   * ULID. An opaque identifier — never parse it, sort by it, or infer
   * anything from its structure.
   */
  id: string;
  name: string;
  email: string;
  preferred_locale: string;
  /**
   * Role names, for display only.
   *
   * **Never decide visibility from these.** No `roles.includes("SUPER_ADMIN")`,
   * no name comparisons anywhere — that is what `permissions` is for, and the
   * backend does not authorize by name either.
   */
  roles: string[];
  /**
   * Canonical permission codes this account **effectively holds**, resolved by
   * the backend's `EffectiveAccessResolver`.
   *
   * Denied permissions are absent. Direct package grants, stale codes, grants
   * missing Data Scope, expired overrides, and malformed overrides never appear.
   * Ordered canonically, so the payload is stable.
   */
  permissions: string[];
  /**
   * The exact effective Data Scope set for each granted permission, in
   * documentation order. Keys match `permissions` exactly.
   */
  permission_scopes: Record<string, DataScope[]>;
};

export type LoginCredentials = {
  email: string;
  password: string;
  remember: boolean;
};
