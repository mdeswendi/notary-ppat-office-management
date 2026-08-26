import { apiClient } from "@/lib/api/client";
import type {
  BillingListQuery,
  Disbursement,
  Invoice,
  Paginated,
  Payment,
  Quotation,
} from "@/types/billing";

/**
 * The Billing surface (M8.2, D-124).
 *
 * **There is no delete function for any billing document**, and that is not an
 * omission: `quotations.delete`, `invoices.delete` and `disbursements.delete` do
 * not exist in the canonical catalogue, so a draft raised in error is cancelled
 * rather than removed (O-051). There is no `rejectPayment` either — payments
 * have no correction path at all (O-050).
 *
 * **`createInvoice` is also the conversion call.** Passing `quotation_id` copies
 * an approved quotation's lines onto the new invoice, which is how the "convert"
 * action works without a `quotations.convert` code.
 */
const blank = (value: string | undefined) => (value === "" ? undefined : value);

export const billingQueryKeys = {
  all: () => ["billing"] as const,

  quotations: (query: BillingListQuery) => ["billing", "quotations", query] as const,
  quotation: (id: string) => ["billing", "quotations", "detail", id] as const,

  invoices: (query: BillingListQuery) => ["billing", "invoices", query] as const,
  invoice: (id: string) => ["billing", "invoices", "detail", id] as const,

  payments: (query: BillingListQuery) => ["billing", "payments", query] as const,
  invoicePayments: (id: string) => ["billing", "invoices", "detail", id, "payments"] as const,

  disbursements: (query: BillingListQuery) => ["billing", "disbursements", query] as const,
  disbursement: (id: string) => ["billing", "disbursements", "detail", id] as const,
};

function listParams(query: BillingListQuery) {
  return {
    page: query.page,
    per_page: query.per_page,
    search: blank(query.search),
    status: blank(query.status),
    overdue: blank(query.overdue),
    client_party_id: blank(query.client_party_id),
    project_id: blank(query.project_id),
    matter_id: blank(query.matter_id),
    invoice_id: blank(query.invoice_id),
  };
}

/* ------------------------------------------------------------------ quotations */

export async function getQuotations(query: BillingListQuery = {}): Promise<Paginated<Quotation>> {
  const response = await apiClient.get<Paginated<Quotation>>("/api/v1/quotations", {
    params: listParams(query),
  });

  return response.data;
}

export async function getQuotation(id: string): Promise<Quotation> {
  const response = await apiClient.get<{ data: Quotation }>(`/api/v1/quotations/${id}`);

  return response.data.data;
}

export async function createQuotation(payload: Record<string, unknown>): Promise<Quotation> {
  const response = await apiClient.post<{ data: Quotation }>("/api/v1/quotations", payload);

  return response.data.data;
}

export async function updateQuotation(
  id: string,
  payload: Record<string, unknown>,
): Promise<Quotation> {
  const response = await apiClient.put<{ data: Quotation }>(`/api/v1/quotations/${id}`, payload);

  return response.data.data;
}

/** The only lifecycle act `quotations.*` authorizes. */
export async function approveQuotation(id: string): Promise<Quotation> {
  const response = await apiClient.patch<{ data: Quotation }>(`/api/v1/quotations/${id}/approve`);

  return response.data.data;
}

/* -------------------------------------------------------------------- invoices */

export async function getInvoices(query: BillingListQuery = {}): Promise<Paginated<Invoice>> {
  const response = await apiClient.get<Paginated<Invoice>>("/api/v1/invoices", {
    params: listParams(query),
  });

  return response.data;
}

export async function getInvoice(id: string): Promise<Invoice> {
  const response = await apiClient.get<{ data: Invoice }>(`/api/v1/invoices/${id}`);

  return response.data.data;
}

/**
 * Raise a bill, optionally from an approved quotation.
 *
 * Pass `quotation_id` to convert: the server copies the lines as they stand, so
 * the invoice records what was agreed rather than tracking a live offer.
 */
