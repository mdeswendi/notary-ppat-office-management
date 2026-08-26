import type { ReportDefinition } from "@/types/reports";

/**
 * Every report, described once (M8.3, D-126).
 *
 * Thirteen surfaces that differ only in their endpoint, columns and filters. A
 * page component each would be thirteen copies of one paging-and-export loop, and
 * `CLAUDE.md` section 40 asks for the shared component instead — so each page is
 * a definition plus `<ReportSurface />`.
 *
 * **The `permission` here gates navigation only.** It is the family's view code,
 * and the backend checks it again on every request; a definition that named the
 * wrong one would show a menu entry leading to a 403, never data the caller may
 * not see (section 28).
 *
 * **`properties` deliberately has no status filter.** `properties.status` has no
 * vocabulary in the ERD and nothing writes it (M7.3), so a control for it would
 * filter by a column that is null on every row.
 */
export const REPORTS: Record<string, ReportDefinition> = {
  "operational.matters": {
    titleKey: "matters",
    endpoint: "/api/v1/reports/operational/matters",
    exportEndpoint: "/api/v1/reports/operational/matters/export",
    columns: [
      "matter_number",
      "title",
      "domain",
      "status",
      "service_type",
      "project",
      "pic",
      "opened_at",
      "target_completion_date",
      "completed_at",
    ],
    filters: ["dateRange", "status", "domain"],
    statusOptions: [
      "OPEN",
      "IN_PROGRESS",
      "WAITING",
      "ON_HOLD",
      "COMPLETED",
      "CANCELLED",
      "ARCHIVED",
    ],
    permission: "reports.operational.view",
  },

  "operational.tasks": {
    titleKey: "tasks",
    endpoint: "/api/v1/reports/operational/tasks",
    exportEndpoint: "/api/v1/reports/operational/tasks/export",
    columns: [
      "title",
      "status",
      "priority",
      "matter",
      "project",
      "assignee",
      "due_at",
      "is_overdue",
      "completed_at",
    ],
    filters: ["dateRange", "status"],
    statusOptions: ["OPEN", "IN_PROGRESS", "WAITING", "COMPLETED", "CANCELLED"],
    permission: "reports.operational.view",
  },

  "operational.documents": {
    titleKey: "documents",
    endpoint: "/api/v1/reports/operational/documents",
    exportEndpoint: "/api/v1/reports/operational/documents/export",
    columns: [
      "document_number",
      "title",
      "document_type_code",
      "status",
      "document_date",
      "created_by",
      "created_at",
    ],
    filters: ["dateRange", "status", "type"],
    statusOptions: ["DRAFT", "UNDER_REVIEW", "VERIFIED", "FINAL", "ARCHIVED", "VOID"],
    permission: "reports.operational.view",
  },

  "notary.deeds": {
    titleKey: "deeds",
    endpoint: "/api/v1/reports/notary/deeds",
    exportEndpoint: "/api/v1/reports/notary/deeds/export",
    columns: [
      "deed_number",
      "deed_date",
      "deed_type_code",
      "title",
      "status",
      "matter",
      "finalized_at",
    ],
    filters: ["dateRange", "status", "type"],
    statusOptions: ["DRAFT", "UNDER_REVIEW", "APPROVED", "FINALIZED", "VOID", "SUPERSEDED"],
    permission: "reports.notary.view",
  },

  "ppat.deeds": {
    titleKey: "deeds",
    endpoint: "/api/v1/reports/ppat/deeds",
    exportEndpoint: "/api/v1/reports/ppat/deeds/export",
    columns: [
      "deed_number",
      "deed_date",
      "deed_type_code",
      "title",
      "status",
      "matter",
      "finalized_at",
    ],
    filters: ["dateRange", "status", "type"],
    statusOptions: ["DRAFT", "UNDER_REVIEW", "APPROVED", "FINALIZED", "VOID", "SUPERSEDED"],
    permission: "reports.ppat.view",
  },

  "ppat.properties": {
    titleKey: "properties",
    endpoint: "/api/v1/reports/ppat/properties",
    exportEndpoint: "/api/v1/reports/ppat/properties/export",
    columns: [
      "property_number",
      "property_type",
      "right_type",
      "certificate_number",
      "land_area",
      "village",
      "district",
      "city",
      "province",
    ],
    numeric: ["land_area"],
    // No `status`: nothing writes `properties.status` (M7.3).
    filters: ["type"],
    permission: "reports.ppat.view",
  },

  "ppat.warkah": {
    titleKey: "warkah",
    endpoint: "/api/v1/reports/ppat/warkah",
    exportEndpoint: "/api/v1/reports/ppat/warkah/export",
    columns: [
      "deed_number",
      "status",
      "completeness_percentage",
      "verified_at",
      "archive_location",
    ],
    numeric: ["completeness_percentage"],
    filters: ["status", "completeness"],
    statusOptions: ["INCOMPLETE", "UNDER_REVIEW", "COMPLETE", "FINALIZED", "ARCHIVED"],
    permission: "reports.ppat.view",
  },

  "financial.invoices": {
    titleKey: "invoices",
    endpoint: "/api/v1/reports/financial/invoices",
    exportEndpoint: "/api/v1/reports/financial/invoices/export",
    columns: [
      "invoice_number",
      "title",
      "client",
      "project",
      "matter",
      "status",
      "currency",
      "issued_at",
      "due_date",
      "is_overdue",
      // Present only when `billing.amount.view` is held; the row simply lacks
      // the key otherwise, and the table renders a withheld marker (D-125).
      "total_amount",
      "paid_amount",
      "outstanding_amount",
    ],
    numeric: ["total_amount", "paid_amount", "outstanding_amount"],
    filters: ["dateRange", "status", "overdue"],
    statusOptions: ["DRAFT", "ISSUED", "CANCELLED"],
    permission: "reports.financial.view",
  },

  "financial.payments": {
    titleKey: "payments",
    endpoint: "/api/v1/reports/financial/payments",
    exportEndpoint: "/api/v1/reports/financial/payments/export",
    columns: [
      "paid_at",
      "invoice_number",
      "status",
      "method_code",
      "reference",
      "currency",
      "amount",
    ],
    numeric: ["amount"],
    filters: ["dateRange", "status"],
    statusOptions: ["PENDING", "VERIFIED"],
    permission: "reports.financial.view",
  },

  "audit.activity": {
    titleKey: "activity",
    endpoint: "/api/v1/reports/audit/activity",
    exportEndpoint: "/api/v1/reports/audit/activity/export",
    columns: [
      "created_at",
      "event",
      "actor",
      "auditable_type",
      "auditable_id",
      "ip_address",
      "reason",
    ],
    filters: ["dateRange", "eventType"],
    permission: "reports.audit.view",
  },
};

/** The audit events the filter offers, mirroring `AuditEvent`. */
export const AUDIT_EVENTS = [
  "CREATED",
  "UPDATED",
  "DELETED",
  "STATUS_CHANGED",
  "LOGIN",
  "LOGOUT",
  "SENSITIVE_ACCESS",
] as const;
