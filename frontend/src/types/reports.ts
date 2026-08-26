/**
 * Report payloads (M8.3, D-126).
 *
 * ## Rows are loosely typed on purpose
 *
 * Every report returns a flat map of column name to scalar, and the columns
 * differ per report — including **within one report**, because a financial row
 * carries monetary keys only when `billing.amount.view` is held (D-125). A shape
 * per report would be five interfaces that all had to be optional anyway, and
 * would still not express "these three keys appear together or not at all".
 *
 * `ReportSurface` renders whatever columns the definition names and the row
 * happens to carry, so a withheld column is simply absent rather than rendered
 * empty.
 */
export type ReportCell = string | number | null | undefined;

export type ReportRow = Record<string, ReportCell>;

export interface ReportMeta {
  current_page: number;
  last_page: number;
  total: number;
  /** Financial reports only: whether this response carries money at all. */
  amounts_visible?: boolean;
}

export interface ReportPage {
  data: ReportRow[];
  meta: ReportMeta;
}

/**
 * The revenue report is the one that returns `null` rather than rows.
 *
 * Every cell of it is a sum, so there is no non-monetary half to serve without
 * `billing.amount.view` — see `FinancialReportController::revenue()`.
 */
export interface RevenuePage {
  data: RevenueRow[] | null;
  meta: { amounts_visible: boolean };
}

export interface RevenueRow {
  period: string;
  domain: string | null;
  service_type_code: string | null;
  /** Both names ship; the client picks. Never a language chosen in SQL. */
  service_type_name_id: string | null;
  service_type_name_en: string | null;
  total_amount: string;
  payment_count: number;
}

export interface DeedSummary {
  total: number;
  by_status: Record<string, number>;
  by_type: Record<string, number>;
}

export interface ReportFilterValues {
  date_from?: string;
  date_to?: string;
  status?: string;
  type?: string;
  domain?: string;
  priority?: string;
  right_type?: string;
  city?: string;
  pic_user_id?: string;
  assignee_id?: string;
  party_id?: string;
  matter_id?: string;
  invoice_id?: string;
  user_id?: string;
  event_type?: string;
  auditable_type?: string;
  auditable_id?: string;
  method_code?: string;
  completeness_min?: string;
  completeness_max?: string;
  overdue?: string;
  page?: number;
  per_page?: number;
}

/** Which filter controls a report offers. Only these are rendered. */
export type ReportFilterKind =
  "dateRange" | "status" | "type" | "domain" | "eventType" | "completeness" | "overdue";

export interface ReportDefinition {
  /** Translation key under `reports.*` for the page heading. */
  titleKey: string;
  /** Where the JSON page comes from. */
  endpoint: string;
  /** Where the CSV comes from, when the caller may export. */
  exportEndpoint: string;
  /** Column keys, in order. Each is also a translation key under `reports.columns.*`. */
  columns: string[];
  /** Columns rendered right-aligned with tabular figures. */
  numeric?: string[];
  filters: ReportFilterKind[];
  /** Options for the `status` dropdown, when the report offers one. */
  statusOptions?: string[];
  /** The capability that opens this family, for navigation gating. */
  permission: string;
}
