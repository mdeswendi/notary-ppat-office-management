"use client";

import Image from "next/image";
import { useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import { resolveOfficeIdentity } from "@/lib/office-identity";

/**
 * Visual orientation for the working day.
 *
 * The illustration is decorative and carries no record data. The text stays
 * bilingual, and the factual panels below remain the only place where counts
 * and operational state are disclosed.
 */
export function DashboardHero() {
  const t = useTranslations("dashboard");
  const { data: user } = useCurrentUser();
  const officeIdentity = resolveOfficeIdentity(user?.office) ?? t("officeIdentityFallback");

  return (
    <section className="border-border/80 bg-card grid min-h-64 overflow-hidden rounded-xl border shadow-sm lg:grid-cols-[minmax(22rem,1.05fr)_minmax(24rem,1.35fr)_minmax(13rem,0.65fr)]">
      <div className="relative z-10 flex flex-col justify-center px-5 py-8 sm:px-8 lg:pr-2">
        <h1 className="max-w-2xl font-serif text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
          {t("welcome")}
        </h1>
        <p className="text-primary mt-1 max-w-2xl font-serif text-xl font-semibold tracking-tight text-balance sm:text-2xl">
          {officeIdentity}
        </p>
      </div>

      <div className="relative min-h-52 overflow-hidden sm:min-h-60 lg:min-h-64">
        <Image
          src="/illustrations/ppat-practice-hero-v3.png"
          alt=""
          fill
          priority
          sizes="(min-width: 1280px) 38vw, (min-width: 1024px) 44vw, 100vw"
          className="object-cover object-center"
        />
        <div
          aria-hidden="true"
          className="from-card absolute inset-y-0 left-0 w-16 bg-linear-to-r to-transparent"
        />
        <div
          aria-hidden="true"
          className="from-card absolute inset-y-0 right-0 w-16 bg-linear-to-l to-transparent"
        />
      </div>

      <div className="flex items-center px-5 pt-1 pb-8 sm:px-8 lg:px-5 lg:py-8">
        <div className="flex items-start gap-3 lg:flex-col lg:items-end lg:text-right">
          <span className="bg-brand-gold mt-2 h-0.5 w-12 shrink-0 lg:order-2" aria-hidden="true" />
          <p className="text-muted-foreground max-w-xs text-sm leading-relaxed italic sm:text-base">
            “{t("practiceMotto")}”
          </p>
        </div>
      </div>
    </section>
  );
}
