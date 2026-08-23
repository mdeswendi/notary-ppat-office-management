# CLAUDE.md

# Notary & PPAT Office Management System

## 1. Project Overview

This repository contains the **Notary & PPAT Office Management System**, a bilingual Indonesian-English web application for managing operational workflows in an Indonesian Notary and PPAT office.

The application is intended to manage:

* Clients and Parties
* Individuals and Companies
* Projects
* Notary Matters
* PPAT Matters
* Notarial Deeds
* PPAT Deeds
* Drafts
* Minuta Akta
* Warkah
* Land and Property Objects
* Document Requirements
* Document Management
* Tasks
* Workflow
* Calendar
* Billing
* Reports
* Users
* Roles
* Permissions
* Audit Logs
* Office Administration

The system must remain suitable for Indonesian legal-office operations.

Do not simplify the Notary and PPAT domains into a generic CRM.

---

# 2. Current Development Phase

The project is currently being developed incrementally.

Always determine the current milestone before implementing anything.

Development order:

```text
M0  Foundation
M1  Identity & Access Management
M2  Party / Individual / Company
M3  Project Management
M4  Matter & Workflow Engine
M5  Documents & Tasks
M6  Notary Module
M7  PPAT Module
M8  Dashboard, Billing & Reports
```

Do not implement features from a future milestone unless explicitly requested.

---

# 3. Technology Stack

## Frontend

Use:

* Next.js 16.x
* Node.js >= 20.9
* React
* TypeScript
* App Router
* Tailwind CSS
* shadcn/ui
* Lucide React
* next-intl
* TanStack Query
* TanStack Table
* React Hook Form
* Zod
* Axios
* date-fns

Package manager:

```text
pnpm
```

Do not introduce another frontend framework or package manager without explicit approval.

---

## Backend

Use:

* Laravel 13.x
* PHP >= 8.3
* Laravel Sanctum
* Spatie Laravel Permission
* Pest for testing
* Laravel Pint for formatting

Laravel is the authoritative backend.

---

## Database

Use:

* PostgreSQL 18.x, using the latest supported minor release

Do not hardcode a specific PostgreSQL minor release, such as 18.4, as a permanent
application requirement.

---

## Infrastructure

Use:

* Redis 8.x as the development baseline
* private file storage

Never place legal or identity documents in a public web directory.

---

# 4. Overall Architecture

The application architecture is:

```text
Browser
   |
   v
Next.js Frontend
   |
   | REST API
   v
Laravel Backend
   |
   +-- PostgreSQL
   |
   +-- Redis
   |
   +-- Private File Storage
```

Frontend and backend are separate applications inside one repository.

Expected structure:

```text
notary-ppat-office-management/
|
|-- frontend/
|-- backend/
|-- docs/
|-- infra/
|-- scripts/
|-- .github/
|-- .editorconfig
|-- .gitattributes
|-- .gitignore
|-- CLAUDE.md
|-- README.md
`-- docker-compose.yml
```

---

# 5. Source of Truth

Laravel backend is the source of truth for:

* authentication
* authorization
* validation
* workflow transitions
* legal record state
* numbering
* permissions
* document access
* approval rules
* finalization rules
* audit events
* business rules

Never rely on frontend validation or frontend permission checks as the authoritative security mechanism.

Frontend checks exist only for user experience.

---

# 6. Internationalization

The application must support:

```text
id
en
```

Primary/default language:

```text
id
```

Use:

```text
next-intl
```

All reusable user-facing static UI text must use translation keys.

Bad:

```tsx
<Button>Simpan</Button>
```

Good:

```tsx
<Button>{t('actions.save')}</Button>
```

Do not hardcode Indonesian or English labels in reusable components.

---

# 7. Route Localization

Use locale-prefixed routes.

Example:

```text
/id/dashboard
/en/dashboard

/id/projects
/en/projects

