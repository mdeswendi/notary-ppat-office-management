import { createNavigation } from "next-intl/navigation";

import { routing } from "./routing";

/**
 * Locale-aware navigation helpers. Use these instead of the equivalents from
 * `next/link` and `next/navigation` so the active locale segment is carried
 * across navigations automatically.
 */
export const { Link, redirect, usePathname, useRouter, getPathname } = createNavigation(routing);
