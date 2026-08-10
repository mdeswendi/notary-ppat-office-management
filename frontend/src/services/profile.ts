import { apiClient } from "@/lib/api/client";
import type { Profile, ProfileUpdate } from "@/types/profile";

export const profileQueryKeys = {
  profile: ["profile"] as const,
};

export async function getProfile(): Promise<Profile> {
  const response = await apiClient.get<{ data: Profile }>("/api/v1/profile");

  return response.data.data;
}

/**
 * Update the authenticated user's own account.
 *
 * Name, phone, and language only — the backend refuses anything else outright
 * rather than ignoring it, so a rejected field surfaces as a 422 instead of a
 * silent no-op.
 */
export async function updateProfile(input: ProfileUpdate): Promise<Profile> {
  const response = await apiClient.patch<{ data: Profile }>("/api/v1/profile", input);

  return response.data.data;
}

/**
 * Persist the interface language.
 *
 * The single path both the header switcher and the profile page use, so the two
 * cannot drift into different persistence behaviour (D-064).
 */
export async function updatePreferredLocale(locale: string): Promise<Profile> {
  return updateProfile({ preferred_locale: locale });
}
