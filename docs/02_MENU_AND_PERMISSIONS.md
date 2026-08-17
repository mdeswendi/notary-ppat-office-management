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
├── Directory          (added M2.5 — read-only, any of parties.view / companies.view)
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
| Direktori | Directory |
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
projects.parties.view
projects.parties.manage
```

Avoid normal hard-delete permission.

**M3.0 locked what three of these mean, and added none** — the canonical count stayed at 171
through M3.3.

**M3.4 added the two participation codes, taking the canonical count to 173** *(D-098)*. They are
the first additions since this catalogue was transcribed, and they follow the M2.4 precedent:
a relationship surface gets its own capability rather than borrowing the parent's lifecycle
permission, exactly as `companies.management.*` does.

`projects.parties.view` reads a Project's participant list; `projects.parties.manage` adds,
corrects, and removes. **Neither implies the other**, and `projects.update` reaches neither.
Both are evaluated against the **parent Project** by the four Project Data Scope predicates
(D-088), and there is deliberately **no `projects.parties.view_all`** — reach is Data Scope `ALL`,
and a second reach mechanism is what D-090 refuses.

Holding `projects.parties.manage` is authority over a Project's participation; it is **not**
authority to discover Parties. Linking additionally requires ordinary Party visibility for the
candidate — `parties.view` for an Individual, `companies.view` for a Company, evaluated
independently.

`projects.assign` means mutating `pic_user_id` **and nothing else**. Generic `projects.update`
must not touch it: reassigning work is a different act from correcting a title. Workflow and
stage assignees are not Project assignment.

`projects.change_status` is likewise separate, and generic update must not mutate status.
**No transition matrix exists** — M3 authorizes *who* may change status, never *which* changes
are legal, because no canonical document defines that.

`projects.restore` restores a **soft-deleted Project record**. It does not turn business status
`ARCHIVED` back into `OPEN`, reverse a workflow, or undo a completion. `ARCHIVED` and
`deleted_at` are different states with unfortunately similar names.

See D-091 and D-093, and `13_M3_PROJECT_ARCHITECTURE.md`.

### `view_all` is superseded by Data Scope `ALL` *(M3.0)*

`projects.view_all` and its siblings elsewhere in this document —
`notary.matters.view_all`, `ppat.matters.view_all`, `tasks.view_all`, `calendar.view_all` —
predate the Data Scope model. They express **reach**, which is exactly what a Data Scope
expresses, and section 22 plus `CLAUDE.md` section 26 both warn against duplicating a
permission per scope. They are listed here as bare entries with no stated meaning, which is
how the duplication survived unnoticed.

The ruling, recorded rather than applied silently:

- The codes **remain registered** for compatibility and documentation history. **Nothing is
  removed and the count stays at 171.**
- For **reach semantics they are superseded by Data Scope `ALL`**.
- **No `view_all` code may be used as backend cross-office authorization authority.**
- **No second reach mechanism may exist beside `EffectiveAccessResolver`.** One resolver
  answers reach, or two answers eventually disagree and the looser one wins by accident.

See D-090.

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

**No directory permission and no duplicate-detection permission exist** *(M2.5)*. The unified
Party Directory composes `parties.view` and `companies.view` (section 23), and advisory
duplicate assistance answers to the capabilities already listed here: the lifecycle code for
whichever operation is being attempted, plus — for a *sensitive* signal — that identifier's own
full-view code.

That last point is the one worth stating explicitly. Being told "another record here already
carries this NIK" is a disclosure about somebody else's record, so it requires
`parties.identity.nik.view_full`; NPWP requires its own; and a Company `tax_id` reuses
`parties.identity.npwp.view_full`, because it *is* the NPWP.
**`parties.identity.update` is deliberately not sufficient** — writing a value is not licence
to learn that somebody else already has it.

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

**M4.0 adds no permission — the canonical count stays at 173.** All sixteen Matter codes, Notary
and PPAT alike, were already canonical. Four rulings govern how they are used
*(`14_M4_MATTER_ARCHITECTURE.md`)*:

- **The namespace comes from the route** *(D-101)*: `/api/v1/notary/matters` resolves
  `notary.matters.*`, `/api/v1/ppat/matters` resolves `ppat.matters.*`. Never from a request-body
  `domain`, and never from row data.
- **Matter Data Scope** *(D-100)*: `OWN` = `created_by`, `ASSIGNED` = `matter.pic_user_id`,
  `OFFICE` = `matter.office_id`, `ALL` = cross-office reach, `TEAM` = no grant. **Project reach
  confers no Matter authority**, and a stage assignee never widens `ASSIGNED`.
- **`view_all` remains superseded for reach** *(D-090)* and is not backend authority.
- **No `archive` or `restore` code exists for Matter, and M4 invents none** *(D-102)*. The absence
  is the registry's. `matters.deleted_at` may exist as reserved schema capability with no API
  lifecycle reaching it, and `CANCELLED` / `COMPLETED` / `ARCHIVED` are business statuses, never
  synonyms for soft deletion.

**M4.2 built the Matter foundation and added no permission — the count stays at 173** *(D-107)*.
Fourteen of the sixteen codes — every one except the two `view_all` — are narrowed in
`PermissionScopeRules` to the four Project-shaped scopes `OWN`, `ASSIGNED`, `OFFICE`, `ALL`, with
`TEAM` withheld. `create` needs no special entry: an administrator may grant it at `ASSIGNED`, and
it simply authorizes nothing, because the predicate is false for a record that has no PIC yet.
**Both `view_all` codes stay out of those rules and are consulted by no Policy ability.** M4.2 ships
no route, so navigation is unchanged.

**Matter participation is expected to add four codes at M4.5** *(D-105)* —
`notary.matters.parties.view`, `notary.matters.parties.manage`, `ppat.matters.parties.view`,
`ppat.matters.parties.manage`, moving the count **173 → 177**. Four rather than two because the
role matrix in section 5 gives Notary Staff full access to Notary Matters and view-only on PPAT
Matters, and the reverse for PPAT Staff; one pair spanning both domains would hand each of them the
other's participation. `view` and `manage` are independent, `manage` does not imply `view`, and
neither is reached by `…matters.update`. **They are not registered at M4.0** — the count moves in
the milestone that gives them routes, following the M3.4 precedent.

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

**M4.1 built the Service Type foundation and added no permission — the count stays at 173**
*(D-106)*. `master.services.view` and `master.services.manage` were already canonical, and the two
are **independent**: `manage` does not imply `view`.

Their Data Scopes are narrowed to exactly **`OFFICE` and `ALL`**. Service Types are Office-owned
reference data, so `OWN` would have to mean `created_by` — a column the table deliberately does not
have — `ASSIGNED` has no assignee to match, and `TEAM` has no Team entity (D-042). Offering any of
the three would let an administrator save a silently powerless grant.

**The other twelve `master.*` families keep the permissive default**, because their domains are
still undesigned; narrowing them now would repeat the mistake that narrowing corrects.

**M4.1 ships no route and no navigation entry.** Master Data stays absent from the sidebar, so no
sibling `master.*` code sits inside a module the interface presents as working, and the
deferred-permission list is unchanged. Backend foundation is not a reachable product route
(D-064).

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

## 19a. Organization & Office Permissions

Locked by D-026 and D-027.

```text
organizations.view
organizations.update

