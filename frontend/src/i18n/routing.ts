import { defineRouting } from "next-intl/routing";

/**
 * Canonical locale configuration.
 *
 * Indonesian is the default and primary UI language. Route segments stay in
 * English in both locales — `/id/projects`, never `/id/proyek`.
 * See docs/05_I18N_LEGAL_TERMINOLOGY.md sections 2 and 4.
 */
export const routing = defineRouting({
  locales: ["id", "en"],
  defaultLocale: "id",

  // The URL is the single source of truth for the active locale.
  //
  // next-intl otherwise negotiates the locale from the `accept-language`
  // header and a cookie, which makes `/` non-deterministic: an English
  // browser would land on `/en` and Indonesian would stop being the real
  // default. Disabling detection makes `/` always resolve to `/id`.
  //
  // Remembering a person's language belongs to their profile
  // (`preferred_locale`) in a later identity milestone, not to a header guess.
  localeDetection: false,

  // With detection off the NEXT_LOCALE cookie is still written but never read.
  // A cookie that looks authoritative and is not would mislead the next reader,
  // so it is switched off rather than left inert.
  localeCookie: false,
});

export type AppLocale = (typeof routing.locales)[number];
