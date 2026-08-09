import { cookies } from "next/headers";

import type { CurrentUser } from "@/types/auth";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const APP_URL = process.env.NEXT_PUBLIC_APP_URL ?? "http://localhost:3000";

/**
 * Resolve the current user on the server by asking Laravel.
 *
 * Protection deliberately does not test for the presence of a session cookie:
 * a cookie can be stale, forged, or belong to an invalidated session, and its
 * existence proves nothing. The browser's cookies are forwarded to
 * `GET /api/v1/me` and Laravel decides. A 401 means anonymous.
 *
 * Returns null rather than throwing so callers can redirect.
 */
export async function fetchCurrentUser(): Promise<CurrentUser | null> {
  const cookieStore = await cookies();
  const cookieHeader = cookieStore.toString();

  if (cookieHeader === "") {
    return null;
  }

  const response = await fetch(`${API_URL}/api/v1/me`, {
    headers: {
      Accept: "application/json",
      Cookie: cookieHeader,
      // Sanctum decides between cookie and token authentication by matching
      // the Origin/Referer against its stateful domains. A browser sends this
      // automatically; this request is made by our own server on the browser's
      // behalf, so it has to state the SPA origin truthfully or the session
      // cookie is ignored and every request looks anonymous.
      Origin: APP_URL,
    },
    cache: "no-store",
  });

  if (response.status === 401) {
    return null;
  }

  if (!response.ok) {
    // Surfaced to the route error boundary, which shows a generic message.
    // The upstream body is never read, so nothing internal can leak.
    throw new Error(`Unexpected status ${response.status} from the identity endpoint.`);
  }

  const payload = (await response.json()) as { data: CurrentUser };

  return payload.data;
}
