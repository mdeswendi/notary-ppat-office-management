"use client";

import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

type LoadingSkeletonProps = {
  /** Placeholder rows below the heading block. */
  rows?: number;
  className?: string;
};

/**
 * Neutral page-level loading placeholder.
 *
 * Shows a heading block and a few content rows — enough to hold layout without
 * inventing a business dataset. Announced as a single busy region so assistive
 * technology reports "loading" once instead of reading each bar.
 *
 * Deliberately a client component. Next.js gives `loading.tsx` no `params`, so
 * a server version could not call `setRequestLocale`, and next-intl would fall
 * back to reading the locale from the request — which opts the whole `[locale]`
 * segment out of static rendering. On the client the messages come from
 * `NextIntlClientProvider` instead, so `/id` and `/en` stay prerendered.
 */
export function LoadingSkeleton({ rows = 4, className }: LoadingSkeletonProps) {
  const t = useTranslations("common");

  return (
    <div
      role="status"
      aria-busy="true"
      aria-label={t("loading")}
      className={cn("flex flex-col gap-6", className)}
    >
      <div className="flex flex-col gap-2">
        <Skeleton className="h-7 w-56" />
        <Skeleton className="h-4 w-80 max-w-full" />
      </div>

      <div className="flex flex-col gap-2">
        {Array.from({ length: rows }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    </div>
  );
}
