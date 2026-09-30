import { fireEvent, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AmountField } from "@/features/billing/amount-field";
import { DisbursementCreateForm } from "@/features/billing/disbursement-create-form";
import { DisbursementList } from "@/features/billing/disbursement-list";
import { InvoiceList } from "@/features/billing/invoice-list";
import { PaymentList } from "@/features/billing/payment-list";
import { QuotationList } from "@/features/billing/quotation-list";
import { QuotationCreateForm } from "@/features/billing/quotation-create-form";
import { renderWithProviders } from "@/test/render";
import type { Invoice, Payment, Quotation } from "@/types/billing";
import type { CurrentUser } from "@/types/auth";

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
  getQuotation: vi.fn(),
  createQuotation: vi.fn(),
  updateQuotation: vi.fn(),
  createDisbursement: vi.fn(),
  getPartyDirectory: vi.fn(),
  getDisbursements: vi.fn(),
  verifyPayment: vi.fn(),
}));

vi.mock("@/features/auth/use-current-user", () => ({ useCurrentUser: vi.fn() }));
vi.mock("@/services/parties", () => ({
  partyDirectoryKeys: { list: (query: unknown) => ["parties", "directory", query] },
  getPartyDirectory: vi.fn(),
}));

const services = await import("@/services/billing");
const parties = await import("@/services/parties");
const auth = await import("@/features/auth/use-current-user");

function actor(permissions: string[]): CurrentUser {
  return {
    id: "u1",
    name: "Kurnia",
    email: "kurnia@example.test",
    preferred_locale: "id",
    roles: ["Front Office", "Finance"],
    permissions,
    permission_scopes: {},
    office: null,
  };
}

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

function quotation(overrides: Partial<Quotation> = {}): Quotation {
  return {
    id: "quo1",
    quotation_number: "QUO-2026-000001",
    title: "Jasa AJB",
    description: null,
    currency: "IDR",
    valid_until: "2026-09-01",
    notes: null,
    amounts_visible: true,
    total_amount: "5000000.00",
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
  vi.mocked(auth.useCurrentUser).mockReturnValue({ data: actor([]) } as never);
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

/**
 * The detail pages these numbers used to link to (billing/quotations/[id],
 * billing/invoices/[id]) are not built — the audit that found the two dead
 * links recorded them as a 404 on every click. The number stays the row's
 * identifier; it must simply never be a link into a page that doesn't exist.
 */
describe("QuotationList", () => {
  it("shows the quotation number and title as plain text, not a link", async () => {
    vi.mocked(services.getQuotations).mockResolvedValue({ data: [quotation()] });

    renderWithProviders(<QuotationList />);

    expect(await screen.findByText("QUO-2026-000001")).toBeInTheDocument();
    expect(screen.getByText("Jasa AJB")).toBeInTheDocument();

    expect(screen.queryByRole("link")).not.toBeInTheDocument();
    expect(screen.getByRole("table", { name: "billing.quotations" })).toBeInTheDocument();
    expect(screen.queryByRole("columnheader", { name: "billing.status" })).not.toBeInTheDocument();
    expect(
      screen.queryByRole("columnheader", { name: "billing.invoiced" }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: "billing.quotationNumber" })).toHaveAttribute(
      "scope",
      "col",
    );
    expect(screen.getByText("2026-09-01T00:00:00.000Z")).toHaveAttribute("datetime", "2026-09-01");
  });

  it("uses the shared empty state when no quotations exist", async () => {
    vi.mocked(services.getQuotations).mockResolvedValue({ data: [] });
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["quotations.create"]),
    } as never);

    renderWithProviders(<QuotationList />);

    expect(await screen.findByText("billing.emptyTitle")).toBeInTheDocument();
    expect(screen.getByText("billing.noQuotations")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "billing.newQuotation" })).toBeInTheDocument();
  });

  it("explains a 403 scope mismatch instead of reporting an unexplained list failure", async () => {
    vi.mocked(services.getQuotations).mockRejectedValue({
      isAxiosError: true,
      response: { status: 403 },
    });

    renderWithProviders(<QuotationList />);

    expect(await screen.findByText("billing.quotationAccessTitle")).toBeInTheDocument();
    expect(screen.getByText("billing.quotationAccessHint")).toBeInTheDocument();
  });

  it("keeps server failures visible instead of turning them into an empty list", async () => {
    vi.mocked(services.getQuotations).mockRejectedValue({
      isAxiosError: true,
      response: { status: 500 },
    });

    renderWithProviders(<QuotationList />);

    expect(await screen.findByText("billing.listErrorTitle")).toBeInTheDocument();
    expect(screen.queryByText("billing.noQuotations")).not.toBeInTheDocument();
  });
});

