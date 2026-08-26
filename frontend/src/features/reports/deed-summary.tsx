"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { Skeleton } from "@/components/ui/skeleton";
import { getDeedSummary, reportQueryKeys } from "@/services/reports";

/**
 * Deed counts by status and by type (M8.3, D-126).
 *
 * ## This is a histogram, not a Repertorium
 *
 * The lock's §10 is explicit: nothing M8.3 produces may resemble a statutory
 * return, *"because it invites being filed as one"*. So this shows counts and
 * nothing else — no period heading, no sequence, no signature block, and no
 * column that exists only because a register would have one. The Repertorium's
 * format and procedure are open questions nobody here has answered (O-035).
 *
 * **Every status appears, including the zeroes.** A histogram with holes in it
 * reads as a bug rather than as an empty bucket, and the server sends the full
 * vocabulary for that reason.
 */
export function DeedSummary({ endpoint }: { endpoint: string }) {
  const t = useTranslations("reports");

  const query = useQuery({
    queryKey: reportQueryKeys.summary(endpoint),
    queryFn: () => getDeedSummary(endpoint),
  });

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true" aria-live="polite">
        <span className="sr-only">{t("loading")}</span>
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }

  if (query.isError) {
    return <p className="text-muted-foreground text-sm">{t("unavailable")}</p>;
  }

  const summary = query.data;
  const types = Object.entries(summary.by_type);

  return (
    <div className="flex flex-col gap-6">
      <div className="border-border bg-card flex flex-col gap-1 rounded-lg border p-5">
        <span className="text-muted-foreground text-sm">{t("totalDeeds")}</span>
        <span className="text-2xl font-semibold tabular-nums">{summary.total}</span>
      </div>

      <Counts
        title={t("byStatus")}
        entries={Object.entries(summary.by_status)}
        empty={t("noData")}
      />

      <Counts
        title={t("byType")}
        entries={types}
        // A deed type the office has never used is not a hole in a histogram —
        // there is no closed vocabulary of deed types to fill in (O-035).
        empty={t("noTypesYet")}
      />
    </div>
  );
}

function Counts({
  title,
  entries,
  empty,
}: {
  title: string;
  entries: Array<[string, number]>;
  empty: string;
}) {
  if (entries.length === 0) {
    return (
      <section className="flex flex-col gap-2">
        <h2 className="text-base font-medium">{title}</h2>
        <p className="text-muted-foreground text-sm">{empty}</p>
      </section>
    );
  }

  const largest = Math.max(1, ...entries.map(([, count]) => count));

  return (
    <section className="flex flex-col gap-2">
      <h2 className="text-base font-medium">{title}</h2>

      <ul className="flex flex-col gap-2 text-sm">
        {entries.map(([label, count]) => (
          <li key={label} className="flex flex-col gap-1">
            <div className="flex items-baseline justify-between gap-2">
              <span className="min-w-0 truncate">{label}</span>
              <span className="text-muted-foreground shrink-0 tabular-nums">{count}</span>
            </div>

            {/* Decorative: the count beside it carries the same information as
                text, so a reader who cannot see the bar loses nothing (§49). */}
            <div className="bg-muted h-1.5 overflow-hidden rounded-full" aria-hidden="true">
              <div
                className="bg-primary h-full rounded-full"
                style={{ width: `${Math.round((count / largest) * 100)}%` }}
              />
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