/id/notary/matters
/en/notary/matters
```

Do not translate route names.

Bad:

```text
/id/proyek
/en/projects
```

Good:

```text
/id/projects
/en/projects
```

---

# 8. Legal Terminology Rules

This is extremely important.

The application deals with Indonesian legal terminology.

Never translate Indonesian legal terminology literally when doing so changes, weakens, or obscures its legal meaning.

Preserve terms such as:

* PPAT
* AJB
* APHT
* Warkah
* Minuta Akta
* Legalisasi
* Waarmerking
* Repertorium

Follow:

```text
docs/05_I18N_LEGAL_TERMINOLOGY.md
```

when displaying legal terminology.

---

# 9. Legal Terminology Display Examples

Examples:

```text
AJB — Akta Jual Beli
Deed of Sale and Purchase
```

```text
APHT — Akta Pemberian Hak Tanggungan
Deed of Granting Mortgage
```

```text
Warkah
Supporting Legal Documents
```

```text
Minuta Akta
Original Deed Record
```

Terms such as:

```text
PPAT
Waarmerking
Legalisasi
```

should normally retain their original legal terminology.

Do not invent legal translations.

If unsure about a legal term or legal workflow:

1. do not guess;
2. preserve the Indonesian legal term;
3. leave a documented TODO if necessary;
4. request clarification.

---

# 10. Translation Architecture

Static UI text belongs in:

```text
frontend/messages/id.json
frontend/messages/en.json
```

Examples:

* Save
* Cancel
* Dashboard
* Search
* Logout
* Loading

Dynamic/master business data may use bilingual database fields:

```text
name_id
name_en
description_id
description_en
```

Examples:

* Service Types
* Workflow Stages
* Requirement Templates
* Task Templates
* Legal Terminology

Do not put all translations in the database.

---

# 11. Database

Database:

```text
PostgreSQL
```

Business domain primary keys should use:

```text
ULID
```

unless a documented exception exists.

Never use legal document numbers or deed numbers as database primary keys.

Example:

```text
id = ULID
deed_number = legal/business identifier
```

These are different concepts.

---

# 12. Database Status Rules

Store stable machine-readable codes.

Good:

```text
OPEN
IN_PROGRESS
WAITING
ON_HOLD
COMPLETED
CANCELLED
ARCHIVED
```

Bad:

```text
Sedang Diproses
Menunggu Dokumen
Selesai
```

Translated labels belong in the presentation layer.

---

# 13. Avoid Excessive Database Enums

Prefer:

```text
VARCHAR
+
PHP Enum
+
Validation
+
optional CHECK constraint
```

Do not use PostgreSQL native ENUM everywhere unless clearly justified.

---

# 14. Core Domain Model

The core hierarchy is:

```text
Party
  |
Project
  |
Matter
  |
Workflow
  |
Requirements + Tasks + Documents
  |
Notary / PPAT Legal Process
  |
Deed
  |
Minuta Akta / Warkah
  |
Register / Protocol
```

PPAT additionally includes:

```text
Matter
  |
Property
  |
Ownership
```

---

# 15. Project and Matter Rules

A Project is the parent transaction or client engagement.

Example:

```text
Akuisisi Tanah PT ABC
```

One Project may contain multiple Matters.

Example:

```text
Project
|
|-- Notary Matter
|-- Notary Matter
|-- PPAT Matter
`-- PPAT Matter
```

Do not merge Project and Matter into one database entity.

They are also separate **milestones**: M3 implements Project, M4 implements Matter and the
Workflow Engine (D-087). Project is the M3 aggregate root; Matter is a child aggregate with its
own lifecycle.

**M4.0 fixed the cardinality** (D-099): `matters.project_id` is required, one Project may hold
many Matters, and a Project with zero Matters is complete rather than a draft. Matter Office is
inherited from the parent Project and immutable during M4, and **reaching a Project confers no
Matter authority** (D-100) — each is judged by its own capability and its own Data Scope.

The Project architecture lock is `docs/13_M3_PROJECT_ARCHITECTURE.md`.
The Matter and Workflow architecture lock is `docs/14_M4_MATTER_ARCHITECTURE.md`.
The Document and Task architecture lock is `docs/15_M5_DOCUMENT_TASK_ARCHITECTURE.md`.

