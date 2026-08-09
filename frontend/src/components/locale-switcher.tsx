"use client";

import { useLocale, useTranslations } from "next-intl";

import { Link, usePathname } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";

/**
 * Minimal locale switch for the M0.5 foundation.
 *
 * The locale lives in the URL, never in browser storage. `usePathname()`
 * returns the path without its locale prefix, so linking to the same pathname
 * under a different locale replaces only the locale segment and preserves the
 * logical route — `/id/projects` becomes `/en/projects` once such routes exist.
 */
const localeLabelKeys = {
  id: "localeIndonesian",
  en: "localeEnglish",
} as const;

export function LocaleSwitcher() {
  const t = useTranslations("common");
  const activeLocale = useLocale();
  const pathname = usePathname();

  return (
    <nav aria-label={t("language")}>
      <ul className="flex items-center gap-2">
        {routing.locales.map((locale) => {
          const isActive = locale === activeLocale;

          return (
            <li key={locale}>
              <Link
                href={pathname}
                locale={locale}
                hrefLang={locale}
                aria-current={isActive ? "true" : undefined}
                className={
                  isActive
                    ? "border-foreground text-foreground rounded-md border px-3 py-1.5 text-sm font-medium"
                    : "border-border text-muted-foreground hover:text-foreground hover:border-foreground rounded-md border px-3 py-1.5 text-sm transition-colors"
                }
              >
                {t(localeLabelKeys[locale])}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
