import { hasLocale } from "next-intl";
import { getRequestConfig } from "next-intl/server";

import { routing } from "./routing";

/**
 * Per-request i18n configuration.
 *
 * `requestLocale` normally comes from the `[locale]` segment matched by the
 * middleware, but it can be undefined or invalid — that segment also catches
 * unknown paths. Anything unrecognised falls back to the default locale so a
 * malformed URL can never produce a third locale.
 */
export default getRequestConfig(async ({ requestLocale }) => {
  const requested = await requestLocale;
  const locale = hasLocale(routing.locales, requested) ? requested : routing.defaultLocale;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
