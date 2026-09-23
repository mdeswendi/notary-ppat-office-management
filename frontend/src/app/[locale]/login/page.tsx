import Image from "next/image";
import { Scale } from "lucide-react";
import { getTranslations, setRequestLocale } from "next-intl/server";

import { LocaleSwitcher } from "@/components/locale-switcher";
import { LoginForm } from "@/features/auth/login-form";

import styles from "./login.module.css";

export default async function LoginPage({ params }: PageProps<"/[locale]/login">) {
  const { locale } = await params;

  setRequestLocale(locale);

  const t = await getTranslations("auth");

  return (
    <main className={styles.page}>
      <div className={styles.content}>
        <header className={styles.brand}>
          <span className={styles.brandMark} aria-hidden="true">
            <Scale className="size-9 stroke-[1.35]" />
          </span>
          <p className={styles.officeName}>
            {t("officeType")}
            <br />
            <span>{t("officeName")}</span>
          </p>
          <span className={styles.brandDivider} aria-hidden="true" />
          <p className={styles.officeDescriptor}>{t("officeDescriptor")}</p>
          <p className={styles.officeValues}>{t("officeValues")}</p>
        </header>

        <div className={styles.layout}>
          <aside className={styles.introduction}>
            <p className={styles.introductionTitle}>
              {t("introLineOne")}
              <br />
              {t("introLineTwo")}
            </p>
            <span className={styles.introductionDivider} aria-hidden="true" />
            <p className={styles.introductionBody}>{t("introDescription")}</p>
          </aside>

          <section className={styles.loginCard} aria-labelledby="login-title">
            <div className="mb-7">
              <h1
                id="login-title"
                className="text-foreground text-3xl font-semibold tracking-tight"
              >
                {t("signIn")}
              </h1>
              <p className="text-muted-foreground mt-2 text-sm leading-relaxed">
                {t("signInSubtitle")}
              </p>
            </div>
            <LoginForm />
            <div className="mt-7 flex justify-center">
              <LocaleSwitcher />
            </div>
          </section>

          <figure className={styles.illustration}>
            <Image
              src="/illustrations/login-scales-books.png"
              alt={t("illustrationAlt")}
              width={1024}
              height={1536}
              priority
              sizes="(max-width: 950px) 0px, (max-width: 1200px) 260px, 380px"
              className="h-auto w-full"
            />
          </figure>
        </div>

        <footer className={styles.credit}>{t("developerCredit")}</footer>
      </div>
    </main>
  );
}