---

# 16. Notary and PPAT Separation

Notary and PPAT share common infrastructure but are separate business domains.

Shared:

* Party
* Company
* Project
* Document
* Task
* Calendar
* Billing
* User
* Audit

Notary-specific:

* Notary Matter
* Notarial Deed
* Draft
* Minuta Akta
* Legalisasi
* Waarmerking
* Repertorium
* Notary Protocol

PPAT-specific:

* PPAT Matter
* PPAT Deed
* Property
* Warkah
* Taxes
* Deed Register
* PPAT Reports
* PPAT Protocol

Do not combine all Notary and PPAT fields into one generic table.

---

# 17. Party Model

Use a unified Party concept.

Party types:

```text
INDIVIDUAL
COMPANY
```

A person's role belongs to the relationship with a Matter or Project.

Do not permanently label a person as:

```text
SELLER
BUYER
DIRECTOR
COMMISSIONER
```

inside the base Party record.

Example:

```text
Budi Santoso
```

can be:

```text
SELLER
```

in one Matter and:

```text
DIRECTOR
```

in another Matter.

---

# 18. Workflow

Workflow must support templates and instantiated workflow records.

Do not allow historical Matter workflow to change merely because an administrator edits a workflow template.

Use snapshot/version concepts where required.

Matter Status and Workflow Stage are different concepts.

Example:

```text
Matter Status:
IN_PROGRESS

Workflow Stage:
NOTARY_REVIEW
```

Do not merge these concepts.

---

# 19. Documents

Legal documents must use private storage.

Never store legal documents in public web directories.

Bad:

```text
/public/uploads/
```

Documents must support versioning.

Example:

```text
Draft Akta
|
|-- v1
|-- v2
|-- v3
`-- Final
```

Never overwrite an existing document version.

---

# 20. Document Metadata

The database should store metadata such as:

```text
storage_disk
storage_path
original_filename
stored_filename
mime_type
file_size
checksum_sha256
uploaded_by
uploaded_at
version_number
```

Do not store large binary legal documents directly inside PostgreSQL unless explicitly approved.

---

# 21. Sensitive Documents

Examples:

* KTP
* NPWP
* identity documents
* tax documents
* deeds
* certificates
* Minuta Akta
* Warkah

Sensitive files must be:

* privately stored;
* authorization protected;
* audited where appropriate;
* unavailable through predictable public URLs.

---

# 22. Sensitive Fields

Examples:

```text
NIK
NPWP
```

should normally be masked in the interface.

Example:

```text
3174********1234
```

Full access requires a specific permission.

---

# 23. Authentication

For the first-party Next.js frontend, use:

```text
Laravel Sanctum
```

with cookie/session-based SPA authentication.

Do not store authentication tokens in:

```text
localStorage
sessionStorage
```

Authentication flow:

```text
GET /sanctum/csrf-cookie
POST /login
GET /api/v1/me
```

Logout:

```text
POST /logout
```

---

# 24. Authorization

Authorization model:

```text
Role
+
Permission
+
Data Scope
+
Record State
+
Business Rule
```

Never authorize only by role name.

Bad:

```php
if ($user->role === 'PPAT_STAFF') {
    // allow
}
```

Also bad — a permission code is not an authorization surface on its own:

```php
$user->can('ppat.matters.create');
Gate::allows('ppat.matters.create');
$user->hasPermissionTo('ppat.matters.create');
$user->getAllPermissions();
```

These read package state directly. They carry no Data Scope, ignore
`user_permission_overrides`, never check the canonical registry, and count
Spatie's direct user-permission grants, which the authorization model excludes.

Required:

```php
// Controller — a Policy ability, never a permission code
$this->authorize('create', PPATMatter::class);
```

```php
// Policy — delegates to the one resolver
public function create(User $user): bool
{
    return $this->resolver->resolve($user, 'ppat.matters.create')->granted;
}
```

Backend authorization must go through a Policy or a first-party authorization
service backed by `EffectiveAccessResolver`. Never authorize a canonical
permission code directly through `User::can()`, `Gate::allows()`,
`hasPermissionTo()`, `getAllPermissions()`, or a role-name check.

The resolver returns the effective Data Scope set alongside the grant; where the
resource context requires a particular scope, the Policy must check it —
deployment-global records require `ALL`.

See `docs/DECISIONS.md` D-048. This is enforced: the package's generic permission
Gate integration is disabled, so the calls above fail closed, and a test scans
`app/` for them.

---

# 25. Permission Naming

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
ppat.matters.create

ppat.warkah.verify

documents.upload
documents.download
```

