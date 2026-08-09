import createMiddleware from "next-intl/middleware";

import { routing } from "./i18n/routing";

/**
 * Locale negotiation and prefixing.
 *
 * This is `proxy.ts`, not `middleware.ts`: Next.js 16.3 deprecates the
 * `middleware` file convention in favour of `proxy` and warns on every build.
 * next-intl still publishes the handler as `next-intl/middleware`, but it is
 * a plain `(NextRequest) => NextResponse`, which is exactly what the `proxy`
 * convention expects — so only the file name changes.
 */
export default createMiddleware(routing);

export const config = {
  // Skip Next.js internals and any path carrying a file extension, so only
  // application routes are given a locale prefix.
  matcher: "/((?!api|_next|_vercel|.*\\..*).*)",
};
