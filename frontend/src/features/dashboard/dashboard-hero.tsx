"use client";

import Image from "next/image";
import { useTranslations } from "next-intl";

import { useCurrentUser } from "@/features/auth/use-current-user";
import type { CurrentUser } from "@/types/auth";

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
    <section className="border-border/80 bg-card relative isolate min-h-72 overflow-hidden rounded-xl border shadow-sm">
      <Image
        src="/illustrations/ppat-practice-hero-v2.png"
        alt=""
        fill
        priority
        sizes="(min-width: 1280px) 82vw, 100vw"
        className="object-cover object-[66%_center] sm:object-center"
      />
      <div
        aria-hidden="true"
        className="from-card via-card/95 absolute inset-0 bg-linear-to-r to-white/5 sm:via-white/88 sm:to-transparent"
      />

      <div className="relative z-10 flex min-h-72 flex-col justify-center px-5 py-8 sm:px-8 lg:max-w-[58%]">
        <h1 className="max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
          {t("welcome")}
        </h1>
        <p className="text-primary mt-1 max-w-2xl text-xl font-semibold tracking-tight text-balance sm:text-2xl">
          {officeIdentity}
        </p>
        <div className="mt-4 flex max-w-xl items-start gap-3">
          <span className="bg-brand-gold mt-2 h-0.5 w-12 shrink-0" aria-hidden="true" />
          <p className="text-muted-foreground text-sm leading-relaxed italic sm:text-base">
            “{t("practiceMotto")}”
          </p>
        </div>
      </div>

      <div
        aria-hidden="true"
        className="dashboard-liquid-glass pointer-events-none absolute top-8 right-8 hidden h-36 w-[min(32vw,24rem)] opacity-55 sm:block"
      />
    </section>
  );
}

/**
 * During the current PPAT-only practice phase, the Organization may still carry
 * the future combined Notary & PPAT identity. The hero names the active practice
 * without rewriting the stored Organization name used elsewhere.
 */
function resolveOfficeIdentity(office: CurrentUser["office"] | undefined): string | null {
  const identity = office?.organization?.name ?? office?.name;

  if (!identity) {
    return null;
  }

  if (office?.practice_type === "PPAT") {
    return identity.replace(/^Kantor\s+Notaris\s*&\s*PPAT/i, "Kantor PPAT");
  }

  return identity;
}