offices.view
offices.create
offices.update
offices.disable
```

There is deliberately no `organizations.create` and no hard-delete permission
for either resource.

V1 runs one active Organization per deployment, created once by the bootstrap
process rather than through routine application use, so a creation capability
would describe an operation the product does not offer. Offices and
Organizations are retired with `offices.disable` and the `is_active` flag, in
keeping with the delete policy in `07_SECURITY_RULES.md` section 22.

---

## 20. Settings Permissions

Two distinct capability groups. They are **not** aliases and must never be
treated as interchangeable — see D-030.

General system settings — application and office-system configuration. This is
the capability behind the **System Settings** row in the matrix above.

```text
settings.view
settings.manage
```

Security settings — authentication and security configuration, session
administration, and MFA.

```text
security.settings.view
security.settings.manage

security.sessions.view
security.sessions.revoke

security.mfa.manage
```

Granting `settings.manage` must not imply any `security.*` capability.

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

### Resolution rules

Locked by D-028 and D-029. These are summarized here; `DECISIONS.md` is
authoritative.

```text
multiple roles     effective scopes are the UNION of every role grant,
                   never collapsed to a single "widest" value

user override      at most one active override per user + permission
                   DENY   denies regardless of role grants
                   ALLOW  replaces the role-derived result, and its scope
                          becomes authoritative — so an override can widen
                          or narrow access
                   expiry evaluated at check time; an expired override is
                          ignored, not honoured until a cleanup job runs

