# Notary & PPAT Office Management System
## Complete Menu Structure & Role-Permission Specification — v1.0

## 1. Authorization Principle

Use role-based access control with granular permissions.

Relationship:

```text
USER
  ↓
ROLE(S)
  ↓
PERMISSIONS
```

Users may hold multiple roles.

Authorization must not depend only on role names.

---

## 2. Main Menu Structure

```text
Dashboard

Projects
├── All Projects
├── My Projects
└── Archived Projects

Notary
├── Matters
├── Notarial Deeds
├── Drafts & Minuta Akta
├── Legalisasi
├── Waarmerking
├── Repertorium
└── Notary Protocol

PPAT
├── Matters
├── PPAT Deeds
├── Land & Property
├── Warkah
├── Taxes & Fees
├── Deed Register
├── PPAT Reports
└── PPAT Protocol

Clients & Parties
├── Individuals
└── Companies

Documents
├── All Documents
├── Document Inbox
└── Recent Documents

Tasks
├── My Tasks
├── All Tasks
└── Completed Tasks

Calendar

Billing
├── Quotations
├── Invoices
├── Payments
└── Disbursements

Reports
├── Operational
├── Notary
├── PPAT
├── Financial
└── Audit & Activity

Master Data
├── Service Types
├── Workflow
├── Document Requirements
├── Task Templates
├── Document Templates
├── Numbering
└── Legal Terminology

Settings
├── Office Profile
├── Users
├── Roles & Permissions
├── Language & Localization
├── Notifications
├── Security
├── Integrations
└── Audit Log
```

---

## 3. Bilingual Menu Labels

| Indonesian | English |
|---|---|
| Dasbor | Dashboard |
| Proyek | Projects |
| Pekerjaan Notaris | Notary Matters |
| Akta Notaris | Notarial Deeds |
| Draft & Minuta Akta | Drafts & Minuta Akta |
| Legalisasi | Legalisasi |
| Waarmerking | Waarmerking |
| Repertorium | Notary Register / Repertorium |
| Protokol Notaris | Notary Protocol |
| Pekerjaan PPAT | PPAT Matters |
| Akta PPAT | PPAT Deeds |
| Objek Tanah & Properti | Land & Property |
| Warkah | Warkah |
| Pajak & Biaya | Taxes & Fees |
| Buku Daftar Akta | Deed Register |
| Laporan PPAT | PPAT Reports |
| Protokol PPAT | PPAT Protocol |
| Klien & Para Pihak | Clients & Parties |
| Individu | Individuals |
| Perusahaan | Companies |
| Dokumen | Documents |
| Tugas | Tasks |
| Kalender | Calendar |
| Penagihan | Billing |
| Laporan | Reports |
| Data Master | Master Data |
| Pengaturan | Settings |

Legal terminology rules are defined separately.

---

## 4. Default Roles

### SUPER_ADMIN

Technical/system administration.

Full technical access.

Should not be used as the normal day-to-day legal working account.

### PRINCIPAL

Notary / PPAT principal.

Typical authority:

- office-wide operational visibility;
- legal review;
- approval;
- finalization;
- protocol/report visibility;
- signing-related oversight.

### OFFICE_MANAGER

Operational management without automatically receiving legal approval authority.

### NOTARY_STAFF

Operational Notary work.

### PPAT_STAFF

Operational PPAT work.

A user may have both `NOTARY_STAFF` and `PPAT_STAFF`.

### FRONT_OFFICE

Client intake, initial data entry, appointment, basic document receipt.

### FINANCE

Quotation, invoice, payment, disbursement, financial reporting.

### ARCHIVE_STAFF

Document filing, archive, Minuta Akta, Warkah, protocol administration.

### AUDITOR

Read-only access within assigned scope.

---

## 5. High-Level Permission Matrix

Legend:

```text
F = Full operational access
V = View / limited
A = Approval / legal authority
- = No default access
```

