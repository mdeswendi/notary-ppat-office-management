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
 * Establish a session. Returns nothing — the identity contract is `getCurrentUser`.
 */
export async function login(credentials: LoginCredentials): Promise<void> {
  await getCsrfCookie();
  await apiClient.post("/login", credentials);
}

export async function getCurrentUser(): Promise<CurrentUser> {
  const response = await apiClient.get<{ data: CurrentUser }>("/api/v1/me");

  return response.data.data;
}

export async function logout(): Promise<void> {
  await apiClient.post("/logout");
}
