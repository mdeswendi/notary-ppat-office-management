/**
 * A role definition, as returned by `GET /api/v1/roles`.
 *
 * `id` is the package's own auto-incrementing integer rather than a ULID —
 * `roles` is a third-party table whose key type stays as the package defines it.
 * Treat it as an opaque handle: nothing may infer ordering, age, or count from
 * its value.
 *
 * There is deliberately no permission list here. M1.4 manages role records; what
 * a role can do is configured in a later milestone.
 */
export type Role = {
  id: number;
  name: string;
  guard_name: string;
  created_at: string | null;
  updated_at: string | null;
};
