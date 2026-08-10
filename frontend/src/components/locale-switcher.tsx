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
      <ul className="flex items-center gap-2">
        {routing.locales.map((locale) => {
          const isActive = locale === activeLocale;
          const className = cn(
            "rounded-md border px-3 py-1.5 text-sm transition-colors",
            isActive
              ? "border-foreground text-foreground font-medium"
              : "border-border text-muted-foreground hover:text-foreground hover:border-foreground",
            preference.isPending && "opacity-60",
          );

          return (
            <li key={locale}>
              {isAuthenticated ? (
                <button
                  type="button"
                  lang={locale}
                  aria-current={isActive ? "true" : undefined}
                  disabled={preference.isPending}
                  onClick={() => preference.mutate(locale)}
                  className={className}
                >
                  {t(localeLabelKeys[locale])}
                </button>
              ) : (
                <Link
                  href={pathname}
                  locale={locale}
                  hrefLang={locale}
                  aria-current={isActive ? "true" : undefined}
                  className={className}
                >
                  {t(localeLabelKeys[locale])}
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
