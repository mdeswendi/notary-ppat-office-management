"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { AUDIT_EVENTS } from "@/features/reports/report-definitions";
import { useCurrentUser } from "@/features/auth/use-current-user";
import { can } from "@/lib/permissions/can";
import { downloadReport, getReportPage, reportQueryKeys } from "@/services/reports";
import type { ReportDefinition, ReportFilterValues, ReportRow } from "@/types/reports";

/**
 * One report: filters, a table, and a download (M8.3, D-126).
 *
 * Shared by all ten tabular reports, because they differ only in endpoint,
 * columns and which filters they offer (`CLAUDE.md` section 40).
 *
 * ## A withheld column is absent, and the table says so
 *
 * A financial row carries its monetary keys only when `billing.amount.view` is
 * held — the server omits them entirely (D-125). So a cell whose key is missing
 * renders a **withheld marker**, not an empty cell: an empty cell in a money
 * column reads as "nothing", which is a different and wrong claim.
 *
 * ## The export button is gated, and the server checks again
 *
 * `reports.export` is a separate capability from every view code, so the button
 * is absent without it. That is presentation only: the endpoint checks both gates
 * regardless, and this component could not widen what the file contains even if
 * it tried (section 28).
 */
export function ReportSurface({ definition }: { definition: ReportDefinition }) {
  const t = useTranslations("reports");
  const { data: user } = useCurrentUser();

  const [filters, setFilters] = useState<ReportFilterValues>({ page: 1, per_page: 50 });
  const [downloading, setDownloading] = useState(false);
  const [downloadFailed, setDownloadFailed] = useState(false);

  const query = useQuery({
    queryKey: reportQueryKeys.page(definition.endpoint, filters),
    queryFn: () => getReportPage(definition.endpoint, filters),
  });

  const rows = query.data?.data ?? [];
  const meta = query.data?.meta;

  const set = (patch: Partial<ReportFilterValues>) =>
    // Any filter change returns to page one: staying on page 7 of a narrower
    // result set shows an empty table that looks like "no data".
    setFilters((current) => ({ ...current, ...patch, page: 1 }));

  const canExport = user !== undefined && can(user, "reports.export");

  async function onExport() {
    setDownloading(true);
    setDownloadFailed(false);

    try {
      await downloadReport(definition.exportEndpoint, filters);
    } catch {
      setDownloadFailed(true);
    } finally {
      setDownloading(false);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <ReportFilters definition={definition} filters={filters} onChange={set} />

        {canExport ? (
          <Button variant="outline" size="sm" onClick={onExport} disabled={downloading}>
            {downloading ? t("exporting") : t("export")}
          </Button>
        ) : null}
      </div>

      {downloadFailed ? <p className="text-destructive text-sm">{t("exportFailed")}</p> : null}

      {query.isPending ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-live="polite">
          <span className="sr-only">{t("loading")}</span>
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : query.isError ? (
        <p className="text-muted-foreground text-sm">{t("unavailable")}</p>
      ) : rows.length === 0 ? (
        <p className="text-muted-foreground text-sm">{t("noData")}</p>
      ) : (
        <>
          <div className="border-border overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-muted-foreground text-xs">
                <tr>
                  {definition.columns.map((column) => (
                    <th
                      key={column}
                      className={`px-3 py-2 font-medium ${
                        definition.numeric?.includes(column) ? "text-right" : "text-left"
                      }`}
                    >
                      {t(`columns.${column}`)}
                    </th>
                  ))}
                </tr>
              </thead>

              <tbody className="divide-border divide-y">
                {rows.map((row, index) => (
                  <tr key={rowKey(row, index)}>
                    {definition.columns.map((column) => (
                      <Cell
                        key={column}
                        row={row}
                        column={column}
                        numeric={definition.numeric?.includes(column) ?? false}
                      />
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta ? (
            <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 text-sm">
              <span className="tabular-nums">
                {t("pageOf", { page: meta.current_page, last: meta.last_page, total: meta.total })}
              </span>

              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setFilters((c) => ({ ...c, page: (c.page ?? 1) - 1 }))}
                >
                  {t("previous")}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setFilters((c) => ({ ...c, page: (c.page ?? 1) + 1 }))}
                >
                  {t("next")}
                </Button>
              </div>
            </div>
          ) : null}
        </>
      )}
    </div>
  );
}

/**
 * One cell.
 *
 * **A missing key and an empty value are different things.** The first means the
 * server withheld the column — `billing.amount.view` — and gets a marker; the
 * second means the record has no value there and gets a dash.
 */
function Cell({ row, column, numeric }: { row: ReportRow; column: string; numeric: boolean }) {
  const t = useTranslations("reports");

  const withheld = !(column in row);
  const value = row[column];

  return (
    <td className={`px-3 py-2 ${numeric ? "text-right tabular-nums" : ""}`}>
      {withheld ? (
        <span className="text-muted-foreground text-xs" title={t("withheldHint")}>
          {t("withheld")}
        </span>
      ) : value === null || value === undefined || value === "" ? (
        <span className="text-muted-foreground">—</span>
      ) : (
        String(value)
      )}
    </td>
  );
}

function ReportFilters({
  definition,
  filters,
  onChange,
}: {
  definition: ReportDefinition;
  filters: ReportFilterValues;
  onChange: (patch: Partial<ReportFilterValues>) => void;
}) {
  const t = useTranslations("reports");

  return (
    <div className="flex flex-wrap items-end gap-3">
      {definition.filters.includes("dateRange") ? (
        <>
          <Field label={t("filters.dateFrom")}>
            <input
              type="date"
              value={filters.date_from ?? ""}
              onChange={(event) => onChange({ date_from: event.target.value })}
              className="border-border bg-background rounded-md border px-3 py-1.5 text-sm"
            />
          </Field>
          <Field label={t("filters.dateTo")}>
            <input
              type="date"
              value={filters.date_to ?? ""}
              onChange={(event) => onChange({ date_to: event.target.value })}
              className="border-border bg-background rounded-md border px-3 py-1.5 text-sm"
            />
          </Field>
        </>
      ) : null}

      {definition.filters.includes("status") && definition.statusOptions ? (
        <Field label={t("filters.status")}>
          <Select
            value={filters.status ?? ""}
            onChange={(event) => onChange({ status: event.target.value })}
          >
            <option value="">{t("filters.all")}</option>
            {definition.statusOptions.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </Select>
        </Field>
      ) : null}

      {definition.filters.includes("domain") ? (
        <Field label={t("filters.domain")}>
          <Select
            value={filters.domain ?? ""}
            onChange={(event) => onChange({ domain: event.target.value })}
          >
            <option value="">{t("filters.all")}</option>
            <option value="NOTARY">NOTARY</option>
            <option value="PPAT">PPAT</option>
          </Select>
        </Field>
      ) : null}

      {definition.filters.includes("type") ? (
        <Field label={t("filters.type")}>
          <input
            type="text"
            value={filters.type ?? ""}
            onChange={(event) => onChange({ type: event.target.value })}
            className="border-border bg-background rounded-md border px-3 py-1.5 text-sm"
            placeholder={t("filters.typePlaceholder")}
          />
        </Field>
      ) : null}

      {definition.filters.includes("eventType") ? (
        <Field label={t("filters.eventType")}>
          <Select
            value={filters.event_type ?? ""}
            onChange={(event) => onChange({ event_type: event.target.value })}
          >
            <option value="">{t("filters.all")}</option>
            {AUDIT_EVENTS.map((event) => (
              <option key={event} value={event}>
                {event}
              </option>
            ))}
          </Select>
        </Field>
      ) : null}

      {definition.filters.includes("completeness") ? (
        <>
          <Field label={t("filters.completenessMin")}>
            <input
              type="number"
              min={0}
              max={100}
              value={filters.completeness_min ?? ""}
              onChange={(event) => onChange({ completeness_min: event.target.value })}
              className="border-border bg-background w-24 rounded-md border px-3 py-1.5 text-sm"
            />
          </Field>
          <Field label={t("filters.completenessMax")}>
            <input
              type="number"
              min={0}
              max={100}
              value={filters.completeness_max ?? ""}
              onChange={(event) => onChange({ completeness_max: event.target.value })}
              className="border-border bg-background w-24 rounded-md border px-3 py-1.5 text-sm"
            />
          </Field>
        </>
      ) : null}

      {definition.filters.includes("overdue") ? (
        <label className="mb-1.5 flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={filters.overdue === "true"}
            onChange={(event) => onChange({ overdue: event.target.checked ? "true" : "" })}
            className="border-border rounded"
          />
          {t("filters.overdueOnly")}
        </label>
      ) : null}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="text-muted-foreground text-xs">{label}</span>
      {children}
    </label>
  );
}

/**
 * A stable key for a row that has no guaranteed id.
 *
 * Reports return flat column maps, and not every one carries an identifier —
 * the audit export deliberately does not. The first non-empty cell plus the
 * index is stable within a page, which is all React needs.
 */
function rowKey(row: ReportRow, index: number): string {
  const first = Object.values(row).find((value) => value !== null && value !== undefined);

  return `${String(first ?? "row")}-${index}`;
}
