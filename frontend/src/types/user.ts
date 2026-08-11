/**
 * A user account as User Management sees it.
 *
 * Distinct from `CurrentUser` in `types/auth.ts`, which answers "who am I".
 * This is the administrative view of somebody else's account, and deliberately
 * carries no roles or permissions — capability is configured in a later
 * milestone.
 */
export type ManagedUser = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  preferred_locale: string;
  last_login_at: string | null;
  created_at: string | null;
  updated_at: string | null;
  office: UserOffice | null;
};

export type UserOffice = {
  id: string;
  code: string;
  name: string;
};

export type UserListMeta = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type UserListPage = {
  data: ManagedUser[];
  meta: UserListMeta;
};

export type UserListQuery = {
  page: number;
  search: string;
};
