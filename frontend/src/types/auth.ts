/**
 * Identity fields returned by `GET /api/v1/me`.
 *
 * Roles and permissions are deliberately absent: M0.8 owns authorization, and
 * the backend remains the security boundary regardless of what is exposed here.
 */
export type CurrentUser = {
  id: number;
  name: string;
  email: string;
  preferred_locale: string;
};

export type LoginCredentials = {
  email: string;
  password: string;
  remember: boolean;
};
