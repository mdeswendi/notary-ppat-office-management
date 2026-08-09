import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { notFound } from "next/navigation";
import { NextIntlClientProvider, hasLocale } from "next-intl";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { routing } from "@/i18n/routing";

import "../globals.css";

/**
 * Inter is the typeface named in docs/04_UI_DESIGN_SYSTEM.md section 4.
 * Self-hosted through next/font, so no external font request is made at
 * runtime. Resolves O-014.
 */
const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
  display: "swap",
});

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "common" });

  return {
    title: t("appName"),
  };
}

export default async function LocaleLayout({ children, params }: LayoutProps<"/[locale]">) {
  const { locale } = await params;

  // The [locale] segment also catches unknown paths, so an unsupported value
  // must 404 rather than silently render as a third locale.
  if (!hasLocale(routing.locales, locale)) {
    notFound();
  }

  // Opts this route into static rendering.
  setRequestLocale(locale);

  return (
    <html lang={locale} className={`${inter.variable} h-full antialiased`}>
      {/* Background and text colour come from the base layer in globals.css. */}
      <body className="flex min-h-full flex-col">
        <NextIntlClientProvider>{children}</NextIntlClientProvider>
      </body>
    </html>
  );
}
