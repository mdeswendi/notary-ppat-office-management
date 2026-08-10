import { routing } from "@/i18n/routing";

/**
 * The locale to land in after signing in.
 *
 * A stored `preferred_locale` is data, and data can be wrong — a row predating
 * the validation, an import, a hand-edited record. Feeding an unrecognized value
 * into the router would produce a path with no matching route, so anything the
 * routing configuration does not know falls back to the canonical default rather
 * than being trusted (D-069).
 *
 * Reading a bad value **never repairs it**. `/me` and login are read paths, and
 * writing to the database as a side effect of a page load is how a "fix" becomes
 * impossible to explain later. Correcting the row is the user's own explicit
 * choice, through the language switcher.
 */
export function landingLocale(
  preferred: string | null | undefined,
): (typeof routing.locales)[number] {
  const supported = routing.locales as readonly string[];

  if (typeof preferred === "string" && supported.includes(preferred)) {
    return preferred as (typeof routing.locales)[number];
  }

  return routing.defaultLocale;
}
