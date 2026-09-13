import Image from "next/image";
import { Landmark, MapPinned } from "lucide-react";
import { useTranslations } from "next-intl";

/**
 * Visual orientation for the working day.
 *
 * The illustration is decorative and carries no record data. The text stays
 * bilingual, and the factual panels below remain the only place where counts
 * and operational state are disclosed.
 */
export function DashboardHero() {
  const t = useTranslations("dashboard");

  return (
    <section className="border-border/80 bg-card relative isolate min-h-60 overflow-hidden rounded-xl border shadow-sm">
      <div
        aria-hidden="true"
        className="absolute inset-0 bg-[radial-gradient(circle_at_80%_15%,color-mix(in_oklab,var(--ppat)_12%,transparent),transparent_42%),linear-gradient(115deg,color-mix(in_oklab,var(--card)_96%,transparent),color-mix(in_oklab,var(--muted)_75%,transparent))]"
      />

      <div className="relative z-10 flex min-h-60 flex-col justify-center px-5 py-7 sm:px-8 lg:max-w-[58%]">
        <span className="text-primary shadow-primary/5 mb-4 inline-flex w-fit items-center gap-2 rounded-full border border-white/70 bg-white/55 px-3 py-1.5 text-xs font-medium shadow-sm backdrop-blur-xl supports-[backdrop-filter]:bg-white/45">
          <Landmark className="text-ppat size-3.5" aria-hidden="true" />
          {t("practiceWorkspace")}
        </span>

        <h1 className="max-w-2xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
          {t("welcome")}
        </h1>
        <p className="text-muted-foreground mt-2 max-w-xl text-sm leading-relaxed sm:text-base">
          {t("welcomeDescription")}
        </p>

        <div className="text-ppat mt-5 flex items-center gap-2 text-xs font-medium">
          <MapPinned className="size-4" aria-hidden="true" />
          <span>{t("practiceContext")}</span>
        </div>
      </div>

      <div className="pointer-events-none absolute right-[-11rem] bottom-[-1.5rem] hidden w-[45rem] max-w-[58%] opacity-95 sm:block lg:right-[-4rem] lg:max-w-[52%]">
        <Image
          src="/illustrations/ppat-practice-dashboard.png"
          alt=""
          width={2001}
          height={786}
          priority
          sizes="(min-width: 1024px) 46vw, 44vw"
          className="h-auto w-full object-contain drop-shadow-[0_24px_28px_rgba(15,23,42,0.13)]"
        />
      </div>

      <div
        aria-hidden="true"
        className="shadow-primary/5 absolute right-6 bottom-5 hidden h-16 w-40 rounded-full border border-white/60 bg-white/22 shadow-lg backdrop-blur-xl sm:block"
      />
    </section>
  );
}
