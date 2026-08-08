import { getTranslations, setRequestLocale } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";

export default async function HomePage({ params }: PageProps<"/[locale]">) {
  const { locale } = await params;

  setRequestLocale(locale);

  const t = await getTranslations("common");

  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col justify-center gap-6 px-6 py-16">
      <h1 className="text-2xl font-semibold tracking-tight">{t("appName")}</h1>
      <p className="text-muted-foreground">{t("foundationNotice")}</p>
      <LocaleSwitcher />
    </main>
  );
}
