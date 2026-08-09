"use client";

import { useQuery } from "@tanstack/react-query";

import { authQueryKeys, getCurrentUser } from "@/services/auth";
import type { CurrentUser } from "@/types/auth";

/**
 * The current user from the shared `["auth", "me"]` query.
 *
 * Deliberately the same cache entry the login flow seeds — there is no second
 * user store, no context mirror, and nothing in browser storage. A 401 is a
 * real answer here, so the query does not retry.
 */
export function useCurrentUser() {
  return useQuery<CurrentUser>({
    queryKey: authQueryKeys.me,
    queryFn: getCurrentUser,
  });
}