describe("QuotationCreateForm", () => {
  it("hides the create action without quotations.create", () => {
    renderWithProviders(<QuotationCreateForm />);

    expect(screen.queryByRole("button", { name: "billing.newQuotation" })).not.toBeInTheDocument();
  });

  it("creates a price record with a client and priced lines for an authorized user", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["quotations.create"]),
    } as never);
    vi.mocked(parties.getPartyDirectory).mockResolvedValue({
      data: [
        {
          id: "party1",
          party_type: "INDIVIDUAL",
          display_name: "Khemal",
          primary_phone: null,
          primary_email: null,
          office: null,
          individual: { full_name: "Khemal" },
          company: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });
    vi.mocked(services.createQuotation).mockResolvedValue(quotation());

    renderWithProviders(<QuotationCreateForm />);

    fireEvent.click(screen.getByRole("button", { name: "billing.newQuotation" }));
    fireEvent.change(screen.getByLabelText("billing.findClient"), {
      target: { value: "Khemal" },
    });
    await waitFor(() =>
      expect(screen.getByRole("group", { name: "billing.client" })).toHaveTextContent("Khemal"),
    );
    fireEvent.change(screen.getByLabelText("billing.title"), { target: { value: "Jasa AJB" } });
    fireEvent.change(screen.getByLabelText("billing.workDescription"), {
      target: { value: "Akta jual beli" },
    });
    fireEvent.change(screen.getByLabelText("billing.costComponent"), {
      target: { value: "Jasa notaris" },
    });
    expect(screen.queryByLabelText("billing.quantity")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("billing.unitAmount")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("billing.costNominal"), {
      target: { value: "2500000" },
    });

    fireEvent.click(screen.getByRole("button", { name: "actions.save" }));

    await waitFor(() =>
      expect(services.createQuotation).toHaveBeenCalledWith({
        title: "Jasa AJB",
        client_party_id: "party1",
        description: "Akta jual beli",
        currency: "IDR",
        valid_until: null,
        notes: null,
        items: [{ description: "Jasa notaris", amount: "2500000" }],
      }),
    );
  });
});

describe("QuotationDetail", () => {
  it("opens a legacy quotation and edits its full line amount without showing quantity or unit price", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["quotations.view", "quotations.update", "billing.amount.view"]),
    } as never);
    const old = quotation({
      items: [
        {
          id: "line1",
          line_number: 1,
          description: "Layanan",
          quantity: "2.00",
          unit_amount: "150000.00",
          line_amount: "300000.00",
          amounts_visible: true,
        },
      ],
      total_amount: "300000.00",
      capabilities: { can_update: true },
    });
    vi.mocked(services.getQuotations).mockResolvedValue({ data: [old] });
    vi.mocked(services.getQuotation).mockResolvedValue(old);
    vi.mocked(services.updateQuotation).mockResolvedValue(quotation({ ...old, title: "Revisi" }));

    renderWithProviders(<QuotationList />);
    fireEvent.click(await screen.findByRole("button", { name: "billing.viewQuotation" }));
    expect(
      await screen.findByRole("region", { name: "billing.quotationDetail" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("billing.quotationStatuses.DRAFT")).not.toBeInTheDocument();
    expect(screen.getAllByText("300.000,00").length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole("button", { name: "actions.edit" }));
    expect(screen.queryByLabelText("billing.quantity")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("billing.unitAmount")).not.toBeInTheDocument();
    const nominal = screen.getByLabelText("billing.costNominal") as HTMLInputElement;
    expect(nominal.value).toBe("300000.00");
    fireEvent.change(nominal, { target: { value: "350000" } });
    fireEvent.click(screen.getByRole("button", { name: "actions.save" }));
    await waitFor(() =>
      expect(services.updateQuotation).toHaveBeenCalledWith(
        "quo1",
        expect.objectContaining({
          items: [{ id: "line1", description: "Layanan", amount: "350000" }],
        }),
      ),
    );
  });
});

