/**
 * Account security, as the API describes it.
 *
 * Note what is absent, and note that it is absent by design rather than by
 * oversight: there is no field here for a two-factor secret, a recovery code, a
 * pending-email token, or a session identifier. The backend never sends them
 * outside the one-shot enrolment responses below, so there is no type to model
 * them with — which means no component can accidentally render one.
 */
export type SecurityOverview = {
  email: string;
  /** The address awaiting confirmation, or null. The current one is unchanged. */
  pending_email: string | null;
  pending_email_requested_at: string | null;
  two_factor_enabled: boolean;
  two_factor_confirmed_at: string | null;
  /** A count, never the codes themselves. */
  recovery_codes_remaining: number;
  last_login_at: string | null;
};

/**
 * A signed-in device.
 *
 * `key` is a SHA-256 digest of the session id, not the id. It names a row for
 * revocation and is useless for impersonation.
 */
export type UserSession = {
  key: string;
  current: boolean;
  ip_address: string | null;
  device: string | null;
  last_active_at: string | null;
};

/**
 * The enrolment payload — returned once, when setup begins, and never readable
 * again.
 *
 * Deliberately not cached in TanStack Query and never written to storage: it is
 * held in component state for as long as the dialog is open and discarded when
 * it closes.
 */
export type TwoFactorEnrolment = {
  secret: string;
  provisioning_uri: string;
  /** Server-rendered inline SVG, so the browser needs no QR library. */
  qr_code_svg: string;
};

export type ChangePasswordInput = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export type RequestEmailChangeInput = {
  current_password: string;
  email: string;
};

export type ResetPasswordInput = {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
};

export type TwoFactorChallengeInput = {
  code?: string;
  recovery_code?: string;
};