---

# 26. Data Scope

Supported authorization scopes:

```text
OWN
ASSIGNED
TEAM
OFFICE
ALL
```

Example:

```text
notary.matters.view
scope = ASSIGNED
```

means the user may view only assigned Matters.

Do not create unnecessary duplicate permissions for every scope.

---

# 27. Permission-Based Navigation

Menu visibility must depend on permission, not role.

Bad:

```tsx
if (role === 'PPAT_STAFF') {
  return <PPATMenu />;
}
```

Preferred concept:

```tsx
if (can('ppat.matters.view')) {
  return <PPATMenu />;
}
```

Backend authorization remains mandatory.

---

# 28. Frontend Permission Guard

Reusable UI permission component may be used.

Example:

```tsx
<PermissionGuard permission="projects.create">
  <Button>
    {t('projects.new')}
  </Button>
</PermissionGuard>
```

Remember:

```text
PermissionGuard is a UI convenience.
It is NOT the security boundary.
```

---

# 29. Legal Record State

Legal records may have lifecycle:

```text
DRAFT
   |
UNDER_REVIEW
   |
APPROVED
   |
FINALIZED
   |
LOCKED
```

Once finalized/locked:

```text
normal update = denied
```

Do not silently edit finalized legal records.

Possible future correction mechanisms:

```text
CORRECTION
AMENDMENT
SUPERSEDE
VOID
```

must follow documented business rules.

---

# 30. Delete Policy

Operational temporary data may use soft delete.

Legal records should generally not be destructively deleted.

Use state such as:

```text
ARCHIVED
CANCELLED
VOID
SUPERSEDED
```

Never add user-facing hard-delete functionality for finalized:

* Deeds
* Minuta Akta
* Warkah
* Registers
* Audit Logs

unless explicitly specified.

---

# 31. Audit Logs

Audit logs are append-only.

Never implement:

```text
audit.update
audit.delete
```

Audit records should be immutable from the application.

Possible fields:

```text
actor
event
resource
old_values
new_values
ip_address
user_agent
reason
created_at
```

Do not log secrets.

---

# 32. Logging Security

Never log:

* passwords
* session cookies
* CSRF tokens
* API secrets
* private keys
* full legal document content
* full NIK unless absolutely necessary
* full NPWP unless absolutely necessary
* authorization headers

Do not leave sensitive `console.log()` statements in production frontend code.

---

# 33. API

All business APIs use:

```text
/api/v1/
```

Example:

```text
GET /api/v1/me
GET /api/v1/projects
POST /api/v1/projects
```

Do not create inconsistent route structures between modules.

---

# 34. API Responses

Single resource:

```json
{
  "data": {}
}
```

Collection:

```json
{
  "data": [],
  "meta": {}
}
```

Validation errors should use Laravel-standard error structures unless otherwise documented.

---

# 35. Backend Structure

Keep controllers thin.

Controllers should primarily:

1. authorize;
2. accept validated input;
3. call an Action or Service;
4. return a Resource.

Use where appropriate:

* Form Requests
* Policies
* Actions
* Services
* DTOs
* Resources
* Enums

Business rules should not live inside controllers.

---

# 36. Business Logic

Bad:

```text
Controller containing hundreds of lines of workflow logic.
```

Preferred:

```text
Domains/
  PPAT/
    Actions/
      ValidateMatterForSigning.php
```

or another appropriate service/action.

---

# 37. Database Transactions

Critical multi-step operations must be transaction-safe.

Example:

```text
Finalize Deed
```

may perform:

```text
validate
assign number
update status
create register
lock record
write audit event
```

These operations should run inside a database transaction.

---

# 38. Numbering

Never generate important sequential numbers using:

```text
MAX(number) + 1
```

This is unsafe under concurrent usage.

Use a proper sequence/locking strategy.

Internal identifiers and legal deed numbers are different concepts.

Examples of internal references:

```text
PRJ-2026-000001
N-2026-000001
P-2026-000001
PROP-000001
DOC-2026-000001
```

These must not automatically be treated as legal deed numbers.

---

# 39. UI Design Principles

Interface personality:

```text
Professional
Legal
Modern
Calm
Structured
Minimal
```

Avoid:

* excessive gradients;
* neon colors;
* excessive animation;
* decorative dashboards;
* oversized cards;
* overly rounded "bubble" UI.

The application is a professional office system.

---

# 40. UI Technology

Use:

```text
Tailwind CSS
shadcn/ui
Lucide
```

Use shared components before creating duplicates.

Potential reusable components:

```text
PageHeader
PageContainer
DataTable
StatusBadge
DomainBadge
WorkflowStepper
RequirementList
DocumentCard
DocumentPreview
MatterHeader
PartyPicker
PropertyPicker
PermissionGuard
SensitiveField
ActivityTimeline
QuickCreate
```

---

# 41. Design Tokens

Use semantic design tokens.

Do not repeatedly hardcode:

```tsx
bg-[#172554]
```

Use semantic classes such as:

```tsx
bg-primary
text-primary-foreground
border-border
text-muted-foreground
```

---

# 42. Domain Accents

Notary:

```text
Navy / Indigo
```

PPAT:

```text
Teal / Emerald
```

Use accents lightly for:

* badges;
* icons;
* subtle section markers.

Do not turn the entire Notary interface blue and PPAT interface green.

---

# 43. Tables

For large data sets use:

```text
TanStack Table
```

Support as appropriate:

* pagination;
* sorting;
* filtering;
* search;
* column visibility.

Do not load unbounded database records into the frontend.

---

# 44. Forms

Use:

```text
React Hook Form
+
Zod
```

Frontend validation improves UX.

Laravel Form Request validation remains authoritative.

Do not duplicate complex business rules in Zod.

---

# 45. Server State

Use:

```text
TanStack Query
```

for remote/server state.

Do not introduce Redux or Zustand simply to store API data.

Add additional state management only when there is a demonstrated need.

---

# 46. HTTP Client

Use one centralized Axios client.

Expected location:

```text
frontend/src/lib/api/client.ts
```

Do not create independent Axios configurations inside each feature.

The client should support:

```text
withCredentials
CSRF/XSRF
base API URL
common error handling
```

---

# 47. Navigation

Do not hardcode all sidebar items directly inside the Sidebar component.

Use configuration.

Example location:

```text
frontend/src/config/navigation.ts
```

Navigation items should include concepts such as:

```text
key
translationKey
href
icon
requiredPermission
children
```

---

# 48. Error Handling

Do not display raw server exceptions.

Frontend should handle common statuses:

```text
401
403
404
419
422
429
500
```

Show user-friendly bilingual messages.

---

# 49. Accessibility

At minimum:

* inputs must have labels;
* keyboard focus should be visible;
* status must not rely only on color;
* buttons need meaningful labels;
* interactive elements should support keyboard navigation;
* dialogs should manage focus appropriately.

---

# 50. Responsive Design

Primary target:

```text
Desktop
Laptop
```

Tablet and mobile must remain usable.

This is a desktop-first office application.

Do not over-engineer complex mobile layouts at the expense of core desktop workflow during MVP.

---

# 51. Testing

Backend changes should have appropriate automated tests.

Use:

```text
Pest
```

At minimum test:

* authentication;
* authorization;
* validation;
* critical business actions;
* workflow transition rules;
* legal finalization rules.

Frontend changes should have appropriate automated tests.

Use:

```text
Vitest + React Testing Library
```

At minimum test:

