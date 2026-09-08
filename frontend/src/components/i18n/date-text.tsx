"use client";

import { useFormatter } from "next-intl";

/**
 * A calendar date rendered in the active application locale.
 *
 * API values are reduced to their date portion and interpreted at UTC midnight.
 * That keeps a legal-office date stable across browser timezones while replacing
 * the technical `YYYY-MM-DD` presentation with a human-readable label.
 */
export function DateText({
  value,
  fallback = "—",
}: {
  value: string | null | undefined;
  fallback?: string;
}) {
  const format = useFormatter();
  const datePart = value?.match(/^\d{4}-\d{2}-\d{2}/)?.[0];

  if (!datePart) {
    return <>{fallback}</>;
  }

  return (
    <time dateTime={datePart} className="whitespace-nowrap tabular-nums">
      {format.dateTime(new Date(`${datePart}T00:00:00.000Z`), {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: "UTC",
      })}
    </time>
  );
}
