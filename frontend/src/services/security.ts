import { apiClient } from "@/lib/api/client";
import type {
  ChangePasswordInput,
  RequestEmailChangeInput,
  ResetPasswordInput,
  SecurityOverview,
  TwoFactorChallengeInput,
  TwoFactorEnrolment,
  UserSession,
} from "@/types/security";

export const securityQueryKeys = {
  overview: ["security", "overview"] as const,
  sessions: ["security", "sessions"] as const,
  userSessions: (userId: string) => ["security", "users", userId, "sessions"] as const,
};

export async function getSecurityOverview(): Promise<SecurityOverview> {
  const response = await apiClient.get<{ data: SecurityOverview }>("/api/v1/security");

  return response.data.data;
}

/**
 * Change your own password.
 *
 * Returns nothing. The backend answers 204, and every other session for the
 * account is revoked server-side — the caller's own survives.
 */
export async function changePassword(input: ChangePasswordInput): Promise<void> {
  await apiClient.put("/api/v1/security/password", input);
}

/**
 * Ask to move the account to a different address.
 *
 * The current address does not change here. A link goes to the new mailbox, and
 * the overview comes back carrying `pending_email` so the interface can say
 * which mailbox to check.
 */
export async function requestEmailChange(
  input: RequestEmailChangeInput,
): Promise<SecurityOverview> {
  const response = await apiClient.post<{ data: SecurityOverview }>(
    "/api/v1/security/email",
    input,
  );

  return response.data.data;
}

export async function verifyEmailChange(token: string): Promise<SecurityOverview> {
  const response = await apiClient.post<{ data: SecurityOverview }>(
    "/api/v1/security/email/verify",
    {
      token,
    },
  );

  return response.data.data;
}

export async function cancelEmailChange(): Promise<SecurityOverview> {
  const response = await apiClient.delete<{ data: SecurityOverview }>("/api/v1/security/email");

  return response.data.data;
}

/*
 * Two-factor authentication.
 */

/**
 * Begin enrolment.
 *
 * The response carries the only copy of the secret that will ever leave the
 * server. It is returned to the caller and deliberately not cached — see
 * `TwoFactorEnrolment`.
 */
export async function beginTwoFactorEnrolment(): Promise<TwoFactorEnrolment> {
  const response = await apiClient.post<{ data: TwoFactorEnrolment }>(
    "/api/v1/security/two-factor",
  );

  return response.data.data;
}

/**
 * Confirm enrolment. Returns the recovery codes — shown once, then gone.
 */
export async function confirmTwoFactorEnrolment(code: string): Promise<string[]> {
  const response = await apiClient.post<{ data: { recovery_codes: string[] } }>(
    "/api/v1/security/two-factor/confirm",
    { code },
  );

  return response.data.data.recovery_codes;
}

export async function disableTwoFactor(currentPassword: string): Promise<void> {
  await apiClient.delete("/api/v1/security/two-factor", {
    data: { current_password: currentPassword },
  });
}

export async function regenerateRecoveryCodes(currentPassword: string): Promise<string[]> {
  const response = await apiClient.post<{ data: { recovery_codes: string[] } }>(
    "/api/v1/security/two-factor/recovery-codes",
    { current_password: currentPassword },
  );

  return response.data.data.recovery_codes;
}

/*
 * Sessions.
 */

export async function getOwnSessions(): Promise<UserSession[]> {
  const response = await apiClient.get<{ data: UserSession[] }>("/api/v1/security/sessions");

  return response.data.data;
}

export async function revokeOwnSession(key: string): Promise<void> {
  await apiClient.delete(`/api/v1/security/sessions/${encodeURIComponent(key)}`);
}

export async function revokeOtherSessions(currentPassword: string): Promise<void> {
  await apiClient.delete("/api/v1/security/sessions/others", {
    data: { current_password: currentPassword },
  });
}

/*
 * Unauthenticated flows. These run before a session exists, so they live
 * alongside the login calls rather than behind the API's authenticated prefix.
 */

/**
 * Supply the second factor after the password step.
 *
 * The session is created by this call, not by `login`, which returns
 * `two_factor: true` and nothing else for an account that requires it.
 */
export async function submitTwoFactorChallenge(input: TwoFactorChallengeInput): Promise<void> {
  await apiClient.post("/login/two-factor-challenge", input);
}

/**
 * Finish a password reset from an emailed link.
 *
 * No session results. The user is sent to sign in again — which for an account
 * with two-factor means meeting its second factor, the reason this deliberately
 * does not log anybody in.
 */
export async function resetPassword(input: ResetPasswordInput): Promise<void> {
  await apiClient.post("/password-reset", input);
}

/*
 * Administering somebody else's security. Each call is authorized server-side
 * against its own canonical permission; reaching these functions proves nothing.
 */

export async function sendUserPasswordReset(userId: string): Promise<void> {
  await apiClient.post(`/api/v1/users/${userId}/password-reset`);
}

export async function getUserSessions(userId: string): Promise<UserSession[]> {
  const response = await apiClient.get<{ data: UserSession[] }>(`/api/v1/users/${userId}/sessions`);

  return response.data.data;
}

export async function revokeUserSessions(userId: string): Promise<void> {
  await apiClient.delete(`/api/v1/users/${userId}/sessions`);
}

/**
 * Remove a user's two-factor authentication.
 *
 * Removal only. There is no counterpart that reads or sets somebody else's
 * secret, because no such endpoint exists.
 */
export async function disableUserTwoFactor(userId: string, reason?: string): Promise<void> {
  await apiClient.delete(`/api/v1/users/${userId}/two-factor`, {
    data: reason ? { reason } : {},
  });
}