* permission and navigation gates;
* branch behaviour a type cannot express;
* error mapping, so no raw server text reaches a user;
* controls that must be absent for an actor who may not use them.

**Frontend tests are presentation only.** The backend is the security boundary
(section 28); a passing frontend test never means an endpoint is authorized.

Frontend changes should pass:

```text
pnpm format:check
pnpm lint
pnpm typecheck
pnpm test
pnpm build
```

Backend changes should pass:

```text
./vendor/bin/pint --test
php artisan test
```

---

# 52. Coding Quality

Before declaring a task complete:

Frontend:

```text
pnpm format:check
pnpm lint
pnpm typecheck
pnpm test
pnpm build
```

This list must never be weaker than `.github/workflows/quality.yml`. It was, once:
`format:check` was missing here while CI enforced it, so work that passed every
documented command still failed CI. Adding a gate to the workflow means adding it
here in the same change.

`pnpm test` joined both lists together when O-032 added the runner. **Use `test`,
never `test:watch`** — the watch mode never exits, so a task using it would appear
to hang rather than to pass. CI runs `test:ci`, which is the same single run plus
a coverage report.

Backend:

```text
./vendor/bin/pint --test
php artisan test
```

Fix errors caused by the implementation.

Do not suppress TypeScript or lint errors without documented justification.

---

# 53. TypeScript

Avoid unnecessary:

```typescript
any
```

Prefer:

* explicit types;
* generated/shared API types where appropriate;
* schemas;
* reusable interfaces.

Do not disable TypeScript strictness merely to make code compile.

---

# 54. Security Rules

Never:

* expose database credentials to frontend;
* expose Laravel `APP_KEY`;
* store sensitive secrets in Git;
* expose private document URLs;
* authorize only on frontend;
* bypass CSRF protection;
* trust client-submitted role/permission values;
* disable security middleware merely to make development easier.

---

# 55. Environment Variables

Frontend environment variables prefixed with:

```text
NEXT_PUBLIC_
```

are considered public browser values.

Never place server secrets in them.

Use `.env.example` with placeholders.

Never commit real secrets.

---

# 56. Current User Endpoint

Expected authenticated endpoint:

```text
GET /api/v1/me
```

Conceptual response:

```json
{
  "data": {
    "id": "...",
    "name": "...",
    "email": "...",
    "preferred_locale": "id",
    "roles": [],
    "permissions": []
  }
}
```

Frontend should not need to request each permission individually.

---

# 57. Application Shell

Foundation should support:

```text
AppShell
AppSidebar
AppHeader
GlobalSearch
QuickCreate
Notifications
LocaleSwitcher
UserMenu
PageContainer
PageHeader
```

Do not add fake analytics charts simply to fill the dashboard.

---

# 58. Documentation

Read relevant files inside:

```text
/docs
```

before implementing a feature.

The canonical documentation set:

```text
docs/
├── 00_PROJECT_OVERVIEW.md
├── 01_ARCHITECTURE.md
├── 02_MENU_AND_PERMISSIONS.md
├── 03_DATABASE_ERD.md
├── 04_UI_DESIGN_SYSTEM.md
├── 05_I18N_LEGAL_TERMINOLOGY.md
├── 06_API_CONVENTIONS.md
├── 07_SECURITY_RULES.md
├── 08_NOTARY_WORKFLOW.md
├── 09_PPAT_WORKFLOW.md
├── 10_M0_FOUNDATION.md
├── 11_LEGAL_REFERENCES.md
├── 12_M2_PARTY_ARCHITECTURE.md
├── 13_M3_PROJECT_ARCHITECTURE.md
├── 14_M4_MATTER_ARCHITECTURE.md
├── 15_M5_DOCUMENT_TASK_ARCHITECTURE.md
├── DECISIONS.md
└── CHANGELOG.md
```

`12_`, `13_`, `14_` and `15_` are milestone architecture locks. Each records what its domain may
build, what it must not, and which statements are transcribed from canonical sources rather than
decided locally. Read the lock for the domain you are working in before changing it.