| Module | Super Admin | Principal | Manager | Notary Staff | PPAT Staff | Front Office | Finance | Archive | Auditor |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Dashboard | F | F | F | F | F | F | V | V | V |
| Projects | F | F | F | F | F | V | V | V | V |
| Individuals | F | F | F | F | F | F | V | V | V |
| Companies | F | F | F | F | F | F | V | V | V |
| Notary Matters | F | F | F | F | V | V | - | V | V |
| Notarial Deeds | F | A | F | F | - | - | - | V | V |
| Minuta Akta | F | A | V | V | - | - | - | F | V |
| Legalisasi | F | A | F | F | - | V | - | V | V |
| Waarmerking | F | A | F | F | - | V | - | V | V |
| Repertorium | F | A | V | V | - | - | - | F | V |
| Notary Protocol | F | A | V | V | - | - | - | F | V |
| PPAT Matters | F | F | F | V | F | V | - | V | V |
| PPAT Deeds | F | A | F | - | F | - | - | V | V |
| Property | F | F | F | V | F | V | - | V | V |
| Warkah | F | A | V | - | F | - | - | F | V |
| Taxes & Fees | F | F | F | - | F | - | F | V | V |
| PPAT Register | F | A | V | - | V | - | - | F | V |
| PPAT Reports | F | A | V | - | V | - | - | V | V |
| PPAT Protocol | F | A | V | - | V | - | - | F | V |
| Documents | F | F | F | F | F | V | V | F | V |
| Tasks | F | F | F | F | F | V | V | V | V |
| Calendar | F | F | F | F | F | F | V | V | V |
| Billing | F | F | F | V | V | V | F | - | V |
| Reports | F | F | F | V | V | - | F | - | V |
| Master Data | F | V | F | - | - | - | - | - | V |
| User Management | F | V | F | - | - | - | - | - | - |
| Roles & Permissions | F | - | V | - | - | - | - | - | - |
| Audit Log | F | F | V | - | - | - | - | - | V |
| System Settings | F | V | V | - | - | - | - | - | - |

This is a default configuration, not a hardcoded permanent rule.

---

## 6. Permission Naming Convention

Use:

```text
resource.action
```

Examples:

```text
projects.view
projects.create
projects.update

notary.matters.view
notary.matters.create

notary.deeds.review
notary.deeds.approve

ppat.matters.view
ppat.warkah.verify

documents.upload
documents.download
```

---

## 7. Project Permissions

```text
projects.view
projects.view_all
projects.create
projects.update
projects.assign
projects.change_status
projects.archive
projects.restore
```

Avoid normal hard-delete permission.

---

## 8. Party Permissions

```text
parties.view
parties.create
parties.update
parties.archive

parties.identity.view
parties.identity.update
parties.identity.nik.view_full
parties.identity.npwp.view_full
```

---

## 9. Company Permissions

```text
companies.view
companies.create
companies.update
companies.archive

companies.management.view
companies.management.update

companies.shareholders.view
companies.shareholders.update
```

---

## 10. Notary Permissions

### Matters

```text
notary.matters.view
notary.matters.view_all
notary.matters.create
notary.matters.update
notary.matters.assign
notary.matters.change_stage
notary.matters.complete
notary.matters.cancel
```

### Deeds

```text
notary.deeds.view
notary.deeds.create
notary.deeds.update
notary.deeds.review
notary.deeds.approve
notary.deeds.finalize
notary.deeds.number
```

### Minuta Akta

```text
notary.minuta.view
notary.minuta.create
notary.minuta.update
notary.minuta.archive
notary.minuta.release
```

### Register

```text
notary.register.view
notary.register.create
notary.register.update
notary.register.finalize
notary.register.export
```

---

## 11. PPAT Permissions

### Matters

```text
ppat.matters.view
ppat.matters.view_all
ppat.matters.create
ppat.matters.update
ppat.matters.assign
ppat.matters.change_stage
ppat.matters.complete
ppat.matters.cancel
```

### Deeds

```text
ppat.deeds.view
ppat.deeds.create
ppat.deeds.update
ppat.deeds.review
ppat.deeds.approve
ppat.deeds.finalize
ppat.deeds.number
```

### Warkah

```text
ppat.warkah.view
ppat.warkah.upload
ppat.warkah.update
ppat.warkah.verify
ppat.warkah.finalize
ppat.warkah.archive
```

### Register