no override        role-derived grants, scopes unioned
```

`TEAM` is **reserved vocabulary and not assignable** until a Team entity is
specified. M1 must not offer it in the UI, must not seed it, and must reject it
in validation. It is kept in the list so the vocabulary stays stable.

Scope meanings — `OFFICE` matches the record's `office_id` against the user's
primary office; `ALL` applies no office restriction within the deployment's
Organization; `OWN` and `ASSIGNED` are resource-specific relationships whose
exact field each resource's Policy defines.

### Per-domain predicates, as each domain has settled them

`OWN` and `ASSIGNED` are deliberately resource-specific, so each domain states its own
answer and argues it on its own facts rather than copying a neighbour's.

**Party domain** (D-080) — `OFFICE` and `ALL` only.

```text
OFFICE     party.office_id == actor.office_id
ALL        cross-office Party reach
OWN        withheld — a Party is a shared directory record, and the colleague
           who typed it in has no claim on the person it describes
ASSIGNED   withheld — no Party assignment entity exists
TEAM       withheld — no Team entity exists
```

**Project domain** (D-088, M3.0) — all four assignable scopes mean something.

```text
OWN        project.created_by   == actor.id
ASSIGNED   project.pic_user_id  == actor.id
OFFICE     project.office_id    == actor.office_id
ALL        cross-office Project reach
TEAM       no Project-domain grant
```

That Party withholds `OWN` while Project grants it is **not** an inconsistency. A Party is a
shared reference record; a Project is a unit of work somebody opened. The Party reasoning did
not transfer, so the answer did not either.

**Future Matter or stage assignment must never expand Project `ASSIGNED`.** Letting
`matters.pic_user_id` or a stage assignee widen Project reach would be a new grant wearing an
existing scope's name, silently widening every role already configured. If Matter workers need
Project visibility, that is its own decision and its own predicate.

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

### Entries composed from more than one capability *(added M2.5)*

Most entries are gated by a single permission. Some destinations are **composed** from several,
and gating those on one code would be wrong in both directions.

The Party Directory is the first. It lists Individuals to a holder of `parties.view` and
Companies to a holder of `companies.view`, so an account holding **either** has something real
to open:

```text
Directory      any of [parties.view, companies.view]
Individuals    parties.view
Companies      companies.view
```

Requiring both would hide a page that works. Inventing `parties.directory.view` would be worse:
a permission for a *page* rather than for the records on it, which would let an administrator
grant the directory without granting sight of anything in it, or withhold it from somebody who
can already open every record it lists. **No such permission exists**, and M2.5 added none — the
count stood at 171 through that milestone. *(It is **173** today: M3.4 added the two Project
participation codes, D-098. Corrected at M4.0, which found this sentence still asserting the
older total in the present tense.)*

Implemented in `frontend/src/config/navigation.ts` as an `anyPermissions` list beside the
existing `requiredPermission`. Where both fields are set, both must hold, so adding
`anyPermissions` to an entry can never widen what a single required permission already
narrowed. An exact `requiredScope` deliberately does not combine with it — a scope belongs to
one specific capability, and pairing it with a set of alternatives would be ambiguous about
which.

**A composed entry does not imply a composed scope.** The two capabilities keep their own Data
Scopes all the way through: an account may hold `parties.view` at `OFFICE` and `companies.view`
at `ALL`, and the backend answers with one Office's people beside every Office's organizations.
Nothing in the interface ranks or unions the two, and the page must not claim a single scope
governs every row.

As always, this is presentation. The endpoint authorizes independently and refuses a caller
whose capabilities reach nothing.

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
├── Directory
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
