import { screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AmountField } from "@/features/billing/amount-field";
import { InvoiceList } from "@/features/billing/invoice-list";
import { PaymentList } from "@/features/billing/payment-list";
import { renderWithProviders } from "@/test/render";
import type { Invoice, Payment } from "@/types/billing";

vi.mock("@/services/billing", () => ({
  billingQueryKeys: {
    all: () => ["billing"],
    quotations: (query: unknown) => ["billing", "quotations", query],
    quotation: (id: string) => ["billing", "quotations", "detail", id],
    invoices: (query: unknown) => ["billing", "invoices", query],
    invoice: (id: string) => ["billing", "invoices", "detail", id],
    payments: (query: unknown) => ["billing", "payments", query],
    invoicePayments: (id: string) => ["billing", "invoices", "detail", id, "payments"],
    disbursements: (query: unknown) => ["billing", "disbursements", query],
    disbursement: (id: string) => ["billing", "disbursements", "detail", id],
  },
  getInvoices: vi.fn(),
  getPayments: vi.fn(),
  getQuotations: vi.fn(),
  getDisbursements: vi.fn(),
  verifyPayment: vi.fn(),
}));

const services = await import("@/services/billing");

function invoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: "inv1",
    invoice_number: "INV-2026-000001",
    title: "Jasa AJB",
    description: null,
    status: "ISSUED",
    currency: "IDR",
    due_date: "2026-08-01",
    issued_at: "2026-07-01T00:00:00+00:00",
    cancelled_at: null,
    cancellation_reason: null,
    notes: null,
    is_overdue: true,
    is_settled: false,
    amounts_visible: true,
    total_amount: "7500000.00",
    outstanding_amount: "7500000.00",
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function payment(overrides: Partial<Payment> = {}): Payment {
  return {
    id: "pay1",
    invoice_id: "inv1",
    status: "PENDING",
    method_code: "BANK_TRANSFER",
    reference: null,
    currency: "IDR",
    notes: null,
    paid_at: "2026-08-01",
    verified_at: null,
    amounts_visible: true,
    amount: "1000000.00",
    created_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

/**
 * The rule this surface turns on (D-125): a masked amount is **absent from the
 * payload**, so there is nothing in the browser to conceal. These assert the
 * presentation half — that the interface renders a deliberate placeholder rather
 * than a zero, a `null`, or an empty cell that reads as "nothing owed".
 */
describe("AmountField", () => {
  it("renders a withheld placeholder when the figure never arrived", () => {
    renderWithProviders(<AmountField amount={undefined} currency="IDR" visible={false} />);

    expect(screen.getByText("billing.amountWithheld")).toBeInTheDocument();
  });

  it("renders a withheld placeholder even if a value somehow arrived", () => {
    // Defence in depth: the server should never send this, and if it ever did,
    // the component still refuses to show it.
    renderWithProviders(<AmountField amount="7500000.00" currency="IDR" visible={false} />);

    expect(screen.getByText("billing.amountWithheld")).toBeInTheDocument();
    expect(screen.queryByText(/7\.500\.000/)).not.toBeInTheDocument();
  });

  it("groups an exact string without parsing it into a float", () => {
    renderWithProviders(<AmountField amount="7500000.00" currency="IDR" visible />);

    expect(screen.getByText("7.500.000,00")).toBeInTheDocument();
    expect(screen.getByText("IDR")).toBeInTheDocument();
  });

  it("never turns a zero into an empty cell", () => {
    // "Nothing outstanding" and "you may not see what is outstanding" must not
    // look the same.
    renderWithProviders(<AmountField amount="0.00" currency="IDR" visible />);

    expect(screen.getByText("0,00")).toBeInTheDocument();
  });
});

describe("InvoiceList", () => {
  it("shows the record and its lateness while withholding the money", async () => {
    vi.mocked(services.getInvoices).mockResolvedValue({
      data: [
        invoice({ amounts_visible: false, total_amount: undefined, outstanding_amount: undefined }),
      ],
    });

    renderWithProviders(<InvoiceList />);

    // What survives masking: somebody may know a bill exists and is late
    // without being entitled to its value.
    expect(await screen.findByText("INV-2026-000001")).toBeInTheDocument();
    expect(screen.getByLabelText("billing.status: billing.overdue")).toBeInTheDocument();

    expect(screen.getAllByText("billing.amountWithheld")).toHaveLength(2);
  });

  it("shows the figures once they are present", async () => {
    vi.mocked(services.getInvoices).mockResolvedValue({ data: [invoice()] });

    renderWithProviders(<InvoiceList />);

    expect(await screen.findAllByText("7.500.000,00")).toHaveLength(2);
  });

  it("shows a generic message rather than raw server text on failure", async () => {
    vi.mocked(services.getInvoices).mockRejectedValue(new Error("SQLSTATE[42S02]"));

    renderWithProviders(<InvoiceList />);

    expect(await screen.findByText("billing.listUnavailable")).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });
});

describe("PaymentList", () => {
  it("offers verify only where the server says it would succeed", async () => {
    vi.mocked(services.getPayments).mockResolvedValue({
      data: [
        payment({ id: "a", capabilities: { can_verify: true } }),
        payment({ id: "b", status: "VERIFIED", capabilities: { can_verify: false } }),
      ],
    });

    renderWithProviders(<PaymentList />);

    // `can_verify` already combines capability with state on the server, so the
    // button is absent on the payment that is through the one-way door (O-050).
    await waitFor(() => expect(screen.getAllByRole("row")).toHaveLength(3));

    expect(screen.getAllByRole("button", { name: "billing.verify" })).toHaveLength(1);
  });

  it("offers no verify control at all without the capability", async () => {
    vi.mocked(services.getPayments).mockResolvedValue({
      data: [payment({ capabilities: { can_verify: false } })],
    });

    renderWithProviders(<PaymentList />);

    // Wait on the row itself: the fixture carries no invoice, so that cell is a
    // dash and matching on it would be matching on nothing.
    await waitFor(() => expect(screen.getAllByRole("row")).toHaveLength(2));

    expect(screen.queryByRole("button", { name: "billing.verify" })).not.toBeInTheDocument();
  });
});
