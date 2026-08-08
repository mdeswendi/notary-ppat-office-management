# Notary & PPAT Office Management System
## Project Overview — v1.0

## 1. Project Name

**Notary & PPAT Office Management System**

A bilingual Indonesian-English web application for managing operational, administrative, document, workflow, and audit activities in an Indonesian Notary and PPAT office.

---

## 2. Project Purpose

The system is designed to become the internal operating platform for a Notary and PPAT office.

Its primary purpose is to help office users answer these questions quickly:

1. Who is the client?
2. What service or transaction is being handled?
3. Which parties are involved?
4. Which documents are complete or missing?
5. What stage is the matter currently in?
6. Who is responsible for the next action?
7. What is the deadline or target completion date?
8. Which legal records and supporting documents have been produced?
9. Which activities have been performed and by whom?

The system must reduce duplicate data entry, fragmented spreadsheets, scattered files, unclear task ownership, and manual tracking.

---

## 3. Core Product Principles

The application shall be built around the following principles:

- One integrated system for **Notary + PPAT**.
- Shared client and party data across Notary and PPAT work.
- Clear separation of Notary and PPAT business domains.
- Project-based transaction management.
- Matter-based operational work management.
- Workflow-driven processing.
- Document requirements and document versioning.
- Strong role and permission management.
- Immutable or controlled handling of finalized legal records.
- Append-only audit trail.
- Bilingual Indonesian-English interface.
- Preservation of Indonesian legal terminology where appropriate.
- Private storage for sensitive legal and identity documents.
- Desktop-first professional office UX.

---

## 4. Core Business Hierarchy

The main business hierarchy is:

```text
PARTY / CLIENT
      ↓
PROJECT
      ↓
MATTER
      ↓
WORKFLOW
      ↓
REQUIREMENTS + TASKS + DOCUMENTS
      ↓
NOTARY / PPAT LEGAL PROCESS
      ↓
DEED / LEGAL OUTPUT
      ↓
MINUTA AKTA / WARKAH
      ↓
REGISTER / PROTOCOL / ARCHIVE
```

For PPAT matters, the following additional relationship applies:

```text
PPAT MATTER
      ↓
PROPERTY / LAND OBJECT
      ↓
OWNERSHIP / TRANSACTION HISTORY
```

---

## 5. Project Concept

A **Project** represents one client engagement, transaction, or larger legal requirement.

Example:

```text
Project:
Akuisisi Tanah PT ABC
```

A Project may contain multiple Matters:

```text
Akuisisi Tanah PT ABC
├── Notary Matter: Corporate Resolution
├── Notary Matter: Agreement
├── PPAT Matter: AJB
└── PPAT Matter: APHT
```

A simple engagement may also contain only one Matter.

---

## 6. Matter Concept

A **Matter** is the operational unit of work.

Matter domains:

```text
NOTARY
PPAT
```

A Matter contains:

- service type;
- assigned PIC;
- parties;
- requirements;
- workflow;
- tasks;
- documents;
- target dates;
- status;
- activity history;
- legal output.

Matter Status and Workflow Stage are separate concepts.

Example:

```text
Matter Status:
IN_PROGRESS

Workflow Stage:
NOTARY_REVIEW
```

---

## 7. Shared Domain

The following modules are shared across Notary and PPAT:

- Dashboard
- Projects
- Individuals
- Companies
- Parties
- Documents
- Tasks
- Calendar
- Billing
- Reports
- Users
- Roles
- Permissions
- Master Data
- Notifications
- Activity Timeline
- Audit Log

---

## 8. Notary Domain

The Notary domain includes:

- Notary Matters
- Notarial Deeds
- Drafts
- Minuta Akta
- Legalisasi
- Waarmerking
- Repertorium
- Notary Protocol
- Notary-specific document requirements
- Notary workflow
- Review and approval processes

Notary-specific fields must not be forced into generic PPAT tables.

---

## 9. PPAT Domain

The PPAT domain includes:

- PPAT Matters
- PPAT Deeds
- AJB
- APHT
- Property / Land Object
- Ownership History
- Warkah
- Taxes and Fees
- Deed Register
- PPAT Reports
- PPAT Protocol
- PPAT-specific document requirements
- PPAT workflow

PPAT-specific data shall remain separated from Notary-specific legal data while sharing common infrastructure.

---

## 10. Party Model

The system uses a unified **Party** concept.

Party types:

```text
INDIVIDUAL
COMPANY
```

A Party may play different roles in different Matters.

Examples:

```text
Budi Santoso
- SELLER in Matter A
- DIRECTOR in Matter B
- AUTHORIZED_PERSON in Matter C
```

