import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { REPORTS } from "@/features/reports/report-definitions";
import { ReportSurface } from "@/features/reports/report-surface";
import { RevenueReport } from "@/features/reports/revenue-report";
import { renderWithProviders } from "@/test/render";
import type { CurrentUser } from "@/types/auth";

vi.mock("@/services/reports", () => ({
  reportQueryKeys: {
    all: () => ["reports"],
    page: (endpoint: string, filters: unknown) => ["reports", endpoint, filters],
    summary: (endpoint: string) => ["reports", "summary", endpoint],
  },
  getReportPage: vi.fn(),
  getDeedSummary: vi.fn(),
  getRevenue: vi.fn(),
  downloadReport: vi.fn(),
}));

vi.mock("@/features/auth/use-current-user", () => ({
  useCurrentUser: vi.fn(),
}));

const services = await import("@/services/reports");
const auth = await import("@/features/auth/use-current-user");

function actor(permissions: string[]): CurrentUser {
  return {
    id: "u1",
    name: "Rina",
    email: "rina@example.test",
    preferred_locale: "id",
    roles: [],
    // A flat string array, which is what `can()` reads.
    permissions,
  } as unknown as CurrentUser;
}

/**
 * An invoice row as the server actually sends one.
 *
 * **Every non-monetary key is present**, because the server's row builder always
 * includes them; only the three monetary keys are conditional on
 * `billing.amount.view`. A fixture missing arbitrary columns would test a
 * response shape the API never produces.
 */
function invoiceRow(overrides: Record<string, unknown> = {}) {
  return {
    invoice_number: "INV-2026-000001",
    title: "Jasa AJB",
    client: "PT ABC",
    project: "PRJ-2026-000001",
    matter: "N-2026-000001",
    status: "ISSUED",
    currency: "IDR",
    issued_at: "2026-07-01",
    due_date: "2026-08-01",
    is_overdue: "yes",
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(auth.useCurrentUser).mockReturnValue({ data: actor([]) } as never);
});

/**
 * The two gates this surface exists to respect (M8.3): `reports.export` decides
 * whether a report can be taken away, and `billing.amount.view` decides whether
 * it carries money at all. Both are enforced on the server; these assert the
 * presentation half — that the interface never offers a control the server would
 * refuse, and never renders a withheld figure as though it were a zero.
 */
describe("ReportSurface", () => {
  it("offers no export button without reports.export", async () => {
    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [invoiceRow()],
      meta: { current_page: 1, last_page: 1, total: 1 },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["financial.invoices"]} />);

    expect(await screen.findByText("INV-2026-000001")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "reports.export" })).not.toBeInTheDocument();
  });

  it("offers the export button once the capability is held", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["reports.export"]),
    } as never);

    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [invoiceRow()],
      meta: { current_page: 1, last_page: 1, total: 1 },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["financial.invoices"]} />);

    expect(await screen.findByRole("button", { name: "reports.export" })).toBeInTheDocument();
  });

  it("marks a withheld column rather than leaving the cell blank", async () => {
    // The server omits monetary keys entirely without `billing.amount.view`
    // (D-125), and sends every non-monetary one — exactly the shape
    // `FinancialReportController` produces. A blank cell in a money column would
    // read as "nothing owed", which is a different and wrong claim.
    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [invoiceRow()],
      meta: { current_page: 1, last_page: 1, total: 1, amounts_visible: false },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["financial.invoices"]} />);

    await screen.findByText("INV-2026-000001");

    // Three monetary columns are declared and none arrived.
    expect(screen.getAllByText("reports.withheld")).toHaveLength(3);
  });

  it("distinguishes a withheld column from a genuinely empty value", async () => {
    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [
        invoiceRow({
          // Present and null: the record has no due date.
          due_date: null,
          total_amount: "7500000.00",
          paid_amount: "0.00",
          outstanding_amount: "7500000.00",
        }),
      ],
      meta: { current_page: 1, last_page: 1, total: 1, amounts_visible: true },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["financial.invoices"]} />);

    await screen.findByText("INV-2026-000001");

    // Nothing is withheld here; the empty due date renders as a dash.
    expect(screen.queryByText("reports.withheld")).not.toBeInTheDocument();
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);

    // Two cells carry it — the total and the outstanding balance are equal on an
    // unpaid invoice — so this counts rather than expecting one.
    expect(screen.getAllByText("7500000.00")).toHaveLength(2);
    expect(screen.getByText("0.00")).toBeInTheDocument();
  });

  it("says so plainly when a report has no rows", async () => {
    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["operational.matters"]} />);

    // An actor who may open a family but read none of its rows sees this, which
    // is correct rather than an error.
    expect(await screen.findByText("reports.noData")).toBeInTheDocument();
  });

  it("shows a generic message rather than raw server text on failure", async () => {
    vi.mocked(services.getReportPage).mockRejectedValue(new Error("SQLSTATE[42S02]"));

    renderWithProviders(<ReportSurface definition={REPORTS["operational.matters"]} />);

    expect(await screen.findByText("reports.unavailable")).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("offers no status filter on the property report", async () => {
    // `properties.status` has no vocabulary and nothing writes it (M7.3), so a
    // control for it would filter by a column that is null on every row.
    vi.mocked(services.getReportPage).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0 },
    });

    renderWithProviders(<ReportSurface definition={REPORTS["ppat.properties"]} />);

    await screen.findByText("reports.noData");

    expect(screen.queryByLabelText("reports.filters.status")).not.toBeInTheDocument();
    expect(REPORTS["ppat.properties"].filters).not.toContain("status");
  });
});

describe("RevenueReport", () => {
  it("explains itself rather than showing an empty table when amounts are withheld", async () => {
    // Every cell of this report is a sum, so the server sends `data: null`
    // rather than rows without figures.
    vi.mocked(services.getRevenue).mockResolvedValue({
      data: null,
      meta: { amounts_visible: false },
    });

    renderWithProviders(<RevenueReport />);

    expect(await screen.findByText("reports.revenueWithheld")).toBeInTheDocument();
    expect(screen.queryByText("reports.noData")).not.toBeInTheDocument();
  });

  it("renders a period with its total once the grant is held", async () => {
    vi.mocked(services.getRevenue).mockResolvedValue({
      data: [
        {
          period: "2026-08",
          domain: "NOTARY",
          service_type_code: "AJB",
          service_type_name_id: "Akta Jual Beli",
          service_type_name_en: "Deed of Sale and Purchase",
          total_amount: "1000000.00",
          payment_count: 1,
        },
      ],
      meta: { amounts_visible: true },
    });

    renderWithProviders(<RevenueReport />);

    expect(await screen.findByText("2026-08")).toBeInTheDocument();

    // The Indonesian name, because the test locale is `id` — chosen here rather
    // than in SQL, where no locale is known.
    expect(screen.getByText("Akta Jual Beli")).toBeInTheDocument();
    expect(screen.getByText("1.000.000,00")).toBeInTheDocument();
  });

  it("keeps a payment with no service type in the total", async () => {
    // A payment against an invoice with no Matter is still revenue; the server
    // buckets it rather than dropping it.
    vi.mocked(services.getRevenue).mockResolvedValue({
      data: [
        {
          period: "2026-08",
          domain: null,
          service_type_code: null,
          service_type_name_id: null,
          service_type_name_en: null,
          total_amount: "500000.00",
          payment_count: 2,
        },
      ],
      meta: { amounts_visible: true },
    });

    renderWithProviders(<RevenueReport />);

    expect(await screen.findByText("500.000,00")).toBeInTheDocument();
  });
});