describe("DisbursementCreateForm", () => {
  it("explains a missing create permission instead of silently omitting the button", () => {
    renderWithProviders(<DisbursementCreateForm />);

    expect(
      screen.queryByRole("button", { name: "billing.newProcessCost" }),
    ).not.toBeInTheDocument();
    expect(screen.getByText("billing.costCreatePermissionRequired")).toBeInTheDocument();
  });

  it("records only a paid process cost for the selected client", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["disbursements.view", "disbursements.create"]),
    } as never);
    vi.mocked(parties.getPartyDirectory).mockResolvedValue({
      data: [
        {
          id: "party1",
          party_type: "INDIVIDUAL",
          display_name: "Saman",
          primary_phone: null,
          primary_email: null,
          office: null,
          individual: { full_name: "Saman" },
          company: null,
          created_at: null,
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    });
    vi.mocked(services.createDisbursement).mockResolvedValue({ id: "cost1" } as never);

    const { queryClient } = renderWithProviders(<DisbursementCreateForm />);
    const invalidate = vi.spyOn(queryClient, "invalidateQueries");

    fireEvent.click(screen.getByRole("button", { name: "billing.newProcessCost" }));
    expect(await screen.findByRole("option", { name: "Saman" })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("billing.client"), { target: { value: "party1" } });
    fireEvent.change(screen.getByLabelText("billing.processCostDescription"), {
      target: { value: "BPHTB" },
    });
    fireEvent.change(screen.getByLabelText("billing.amount"), { target: { value: "1250000" } });
    fireEvent.change(screen.getByLabelText("billing.processCostPaidOn"), {
      target: { value: "2026-01-01" },
    });
    fireEvent.click(screen.getByRole("button", { name: "actions.save" }));

    await waitFor(() =>
      expect(services.createDisbursement).toHaveBeenCalledWith({
        client_party_id: "party1",
        description: "BPHTB",
        amount: "1250000",
        incurred_on: "2026-01-01",
        reference: null,
        notes: null,
        currency: "IDR",
      }),
    );
    await waitFor(() =>
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["billing", "disbursements", {}] }),
    );
  });

  it("rejects an empty or unpaid cost before calling the API", async () => {
    vi.mocked(auth.useCurrentUser).mockReturnValue({
      data: actor(["disbursements.create"]),
    } as never);
    vi.mocked(parties.getPartyDirectory).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
    });

    renderWithProviders(<DisbursementCreateForm />);
    fireEvent.click(screen.getByRole("button", { name: "billing.newProcessCost" }));
    fireEvent.click(screen.getByRole("button", { name: "actions.save" }));

    expect(await screen.findByText("billing.costValidation.clientRequired")).toBeInTheDocument();
    expect(services.createDisbursement).not.toHaveBeenCalled();
  });
});

describe("DisbursementList", () => {
  it("identifies the client for a process cost without surfacing client invoice references", async () => {
    vi.mocked(services.getDisbursements).mockResolvedValue({
      data: [
        {
          id: "cost1",
          description: "BPHTB",
          currency: "IDR",
          incurred_on: "2026-01-01",
          reference: null,
          notes: null,
          amount: "1250000.00",
          amounts_visible: true,
          client_party: { id: "party1", display_name: "Saman" },
          invoice: { id: "invoice1", reference: "INV-PRIVATE" },
          created_at: null,
          updated_at: null,
        },
      ],
    });

    renderWithProviders(<DisbursementList />);

    expect(await screen.findByText("Saman")).toBeInTheDocument();
    expect(screen.getByText("BPHTB")).toBeInTheDocument();
    expect(screen.queryByText("INV-PRIVATE")).not.toBeInTheDocument();
  });
});

describe("InvoiceList", () => {
  it("shows the invoice number and title as plain text, not a link", async () => {
    vi.mocked(services.getInvoices).mockResolvedValue({ data: [invoice()] });

    renderWithProviders(<InvoiceList />);

    expect(await screen.findByText("INV-2026-000001")).toBeInTheDocument();
    expect(screen.getByText("Jasa AJB")).toBeInTheDocument();

    expect(screen.queryByRole("link")).not.toBeInTheDocument();
    expect(screen.getByRole("table", { name: "billing.invoices" })).toBeInTheDocument();
  });

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
    expect(screen.getByText("billing.listErrorTitle")).toBeInTheDocument();
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument();
  });

  it("can retry a failed invoice request", async () => {
    vi.mocked(services.getInvoices)
      .mockRejectedValueOnce(new Error("temporary"))
      .mockResolvedValueOnce({ data: [invoice()] });

    renderWithProviders(<InvoiceList />);

    fireEvent.click(await screen.findByRole("button", { name: "actions.retry" }));

    expect(await screen.findByText("INV-2026-000001")).toBeInTheDocument();
    expect(services.getInvoices).toHaveBeenCalledTimes(2);
  });
});

describe("PaymentList", () => {
  it("shows the paid invoice's reference as plain text, not a link", async () => {
    vi.mocked(services.getPayments).mockResolvedValue({
      data: [
        payment({
          invoice: { id: "inv1", reference: "INV-2026-000001" },
          capabilities: { can_verify: false },
        }),
      ],
    });

    renderWithProviders(<PaymentList />);

    expect(await screen.findByText("INV-2026-000001")).toBeInTheDocument();

    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });

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
    expect(screen.getByRole("columnheader", { name: "billing.actions" })).toHaveAttribute(
      "scope",
      "col",
    );
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
