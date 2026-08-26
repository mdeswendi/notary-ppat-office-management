/**
 * Billing payloads (M8.2, D-124, D-125).
 *
 * ## Every monetary field is optional, and that is the type doing its job
 *
 * `billing.amount.view` is a separate capability. When it is not held the server
 * **omits** the key entirely — not null, not a placeholder — so the honest type
 * is `string | undefined`, and TypeScript then forces every render path to decide
 * what to show instead. `amounts_visible` says which case you are in, so a
 * component can render a deliberate placeholder rather than inferring one from a
 * missing key.
 *
 * Amounts are **strings**, not numbers: they come from PostgreSQL `numeric`, and
 * parsing them into IEEE floats to display them would reintroduce exactly the
 * imprecision an exact column exists to avoid.
 */

/** `DRAFT → APPROVED`. There is no `quotations.reject`, `.send` or `.expire`. */
export type QuotationStatus = "DRAFT" | "APPROVED";

/** `DRAFT → ISSUED → CANCELLED`. Settlement is derived, never a status. */
export type InvoiceStatus = "DRAFT" | "ISSUED" | "CANCELLED";

/** `PENDING → VERIFIED`. There is no `payments.reject` (O-050). */
export type PaymentStatus = "PENDING" | "VERIFIED";

export type PaymentMethod = "CASH" | "BANK_TRANSFER" | "CARD" | "OTHER";

interface PartyRef {
  id: string;
  display_name: string;
}

interface RecordRef {
  id: string;
  reference: string | null;
  title?: string;
}

/** Shared by every billing payload: whether money is in it at all. */
interface Maskable {
  amounts_visible: boolean;
}

export interface BillingLine extends Maskable {
  id: string;
  line_number: number;
  description: string;
  quantity?: string;
  unit_amount?: string;
  line_amount?: string;
}

export interface Quotation extends Maskable {
  id: string;
  quotation_number: string;
  title: string;
  description: string | null;
  status: QuotationStatus;
  currency: string;
  valid_until: string | null;
  approved_at: string | null;
  notes: string | null;
  subtotal_amount?: string;
  total_amount?: string;
  client_party?: PartyRef | null;
  project?: RecordRef | null;
  matter?: RecordRef | null;
  approved_by?: { id: string; name: string } | null;
  items?: BillingLine[];
  items_count?: number;
  invoices_count?: number;
  created_at: string | null;
  updated_at: string | null;
  capabilities?: { can_update: boolean; can_approve: boolean };
}

export interface Payment extends Maskable {
  id: string;
  invoice_id: string;
  status: PaymentStatus;
  method_code: PaymentMethod;
  reference: string | null;
  currency: string;
  notes: string | null;
  paid_at: string | null;
  verified_at: string | null;
  amount?: string;
  verified_by?: { id: string; name: string } | null;
  invoice?: RecordRef | null;
  created_at: string | null;
  capabilities?: { can_verify: boolean };
}

export interface Invoice extends Maskable {
  id: string;
  invoice_number: string;
  title: string;
  description: string | null;
  status: InvoiceStatus;
  currency: string;
  due_date: string | null;
  issued_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  notes: string | null;

  /** Facts about dates and state, not sums — so never masked. */
  is_overdue: boolean;
  is_settled: boolean;

  subtotal_amount?: string;
  total_amount?: string;
  paid_amount?: string;
  outstanding_amount?: string;

  client_party?: PartyRef | null;
  project?: RecordRef | null;
  matter?: RecordRef | null;
  quotation?: RecordRef | null;
  items?: BillingLine[];
  payments?: Payment[];
  items_count?: number;
  payments_count?: number;
  created_at: string | null;
  updated_at: string | null;
  capabilities?: { can_update: boolean; can_issue: boolean; can_cancel: boolean };
}

export interface Disbursement extends Maskable {
  id: string;
  description: string;
  currency: string;
  incurred_on: string | null;
  reference: string | null;
  notes: string | null;
  amount?: string;
  client_party?: PartyRef | null;
  project?: RecordRef | null;
  matter?: RecordRef | null;
  invoice?: RecordRef | null;
  created_at: string | null;
  updated_at: string | null;
  capabilities?: { can_update: boolean };
}

export interface BillingListQuery {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  overdue?: string;
  client_party_id?: string;
  project_id?: string;
  matter_id?: string;
  invoice_id?: string;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
}
