import type { ReactNode } from "react";
import { HydrationBoundary, QueryClient, dehydrate } from "@tanstack/react-query";

import { AppShell } from "@/components/layout/app-shell";
import { redirect } from "@/i18n/navigation";
import { fetchCurrentUser } from "@/lib/auth/session";
import { authQueryKeys } from "@/services/auth";

/**
 * Layout for every authenticated application page.
 *
 * `(app)` is a route group, so it does not appear in the URL: pages below it
 * stay at `/id/dashboard`, not `/id/app/dashboard`.
 *
 * The session is verified here, once, by asking Laravel — never by looking for
 * a cookie, which proves nothing. Putting the check in the layout means future
 * pages inherit it instead of each repeating a slightly different version, and
 * it keeps the redirect a real HTTP redirect rather than something resolved in
 * the browser.
 *
 * Login deliberately lives outside this group, so the sign-in screen never
 * renders the authenticated shell.
 *
 * No `loading.tsx` is added at or above this boundary. In M0.7 a locale-level
 * loading file wrapped the protected route in a Suspense boundary, which made
 * Next.js stream a 200 with a skeleton and deliver the redirect inside the
 * stream — anonymous protection stopped being verifiable over HTTP. See D-022.
 */
export default async function AuthenticatedLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  const user = await fetchCurrentUser();

  if (user === null) {
    // Returned rather than called bare: `redirect` is typed `never`, but
    // TypeScript does not narrow through a destructured const.
    return redirect({ href: "/login", locale });
  }

  // Seed the same ["auth", "me"] cache the login flow writes, so client
  // components such as PermissionGuard read the user immediately instead of
  // refetching what the server already has. One source of truth, no context
  // mirror, nothing in browser storage.
  const queryClient = new QueryClient();
  queryClient.setQueryData(authQueryKeys.me, user);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <AppShell user={user}>{children}</AppShell>
    </HydrationBoundary>
  );
}