export async function createInvoice(payload: Record<string, unknown>): Promise<Invoice> {
  const response = await apiClient.post<{ data: Invoice }>("/api/v1/invoices", payload);

  return response.data.data;
}

export async function updateInvoice(
  id: string,
  payload: Record<string, unknown>,
): Promise<Invoice> {
  const response = await apiClient.put<{ data: Invoice }>(`/api/v1/invoices/${id}`, payload);

  return response.data.data;
}

/** `issue`, never `send`: the catalogue's verb is `issue`, and issuing is sending. */
export async function issueInvoice(id: string): Promise<Invoice> {
  const response = await apiClient.patch<{ data: Invoice }>(`/api/v1/invoices/${id}/issue`);

  return response.data.data;
}

/** What the catalogue offers instead of deletion. */
export async function cancelInvoice(id: string, reason?: string): Promise<Invoice> {
  const response = await apiClient.patch<{ data: Invoice }>(`/api/v1/invoices/${id}/cancel`, {
    reason,
  });

  return response.data.data;
}

/* ----------------------------------------------------------------------- lines */

export async function addInvoiceLine(
  invoiceId: string,
  payload: Record<string, unknown>,
): Promise<void> {
  await apiClient.post(`/api/v1/invoices/${invoiceId}/items`, payload);
}

export async function removeInvoiceLine(invoiceId: string, lineId: string): Promise<void> {
  await apiClient.delete(`/api/v1/invoices/${invoiceId}/items/${lineId}`);
}

export async function addQuotationLine(
  quotationId: string,
  payload: Record<string, unknown>,
): Promise<void> {
  await apiClient.post(`/api/v1/quotations/${quotationId}/items`, payload);
}

export async function removeQuotationLine(quotationId: string, lineId: string): Promise<void> {
  await apiClient.delete(`/api/v1/quotations/${quotationId}/items/${lineId}`);
}

/* -------------------------------------------------------------------- payments */

export async function getPayments(query: BillingListQuery = {}): Promise<Paginated<Payment>> {
  const response = await apiClient.get<Paginated<Payment>>("/api/v1/payments", {
    params: listParams(query),
  });

  return response.data;
}

export async function getInvoicePayments(invoiceId: string): Promise<{ data: Payment[] }> {
  const response = await apiClient.get<{ data: Payment[] }>(
    `/api/v1/invoices/${invoiceId}/payments`,
  );

  return response.data;
}

export async function recordPayment(
  invoiceId: string,
  payload: Record<string, unknown>,
): Promise<Payment> {
  const response = await apiClient.post<{ data: Payment }>(
    `/api/v1/invoices/${invoiceId}/payments`,
    payload,
  );

  return response.data.data;
}

/**
 * The one-way door.
 *
 * Only verified payments count toward an invoice's paid total, and nothing
 * undoes this — there is no `payments.update`, `.delete` or `.reject` (O-050).
 */
export async function verifyPayment(id: string): Promise<Payment> {
  const response = await apiClient.patch<{ data: Payment }>(`/api/v1/payments/${id}/verify`);

  return response.data.data;
}

/* ---------------------------------------------------------------- disbursements */

export async function getDisbursements(
  query: BillingListQuery = {},
): Promise<Paginated<Disbursement>> {
  const response = await apiClient.get<Paginated<Disbursement>>("/api/v1/disbursements", {
    params: listParams(query),
  });

  return response.data;
}

export async function createDisbursement(payload: Record<string, unknown>): Promise<Disbursement> {
  const response = await apiClient.post<{ data: Disbursement }>("/api/v1/disbursements", payload);

  return response.data.data;
}

export async function updateDisbursement(
  id: string,
  payload: Record<string, unknown>,
): Promise<Disbursement> {
  const response = await apiClient.put<{ data: Disbursement }>(
    `/api/v1/disbursements/${id}`,
    payload,
  );

  return response.data.data;
}
