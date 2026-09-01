"use client";

import { useLocale, useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { useLocalePreference } from "@/features/profile/use-locale-preference";
import { Link, usePathname } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";
import { cn } from "@/lib/utils";

const localeLabelKeys = {
  id: "localeIndonesian",
  en: "localeEnglish",
} as const;

/**
 * What the control shows. The full language name stays as the accessible name,
 * so nothing is lost to a screen reader — see the note on sizing below.
 */
const localeCodes = {
  id: "ID",
  en: "EN",
} as const;

/**
 * Choose the interface language.
 *
 * The locale lives in the URL and nowhere else — no cookie, no `localStorage`,
 * no browser-language detection (D-020). What changes here is which URL you are
 * on, and, when signed in, which language you land in next time.
 *
 * **Signed in**, choosing a language is an explicit preference: the choice is
 * persisted to `preferred_locale` first, and only a successful save navigates
 * (D-064). If it fails, the page stays where it is and says so, rather than
 * switching the display while the stored preference silently disagrees.
 *
 * **Signed out** — the login page is public — it can only change the URL. There
 * is nowhere to persist a preference for somebody who has not identified
 * themselves, and inventing a cookie for it is exactly what D-020 rejected.
 *
 * Selecting the language already displayed is not always a no-op: somebody may
 * have typed `/en/...` while their stored preference is still `id`. Choosing EN
 * then genuinely records EN.
 *
 * ## It shows codes, not names
 *
 * It used to render "Bahasa Indonesia" and "English" as two full-width bordered
 * buttons, which made the heaviest thing in the header a control an office
 * touches perhaps twice a year. It is a two-position segment now.
 *
 * **The names are not lost.** Each option carries the full language name as its
 * `aria-label`, so a screen reader still hears "Bahasa Indonesia" rather than
 * the letters I and D — a two-letter code is exactly the kind of label that is
 * legible to the eye and useless to the ear.
 */
export function LocaleSwitcher() {
  const t = useTranslations("common");
  const activeLocale = useLocale();
  const pathname = usePathname();

  const { data: user } = useCurrentUser();
  const preference = useLocalePreference();

  const isAuthenticated = Boolean(user);

  return (
    <nav aria-label={t("language")}>
      <ul className="border-border bg-background inline-flex items-center rounded-md border p-0.5">
        {routing.locales.map((locale) => {
          const isActive = locale === activeLocale;
          const className = cn(
            "block rounded-[0.3rem] px-2 py-1 text-xs font-medium transition-colors",
            isActive
              ? "bg-secondary text-secondary-foreground"
              : "text-muted-foreground hover:text-foreground",
            preference.isPending && "opacity-60",
          );

          return (
            <li key={locale}>
              {isAuthenticated ? (
                <button
                  type="button"
                  lang={locale}
                  aria-label={t(localeLabelKeys[locale])}
                  aria-current={isActive ? "true" : undefined}
                  disabled={preference.isPending}
                  onClick={() => preference.mutate(locale)}
                  className={className}
                >
                  {localeCodes[locale]}
                </button>
              ) : (
                <Link
                  href={pathname}
                  locale={locale}
                  hrefLang={locale}
                  aria-label={t(localeLabelKeys[locale])}
                  aria-current={isActive ? "true" : undefined}
                  className={className}
                >
                  {localeCodes[locale]}
                </Link>
              )}
            </li>
          );
        })}
      </ul>

      {preference.isError ? (
        <p role="alert" className="text-destructive mt-2 text-xs">
          {t("languageSaveFailed")}
        </p>
      ) : null}
    </nav>
  );
}
