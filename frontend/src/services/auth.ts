import { apiClient } from "@/lib/api/client";
import type { CurrentUser, LoginCredentials } from "@/types/auth";

/**
 * Stable query key for the current user, per docs/10_M0_FOUNDATION.md section 40.
 */
export const authQueryKeys = {
  me: ["auth", "me"] as const,
};

/**
 * Prime the CSRF cookie. Must run before the first state-changing request.
 */
export async function getCsrfCookie(): Promise<void> {
  await apiClient.get("/sanctum/csrf-cookie");
}

/**
 * Submit the password step.
 *
 * Two outcomes, and the difference matters. Without two-factor the session now
 * exists and the identity contract is `getCurrentUser`. With it, **no session
 * was created**: the backend answers 202 with `two_factor: true`, and the
 * caller must send the second factor before anything is authenticated.
 *
 * The return value says which happened. Treating a 202 as success would leave
 * the interface believing somebody is signed in when the server does not agree.
 */
export async function login(credentials: LoginCredentials): Promise<{ twoFactorRequired: boolean }> {
  await getCsrfCookie();

  const response = await apiClient.post<{ two_factor?: boolean } | null>("/login", credentials);

  return { twoFactorRequired: response.data?.two_factor === true };
}

export async function getCurrentUser(): Promise<CurrentUser> {
  const response = await apiClient.get<{ data: CurrentUser }>("/api/v1/me");

  return response.data.data;
}

export async function logout(): Promise<void> {
  await apiClient.post("/logout");
}
