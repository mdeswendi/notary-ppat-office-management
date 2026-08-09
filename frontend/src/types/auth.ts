/**
 * Identity fields returned by `GET /api/v1/me`.
 *
 * Roles and permissions are deliberately absent: M0.8 owns authorization, and
 * the backend remains the security boundary regardless of what is exposed here.
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
   * Role names, for display and administration only. Never use these to
   * decide whether a capability is available — see `permissions`.
   */
  roles: string[];
  /**
   * Effective permission names, already resolved by the backend across direct
   * grants and role inheritance. The browser never recomputes inheritance.
   */
  permissions: string[];
};

export type LoginCredentials = {
  email: string;
  password: string;
  remember: boolean;
};
