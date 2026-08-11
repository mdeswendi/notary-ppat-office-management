/**
 * The canonical permission catalogue, as served by `GET /api/v1/permissions`.
 *
 * Loaded from the backend, never listed in frontend source: the registry is the
 * single source of truth, and a second copy here would drift the moment either
 * side changed.
 */
export type PermissionCatalogue = {
  guard: string;
  groups: PermissionGroup[];
};

export type PermissionGroup = {
  group: string;
  permissions: CanonicalPermission[];
};

export type CanonicalPermission = {
  code: string;
  /** The scopes the backend will accept for this permission. Never includes TEAM. */
  allowed_scopes: string[];
  /** False when the registry declares it but `permissions:sync` has not been run. */
  synchronized: boolean;
  /** Registered and configurable, but no endpoint honours it yet. */
  deferred: boolean;
};

/** One configured grant: a permission and the Data Scope it applies at. */
export type PermissionGrant = {
  code: string;
  scope: string;
};

/**
 * A grant the resolver cannot honour — most often a package permission row with
 * no scope metadata. Shown rather than hidden, because it looks configured and
 * grants nothing.
 */
export type MalformedGrant = {
  code: string;
  scope: string | null;
  reason: string;
};

export type RolePermissions = {
  role: { id: number; name: string };
  permissions: PermissionGrant[];
  malformed: MalformedGrant[];
};

export type UserRoles = {
  user: { id: string; name: string };
  roles: { id: number; name: string }[];
};
