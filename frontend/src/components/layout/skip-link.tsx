"use client";

import { useTranslations } from "next-intl";

/** Keyboard shortcut past the repeated application navigation. */
export function SkipLink() {
  const t = useTranslations("common");

  return (
    <a
      href="#main-content"
      className="bg-background text-foreground focus-visible:ring-ring sr-only z-50 rounded-md px-3 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
    >
      {t("skipToContent")}
    </a>
  );
}