`DECISIONS.md` records canonical decisions that resolve conflicts in the source material.
When older material conflicts with `DECISIONS.md`, the newer explicit decision takes
precedence unless later superseded.

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are `DRAFT — DOMAIN VALIDATION REQUIRED`
and MUST NOT be used to implement legal workflows until validated.

`11_LEGAL_REFERENCES.md` is a statutory reference register only. It confers no operational
rules and does not define software workflow.

If source code and documentation conflict:

1. identify the conflict;
2. do not silently choose one;
3. report the discrepancy before making an architectural change.

---

# 59. Development Workflow for Claude

Before implementing any task:

1. Read `CLAUDE.md`.
2. Read relevant files in `/docs`.
3. Inspect the existing code.
4. Understand the current milestone.
5. Identify files affected.
6. Make the smallest coherent implementation.
7. Add or update tests.
8. Run formatter.
9. Run tests.
10. Run lint/typecheck/build where applicable.
11. Report the result.

Do not make unrelated architectural changes.

---

# 60. Scope Discipline

Never implement a module merely because it appears in the project specification.

Implement only the feature explicitly requested for the current task.

Example:

If the current task is:

```text
M0 — Authentication Foundation
```

do not additionally implement:

```text
Projects
Clients
PPAT Matters
Warkah
Billing
Dashboard analytics
```

without explicit instruction.

---

# 61. No Speculative Tables

Do not create speculative migrations or tables for future modules unless requested.

The database architecture is planned, but migrations should be introduced milestone by milestone.

---

# 62. No Invented Legal Rules

This is a critical rule.

Do not invent:

* PPAT legal procedures;
* Notary approval requirements;
* required Warkah;
* deed numbering rules;
* tax rules;
* registration deadlines;
* legal document requirements.

When the specification does not define a legal rule:

```text
STOP
DOCUMENT THE GAP
ASK FOR DOMAIN SPECIFICATION
```

instead of guessing.

---

# 63. Preserve Historical Data

When designing:

* company management history;
* ownership history;
* workflow history;
* document versions;
* deed state;
* audit records;

do not overwrite historical records merely because the current state changes.

History is important for this system.

---

# 64. Finalized Data

When a record becomes legally finalized:

* prevent normal edits;
* show it as locked/read-only;
* preserve original values;
* preserve related document versions;
* audit important actions.

Do not implement "Edit" as a universal action.

---

# 65. Performance

Avoid obvious N+1 database queries.

Use:

* pagination;
* eager loading where appropriate;
* indexes for frequent lookup;
* query scopes;
* resource serialization.

Do not prematurely optimize before a real need exists.

---

# 66. Search

Global search will eventually include:

```text
Projects
Matters
Individuals
Companies
Properties
Documents
Notarial Deeds
PPAT Deeds
```

Do not build full global search unless explicitly requested.

PostgreSQL Full Text Search may be used later.

---

# 67. Commit Discipline

Prefer small meaningful changes.

Example commit style:

```text
chore: initialize frontend
chore: initialize Laravel backend
feat: configure bilingual routing
feat: add Sanctum authentication
feat: add application shell
docs: add legal terminology guide
```

Do not describe one massive unrelated change as:

```text
finished app
```

---

# 68. Current Project Principle

The application must always help office users answer:

```text
Siapa kliennya?
Urusannya apa?
Dokumennya sudah lengkap?
Sekarang prosesnya sampai mana?
Siapa yang harus mengerjakan berikutnya?
Kapan target atau deadline-nya?
```

If a proposed UI or feature makes these questions harder to answer, reconsider the implementation.

---

# 69. Final Instruction

When given a coding request:

* do not immediately generate large amounts of code;
* first inspect the relevant existing files;
* stay within current milestone;
* follow the project specifications;
* preserve architectural consistency;
* preserve Indonesian legal terminology;
* treat security and document privacy as first-class requirements;
* do not invent legal business rules.

The goal is not to build quickly at any cost.

The goal is to build a stable, maintainable, secure, bilingual **Notary & PPAT Office Management System** that can safely grow module by module.