```text
ppat.register.view
ppat.register.create
ppat.register.update
ppat.register.finalize
ppat.register.export
```

### Reports

```text
ppat.reports.view
ppat.reports.generate
ppat.reports.review
ppat.reports.approve
ppat.reports.export
```

---

## 12. Property Permissions

```text
properties.view
properties.create
properties.update
properties.archive

properties.ownership.view
properties.ownership.update
```

---

## 13. Document Permissions

```text
documents.view
documents.sensitive.view

documents.upload
documents.download
documents.sensitive.download

documents.update
documents.verify
documents.archive
documents.delete
```

`documents.delete` must be heavily restricted.

For legal documents, prefer archive, void, or supersede over hard delete.

---

## 14. Task Permissions

```text
tasks.view
tasks.view_all
tasks.create
tasks.update
tasks.assign
tasks.complete
tasks.reopen
tasks.delete
```

---

## 15. Calendar Permissions

```text
calendar.view
calendar.view_all
calendar.create
calendar.update
calendar.delete
```

---

## 16. Billing Permissions

```text
billing.view
billing.amount.view

quotations.view
quotations.create
quotations.update
quotations.approve

invoices.view
invoices.create
invoices.update
invoices.issue
invoices.cancel

payments.view
payments.create
payments.verify

disbursements.view
disbursements.create
disbursements.update
```

---

## 17. Report Permissions

```text
reports.operational.view
reports.notary.view
reports.ppat.view
reports.financial.view
reports.audit.view
reports.export
```

---

## 18. Master Data Permissions

```text
master.services.view
master.services.manage

master.workflows.view
master.workflows.manage

master.requirements.view
master.requirements.manage

master.task_templates.view
master.task_templates.manage

master.document_templates.view
master.document_templates.manage

master.numbering.view
master.numbering.manage

master.legal_terms.view
master.legal_terms.manage
```

---

## 19. User & Role Permissions

```text
users.view
users.create
users.update
users.disable
users.reset_password

roles.view
roles.create
roles.update
roles.delete

permissions.view
permissions.assign
```

---

## 20. Security Permissions

```text
security.settings.view
security.settings.manage

security.sessions.view
security.sessions.revoke

security.mfa.manage
```

---

## 21. Audit Permissions

```text
audit.view
audit.export
```

Do not create:

```text
audit.update
audit.delete
```

---

## 22. Data Scope

Supported data scopes:

```text
OWN
ASSIGNED
TEAM
OFFICE
ALL
```

Example:

```text
NOTARY_STAFF
notary.matters.view
scope = ASSIGNED
```

Principal may have:

```text
notary.matters.view
scope = OFFICE
```

---

## 23. Permission-Based Menu Visibility

Bad:

```text
if role == PPAT_STAFF:
    show PPAT menu
```

Correct concept:

```text
if user can ppat.matters.view:
    show PPAT menu
```

If the user has no permission for any PPAT child menu, hide the PPAT parent menu.

---

## 24. Critical Actions

The following must require explicit permissions and produce audit events:

- approve deed;
- finalize deed;
- assign deed number;
- finalize register;
- approve PPAT report;
- cancel issued invoice;
- modify finalized data through correction process;
- restore archived legal record;
- change role or permission;
- sensitive document access where configured.

---

## 25. Record State Enforcement

Example legal lifecycle:

```text
DRAFT
UNDER_REVIEW
APPROVED
FINALIZED
LOCKED
```

Once locked, normal updates must be denied.

Correction must use a controlled process.

---

## 26. Recommended MVP Sidebar

```text
Dashboard

Projects

Notary
├── Matters
├── Notarial Deeds
└── Drafts & Minuta Akta

PPAT
├── Matters
├── PPAT Deeds
├── Land & Property
└── Warkah

Clients & Parties
├── Individuals
└── Companies

Documents
Tasks
Calendar

Master Data
Settings
```

Later milestones may activate:

- Repertorium;
- Notary Protocol;
- Taxes & Fees;
- PPAT Register;
- PPAT Reports;
- PPAT Protocol;
- Billing;
- Advanced Reports;
- Integrations.

---

**Status:** Final baseline v1.0