Party roles must therefore be stored in the relationship with a Project or Matter, not permanently in the base Party record.

---

## 11. Individual Data

The system may store:

- full name;
- NIK;
- NPWP;
- birth place;
- birth date;
- occupation;
- nationality;
- marital status;
- address;
- phone;
- email;
- identity documents;
- related projects and matters.

Sensitive information must be masked or access-controlled.

---

## 12. Company Data

The system may store:

- legal name;
- short name;
- entity type;
- registration data;
- tax ID;
- address;
- phone;
- email;
- directors;
- commissioners;
- shareholders;
- authorized persons;
- historical management changes;
- related projects and matters.

Historical company relationships should not be overwritten when management changes.

---

## 13. Document Management

Documents must support:

- private storage;
- document metadata;
- document type;
- related Party / Project / Matter / Property / Deed;
- sensitivity classification;
- versioning;
- verification status;
- uploader information;
- checksum;
- archive status;
- access control.

Legal files must never be exposed through predictable public URLs.

---

## 14. Workflow

Each Service Type may have its own workflow template.

Examples:

```text
Notary:
Intake
Document Collection
Draft Preparation
Notary Review
Signing
Completion
Archive
```

```text
PPAT:
Intake
Document Collection
Verification
Tax Processing
Deed Preparation
Review
Signing
Registration
Completion
Archive
```

Templates may evolve, but existing Matter history must remain stable through snapshot/version logic.

---

## 15. Bilingual Requirement

The application must support:

```text
id
en
```

Primary/default UI language:

```text
Indonesian
```

English is the secondary language.

Not all Indonesian legal terminology should be translated literally.

Terms such as these should remain preserved where appropriate:

- PPAT
- AJB
- APHT
- Warkah
- Minuta Akta
- Legalisasi
- Waarmerking
- Repertorium

Detailed rules are defined in:

```text
docs/05_I18N_LEGAL_TERMINOLOGY.md
```

---

## 16. User Roles

Default roles:

- Super Administrator
- Notary / PPAT Principal
- Office Manager
- Notary Staff
- PPAT Staff
- Front Office
- Finance
- Archive / Document Staff
- Auditor / Read Only

Users may have more than one role.

Authorization must not depend only on role name.

---

## 17. Authorization Model

Authorization follows:

```text
ROLE
+
PERMISSION
+
DATA SCOPE
+
RECORD STATE
+
BUSINESS RULE
```

Supported data scopes:

```text
OWN
ASSIGNED
TEAM
OFFICE
ALL
```

Backend authorization remains authoritative.

---

## 18. Technology Direction

Frontend:

- Next.js
- TypeScript
- Tailwind CSS
- shadcn/ui
- next-intl
- TanStack Query
- TanStack Table
- React Hook Form
- Zod
- Axios

Backend:

- Laravel
- Laravel Sanctum
- Spatie Laravel Permission
- Pest
- Laravel Pint

Database:

- PostgreSQL

Storage:

- Private local storage for development
- Private S3-compatible object storage for production

---

## 19. Development Milestones

```text
M0 — Foundation
M1 — Identity & Access Management
M2 — Party / Individual / Company
M3 — Project Management
M4 — Matter & Workflow Engine
M5 — Documents & Tasks
M6 — Notary Module
M7 — PPAT Module
M8 — Dashboard, Billing & Reports
```

Modules must be developed incrementally.

Do not implement future modules without explicit instruction.

---

## 20. MVP Direction

Initial MVP focuses on:

- authentication;
- users;
- roles and permissions;
- bilingual UI;
- Party / Individual / Company;
- Project;
- Matter;
- workflow;
- document requirements;
- document management;
- task management;
- Notary Matter;
- PPAT Matter;
- Property;
- Warkah;
- Notary Deed;
- PPAT Deed;
- activity timeline;
- audit trail.

Advanced Billing, complex Reporting, integrations, and AI automation are later phases.

---

## 21. Legal and Compliance Note

This specification defines software architecture and operational behavior.

Before production deployment, all legal workflows, document requirements, numbering rules, reporting rules, protocol handling, taxation logic, and statutory deadlines must be validated against current applicable Indonesian laws, regulations, professional requirements, and actual office procedures.

The development team must not invent legal rules when the specification is incomplete.

---

## 22. Project Success Criteria

The system is successful when office users can quickly determine:

```text
Client
Transaction
Parties
Requirements
Current Stage
Missing Documents
Responsible Staff
Next Action
Deadline
Legal Output
Archive Status
Activity History
```

without relying on separate spreadsheets, chat history, or scattered folders.

---

**Status:** Final baseline v1.0
