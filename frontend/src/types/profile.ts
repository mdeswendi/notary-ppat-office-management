import type { UserOffice } from "@/types/user";

/**
 * The authenticated user's own account, from `GET /api/v1/profile`.
 *
 * Distinct from `CurrentUser` (the session and authorization contract) and from
 * `ManagedUser` (how an administrator sees somebody else). Three questions,
 * three shapes.
 *
 * `email`, `office`, and `roles` are present to display, never to edit — the
 * backend rejects them outright on `PATCH`.
 */
export type Profile = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  preferred_locale: string;
  last_login_at: string | null;
  office: UserOffice | null;
  roles: string[];
};

/**
 * Everything a person may change about themselves. Nothing else is accepted.
 */
export type ProfileUpdate = {
  name?: string;
  phone?: string | null;
  preferred_locale?: string;
};
