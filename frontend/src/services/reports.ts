import { apiClient } from "@/lib/api/client";
import type { DeedSummary, ReportFilterValues, ReportPage, RevenuePage } from "@/types/reports";

/**
 * The Reports surface (M8.3, D-126).
 *
 * **Read-only, all of it.** There is no create, update or delete function here
 * and there is no endpoint for one: no capability in the `reports.*` family
 * authorizes a stored report, so every call is a GET.
 *
 * **Nothing here touches `ppat.reports.*`.** `reports.ppat.view` and
 * `ppat.reports.view` differ only in word order; the second belongs to the PPAT
 * monthly reporting obligation, whose format nobody has authored (O-043).
 */
const blank = (value: string | number | undefined) =>
  value === "" || value === undefined ? undefined : value;

export const reportQueryKeys = {
  all: () => ["reports"] as const,
  page: (endpoint: string, filters: ReportFilterValues) => ["reports", endpoint, filters] as const,
  summary: (endpoint: string) => ["reports", "summary", endpoint] as const,
};

function params(filters: ReportFilterValues): Record<string, string | number | undefined> {
  return Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, blank(value)]));
}

/** One page of any tabular report. */
export async function getReportPage(
  endpoint: string,
  filters: ReportFilterValues = {},
): Promise<ReportPage> {
  const response = await apiClient.get<ReportPage>(endpoint, { params: params(filters) });

  return response.data;
}

/** Deed counts by status and type, for the two summary surfaces. */
export async function getDeedSummary(
  endpoint: string,
  filters: ReportFilterValues = {},
): Promise<DeedSummary> {
  const response = await apiClient.get<{ data: DeedSummary }>(endpoint, {
    params: params(filters),
  });

  return response.data.data;
}

/** Verified receipts by period — `data` is null without `billing.amount.view`. */
export async function getRevenue(filters: ReportFilterValues = {}): Promise<RevenuePage> {
  const response = await apiClient.get<RevenuePage>("/api/v1/reports/financial/revenue", {
    params: params(filters),
  });

  return response.data;
}

/**
 * Download a report as CSV.
 *
 * **The server decides what the file contains**, including whether it carries
 * amounts at all — `reports.export` and `billing.amount.view` are separate gates
 * and both are checked there. This function only moves bytes.
 *
 * The blob is released after the click: an object URL that is never revoked
 * pins the whole file in memory for the life of the tab.
 */
export async function downloadReport(
  endpoint: string,
  filters: ReportFilterValues = {},
): Promise<void> {
  const response = await apiClient.get<Blob>(endpoint, {
    params: params(filters),
    responseType: "blob",
  });

  const disposition = String(response.headers["content-disposition"] ?? "");
  const match = /filename="?([^";]+)"?/.exec(disposition);

  const url = URL.createObjectURL(response.data);

  try {
    const anchor = document.createElement("a");

    anchor.href = url;
    anchor.download = match?.[1] ?? "report.csv";

    document.body.append(anchor);
    anchor.click();
    anchor.remove();
  } finally {
    URL.revokeObjectURL(url);
  }
}
