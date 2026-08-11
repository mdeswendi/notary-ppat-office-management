# Notary & PPAT Office Management System
## M0 — Foundation Implementation Specification v1.0

**Baseline date:** 8 August 2026
**Purpose:** Establish a stable development foundation before implementing business modules.
**Status:** Specification only. M0 has not been executed.

Where this document conflicts with `docs/DECISIONS.md`, the decision recorded in
`docs/DECISIONS.md` takes precedence.

---

## 1. M0 Objective

M0 does not aim to build Notary or PPAT features first.

M0 must produce:

```text
✓ Repository structure
✓ Frontend application
✓ Backend API
✓ PostgreSQL connection
✓ Authentication foundation
✓ Authorization foundation
✓ Bilingual infrastructure
✓ UI design tokens
✓ Base application shell
✓ Logging
✓ Testing foundation
✓ Code quality tools
✓ Environment configuration
✓ AI coding instructions
✓ Development documentation
```

After M0 is complete, the application must at minimum be able to:

```text
Open application
   ↓
Login
   ↓
Laravel authenticates user
   ↓
Next.js receives current user
   ↓
Display protected application shell
   ↓
Switch ID ↔ EN
   ↓
Logout
```

Not yet present:

```text
Projects
Notary Matters
PPAT Matters
Clients
Property
Warkah
Deeds
```

Those modules begin only after the foundation is stable.

---

## 2. Technology Baseline

### Frontend

```text
Next.js 16.x
React
TypeScript
Tailwind CSS
shadcn/ui
Lucide React
next-intl
TanStack Query
TanStack Table
React Hook Form
Zod
Axios
```

Minimum Node:

```text
Node.js >= 20.9
```

Recommended: current supported Node LTS.

---

## 3. Backend

```text
Laravel 13.x
PHP >= 8.3
Laravel Sanctum
Spatie Laravel Permission
PostgreSQL
```

Development tools:

```text
Laravel Pint
PHPUnit or Pest
```

For this project use:

```text
Pest
```

because the test syntax is more concise.

---

## 4. Database

Production baseline:

```text
PostgreSQL 18.x
```

Always use the latest supported minor release available on major 18. Do not pin a specific
minor release as a permanent application requirement.

Development database name:

```text
notary_ppat_office
```

Testing:

```text
notary_ppat_office_test
```

Encoding and timezone rules are defined in `docs/03_DATABASE_ERD.md` section 1.

---

## 5. Cache / Queue

Foundation:

```text
Redis
```

Used later for:

```text
Cache
Queue
Rate limiting
Session, if required
Notifications
```

M0 business features must not depend critically on Redis.

---

## 6. Storage

M0:

```text
Laravel private local storage
```

Not:

```text
public/uploads
```

Future production:

```text
S3-compatible private object storage
```

---

## 7. Repository Strategy

Use a single Git repository.

Folder name:

```text
notary-ppat-office-management/
```

Structure:

```text
notary-ppat-office-management/
│
├── frontend/
│
├── backend/
│
├── docs/
│
├── infra/
│
├── scripts/
│
├── .github/
│
├── .editorconfig
├── .gitattributes
├── .gitignore
├── CLAUDE.md
├── README.md
└── docker-compose.yml
```

Two separate repositories are not used at this stage.

Advantages:

```text
Frontend + backend changes can be reviewed together
Claude can read the complete architecture
Documentation stays in one place
Simpler for solo/small-team development
```

---

## 8. Frontend Folder Structure

```text
frontend/
│
├── public/
│
├── src/
│   │
│   ├── app/
│   │   ├── [locale]/
│   │   │   ├── (auth)/
│   │   │   │   └── login/
│   │   │   │
│   │   │   └── (dashboard)/
│   │   │       ├── dashboard/
│   │   │       └── layout.tsx
│   │   │
│   │   └── layout.tsx
│   │
│   ├── components/
│   │   ├── ui/
│   │   ├── layout/
│   │   ├── navigation/
│   │   ├── forms/
│   │   └── shared/
│   │
│   ├── features/
│   │   ├── auth/
│   │   ├── permissions/
│   │   └── localization/
│   │
│   ├── hooks/
│   │
│   ├── i18n/
│   │   ├── routing.ts
│   │   ├── request.ts
│   │   └── navigation.ts
│   │
│   ├── lib/
│   │   ├── api/
│   │   ├── auth/
│   │   ├── permissions/
│   │   ├── constants/
│   │   └── utils/
│   │
│   ├── providers/
│   │
│   ├── services/
│   │
│   ├── types/
│   │
│   └── config/
│
├── messages/
│   ├── id.json
│   └── en.json
│
├── .env.example
├── components.json
├── next.config.ts
├── package.json
├── tsconfig.json
└── README.md
```

---

## 9. Backend Folder Strategy

Preserve the Laravel default structure as far as possible; organize business domains
modularly on top of it.

```text
backend/
│
├── app/
│   ├── Domains/
│   │   ├── Identity/
│   │   ├── Authorization/
│   │   └── Shared/
│   │
│   ├── Http/
│   ├── Models/
│   ├── Policies/
│   ├── Providers/
│   └── Support/
│
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│
├── routes/
│   ├── api.php
│   └── web.php
│
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
│
├── .env.example
├── composer.json
└── README.md
```

Do not relocate all Laravel files into a custom architecture without reason.

Use the domain structure only for our own business logic.

---

## 10. Future Backend Domain Structure

After M0:

```text
app/Domains/
│
├── Identity/
├── Authorization/
├── Party/
├── Company/
├── Project/
├── Matter/
├── Workflow/
├── Document/
├── Task/
├── Notary/
├── PPAT/
├── Property/
├── Audit/
└── MasterData/
```

M0 requires only:

```text
Identity
Authorization
Shared
```

---

## 11. Package Manager

Frontend uses:

```text
pnpm
```

Reasons:

```text
Fast
Deterministic lockfile
Efficient dependency storage
Good modern ecosystem support
```

Lockfile:

```text
pnpm-lock.yaml
```

Must be committed to Git.

Do not mix `npm`, `yarn`, and `pnpm` in the repository.

---

## 12. Create Frontend

From the project root:

```bash
pnpm create next-app@latest frontend --ts --tailwind --eslint --app --src-dir --import-alias "@/*"
```

Then:

```bash
cd frontend
```

---

## 13. Initialize shadcn/ui

```bash
pnpm dlx shadcn@latest init
```

Use:

```text
TypeScript
CSS Variables
Lucide icons
src directory
@/* alias
```

Add the foundation components:

```bash
pnpm dlx shadcn@latest add button input label card badge avatar dropdown-menu sheet dialog alert tooltip separator skeleton breadcrumb select textarea checkbox tabs table command popover
```

Do not run:

```bash
shadcn add --all
```

Only add components that are actually used.

---

## 14. Frontend Dependencies

Install:

```bash
pnpm add next-intl
pnpm add @tanstack/react-query
pnpm add @tanstack/react-table
pnpm add react-hook-form
pnpm add @hookform/resolvers
pnpm add zod
pnpm add axios
pnpm add lucide-react
pnpm add date-fns
```

Development:

```bash
pnpm add -D prettier prettier-plugin-tailwindcss
```

---

## 15. Create Laravel Backend

From the repository root:

```bash
laravel new backend
```

Choose:

```text
Database: PostgreSQL
Starter Kit: None
Testing: Pest
```

No Laravel frontend starter kit is used, because the frontend is Next.js.

---

## 16. Install API Authentication

Backend:

```bash
cd backend
php artisan install:api
```

Use Laravel Sanctum.

Architecture:

```text
Browser
   ↓
Next.js
   ↓
Cookie-based session
   ↓
Laravel Sanctum
   ↓
Protected API
```

Do not store login tokens in:

```text
localStorage
sessionStorage
```

for the first-party web application.

---

## 17. Install Permission Package

```bash
composer require spatie/laravel-permission
```

Publish the config/migration as documented by the package, then:

```bash
php artisan migrate
```

Role and permission seeds are created in M1.

---

## 18. Authentication Decision

Ideal production domains:

```text
app.example.com
api.example.com
```

Both on the same top-level domain.

Concrete example later:

```text
app.notaryoffice.id
api.notaryoffice.id
```

or via reverse proxy:

```text
office.example.com
office.example.com/api
```

---

## 19. Local Development URLs

```text
Frontend:
http://localhost:3000

Backend:
http://localhost:8000

PostgreSQL:
localhost:5432

Redis:
localhost:6379
```

Do not change ports frequently during development.

---

## 20. Axios API Client

Create one centralized client:

```text
src/lib/api/client.ts
```

All requests must go through this client.

Configuration:

```text
baseURL = NEXT_PUBLIC_API_URL
withCredentials = true
withXSRFToken = true
Accept = application/json
```

Do not create a new Axios configuration inside each feature.

---

## 21. Login Sequence

```text
User submits login
   ↓
GET /sanctum/csrf-cookie
   ↓
POST /login
   ↓
Session established
   ↓
GET /api/v1/me
   ↓
Store user in application state/query cache
   ↓
Redirect /dashboard
```

Logout:

```text
POST /logout
   ↓
clear client cache
   ↓
redirect /login
```

---

## 22. API Prefix

All business APIs:

```text
/api/v1/
```

M0 examples:

```text
GET /api/v1/me
GET /api/v1/permissions
GET /api/v1/health
```

Later:

```text
GET  /api/v1/projects
POST /api/v1/projects
```

Versioning must be prepared from the start.

---

## 23. API Response Convention

Success, single resource:

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

Error:

```json
{
  "message": "Validation failed.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

Do not use different API formats between modules.

---

## 24. HTTP Status Convention

```text
200 OK
201 Created
204 No Content

400 Bad Request
401 Unauthenticated
403 Forbidden
404 Not Found
409 Conflict
422 Validation Error
429 Too Many Requests
500 Internal Server Error
```

---

## 25. Global Error Handling

The frontend must recognize:

```text
401
→ redirect login

403
→ permission denied state

404
→ not found

419
→ session/CSRF expired

422
→ attach validation errors to form

500
→ generic server error
```

Raw backend exceptions must never be shown to users.

---

## 26. Internationalization Foundation

Supported locales:

```text
id
en
```

Default:

```text
id
```

Locale routing:

```text
/id/dashboard
/en/dashboard
```

URL resources stay English/internal:

```text
/id/projects
/en/projects
```

Do not use:

```text
/id/proyek
/en/projects
```

---

## 27. Translation Files

```text
frontend/messages/id.json
frontend/messages/en.json
```

Initial structure:

```json
{
  "common": {},
  "auth": {},
  "navigation": {},
  "dashboard": {},
  "actions": {},
  "status": {},
  "validation": {},
  "legal": {}
}
```

---

## 28. Initial Indonesian Messages

```json
{
  "common": {
    "appName": "Notary & PPAT Office Management System",
    "loading": "Memuat...",
    "search": "Cari",
    "noData": "Belum ada data"
  },
  "actions": {
    "save": "Simpan",
    "cancel": "Batal",
    "edit": "Edit",
    "delete": "Hapus",
    "archive": "Arsipkan",
    "close": "Tutup"
  },
  "auth": {
    "login": "Masuk",
    "logout": "Keluar",
    "email": "Email",
    "password": "Kata Sandi",
    "rememberMe": "Ingat saya",
    "forgotPassword": "Lupa kata sandi?"
  }
}
```

---

## 29. Initial English Messages

```json
{
  "common": {
    "appName": "Notary & PPAT Office Management System",
    "loading": "Loading...",
    "search": "Search",
    "noData": "No data yet"
  },
  "actions": {
    "save": "Save",
    "cancel": "Cancel",
    "edit": "Edit",
    "delete": "Delete",
    "archive": "Archive",
    "close": "Close"
  },
  "auth": {
    "login": "Sign In",
    "logout": "Sign Out",
    "email": "Email",
    "password": "Password",
    "rememberMe": "Remember me",
    "forgotPassword": "Forgot password?"
  }
}
```

Note:

```text
Masuk → Sign In
```

is not required to be a literal one-to-one translation.

---

## 30. Legal Terminology Rule

The legal terminology dictionary lives in:

```text
docs/05_I18N_LEGAL_TERMINOLOGY.md
```

Initial dictionary:

```text
PPAT
Preserve: YES

AJB
Primary: AJB — Akta Jual Beli
English explanation: Deed of Sale and Purchase

APHT
Primary: APHT — Akta Pemberian Hak Tanggungan
English explanation: Deed of Granting Mortgage

Warkah
Preserve: YES
English explanation: Supporting Legal Documents

Minuta Akta
Preserve primary Indonesian term
English explanation: Original Deed Record

Waarmerking
Preserve: YES

Legalisasi
Preserve: YES

Repertorium
Preserve: YES
English explanation: Notary Register
```

Claude must not create new legal translations outside this dictionary.

---

## 31. Design Tokens

All visuals use semantic CSS variables.

Do not repeatedly hardcode:

```text
bg-[#172554]
```

Use:

```text
bg-primary
text-primary-foreground
```

---

## 32. Core Color Tokens

Light theme:

```text
background
foreground

card
card-foreground

primary
primary-foreground

secondary
secondary-foreground

muted
muted-foreground

border
input
ring

destructive
destructive-foreground
```

Add:

```text
success
warning
info

notary
ppat
```

---

## 33. Domain Accent

```text
Notary:
navy / indigo accent

PPAT:
teal / emerald accent
```

Accent is used only for:

```text
DomainBadge
small icon
section marker
subtle border
```

Do not use it as a full-page background.

---

## 34. Theme

M0:

```text
Light theme required
```

Dark mode:

```text
architecture-ready
not required for MVP
```

Do not spend M0 time perfecting dark mode.

---

## 35. App Shell Components

M0 must produce:

```text
AppShell
AppSidebar
AppHeader
GlobalSearchButton
QuickCreateButton
NotificationButton
LocaleSwitcher
UserMenu
PageContainer
PageHeader
```

Full business menu content is not required yet.

---

## 36. Sidebar Foundation

M0 sidebar:

```text
Dashboard

──────────
Notary & PPAT Office
```

The navigation config may already list future menus:

```text
Projects
Notary
PPAT
Clients & Parties
Documents
Tasks
Calendar
```

but routes that do not yet exist must not be active.

---

## 37. Navigation Configuration

Do not write menu items directly in the Sidebar JSX.

Create:

```text
src/config/navigation.ts
```

Concept:

```text
key
translationKey
href
icon
requiredPermission
children
```

The sidebar reads this configuration.

---

## 38. PermissionGuard

Create a reusable component:

```text
PermissionGuard
```

Concept:

```tsx
<PermissionGuard permission="projects.create">
  <Button>...</Button>
</PermissionGuard>
```

But:

```text
Frontend PermissionGuard ≠ Security
```

Backend Policy/Gate remains authoritative.

---

## 39. Current User Contract

Endpoint:

```text
GET /api/v1/me
```

Response concept:

```json
{
  "data": {
    "id": "...",
    "name": "Rina",
    "email": "rina@example.com",
    "preferred_locale": "id",
    "roles": [],
    "permissions": []
  }
}
```

The frontend must not need to request permissions one by one.

---

## 40. Auth State

Use TanStack Query for server state.

Key:

```text
["auth", "me"]
```

Do not store all server state in Redux, Zustand, or Context without need.

Context is used only for genuinely UI-local state.

---

## 41. Query Client

Create:

```text
QueryProvider
```

Default strategy:

```text
reasonable stale time
disable excessive automatic refetch
retry selectively
```

For sensitive operations, always refetch/invalidate after mutation.

---

## 42. Form Foundation

Use:

```text
React Hook Form
+
Zod
```

Pattern:

```text
schema
form type
default values
submit handler
backend validation mapping
```

Do not build a different custom validation approach per form when it can be reused.

---

## 43. Backend Validation

Frontend Zod is not the source of truth.

Laravel Form Requests remain mandatory.

Examples:

```text
LoginRequest
CreateProjectRequest
```

Business validation lives in the backend.

---

## 44. User Model Foundation

M0 minimum user columns:

```text
id
office_id            nullable initially if needed
name
email
password
preferred_locale
is_active
last_login_at
email_verified_at
created_at
updated_at
```

If the organization/office migration does not exist yet at the start of M0, do not create a
fake foreign key. Correct migration order is preferable.

> **Cross-milestone note, added 2026-08-09.** M0 followed this guidance and omitted
> `office_id` entirely rather than adding a nullable column pointing at a table that did
> not exist. The "nullable initially if needed" allowance above described the M0 position
> and is now superseded for M1: **D-027** makes `users.office_id` required for operational
> users, since `offices` arrives in M1.1 and the `users` table holds no persistent user.
> This note records the transition; it does not alter what M0 delivered.

---

## 45. ULID

For our business-domain entities use:

```text
ULID
```

For tables owned by third-party packages, do not force ULID if it destabilizes package
integration without need.

Rule:

```text
Our domain models → ULID

Third-party internal tables
→ follow package convention unless deliberately customized
```

---

## 46. PostgreSQL Local Infrastructure

Recommended Docker Compose services:

```text
postgres
redis
```

Example environment:

```text
POSTGRES_DB=notary_ppat_office
POSTGRES_USER=notary_app
POSTGRES_PASSWORD=local-development-only
```

Development credentials must never be used in production.

---

## 47. docker-compose Scope

M0 Docker Compose does not need to run the frontend/backend if the local runtime is more
convenient.

Enough:

```text
PostgreSQL
Redis
```

Optional later:

```text
MinIO
Mailpit
```

Advantages:

```text
VS Code stays fast
Native frontend hot reload
Native Laravel development
Consistent database environment
```

---

## 48. Backend Environment Example

`backend/.env.example` must contain placeholders, never real secrets.

```text
APP_NAME="Notary & PPAT Office Management System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

FRONTEND_URL=http://localhost:3000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=notary_ppat_office
DB_USERNAME=notary_app
DB_PASSWORD=

SESSION_DRIVER=database

CACHE_STORE=redis
QUEUE_CONNECTION=database

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

---

## 49. Frontend Environment Example

`frontend/.env.example`

```text
NEXT_PUBLIC_APP_NAME="Notary & PPAT Office Management System"
NEXT_PUBLIC_API_URL=http://localhost:8000
```

Do not put into frontend environment:

```text
database password
Laravel APP_KEY
private API secret
```

Every `NEXT_PUBLIC_*` variable is considered visible in the browser.

---

## 50. CORS / Sanctum Configuration

Development stateful domains must recognize:

```text
localhost:3000
localhost:8000
```

Production:

```text
frontend domain
API domain
```

CORS credentials must be enabled for Sanctum SPA authentication.

Production cookies:

```text
Secure
HttpOnly for session
SameSite configured appropriately
```

---

## 51. Rate Limiting

M0 minimum: the login endpoint must have rate limiting.

Future candidates:

```text
Document download
Global search
Sensitive data reveal
API intensive endpoint
```

---

## 52. Password Policy

M0 baseline:

```text
minimum reasonable length
compromised/common password protection where practical
password confirmation for sensitive account actions
```

Do not impose rules such as:

```text
must contain 1 symbol + 1 uppercase + 1 digit
```

excessively, if they only produce guessable passwords.

MFA is prepared in the M1/security milestone.

---

## 53. Audit Foundation

M0 does not yet need to audit every model.

But the documentation must establish:

```text
Audit log is append-only.

Never:
audit.update
audit.delete
```

Authentication events that may be recorded later:

```text
LOGIN_SUCCESS
LOGIN_FAILED
LOGOUT
PASSWORD_CHANGED
MFA_ENABLED
```

---

## 54. Logging

Backend:

```text
Laravel structured application logs
```

Do not log:

```text
password
session cookie
full NIK
full NPWP
document contents
authorization header
```

Frontend production must not leave behind:

```text
console.log(user)
console.log(document)
```

carrying sensitive data.

---

## 55. Security Headers

The foundation must be ready for:

```text
Content-Security-Policy
X-Content-Type-Options
Referrer-Policy
frame protection
HTTPS
```

Production requires HTTPS.

---

## 56. Formatting — Frontend

Use:

```text
ESLint
Prettier
prettier-plugin-tailwindcss
```

Commands:

```bash
pnpm lint
pnpm format
pnpm typecheck
pnpm build
```

Add the `typecheck` script:

```bash
tsc --noEmit
```

---

## 57. Formatting — Backend

Use Laravel Pint.

Commands:

```bash
./vendor/bin/pint
php artisan test
```

Code produced by Claude must pass the formatter and the tests.

---

## 58. Testing Foundation

M0 frontend:

```text
TypeScript compilation
Lint
Build
```

A UI test framework may be added when the first feature begins.

M0 backend:

```text
Health endpoint test
Authentication test
Protected route test
Logout test
Permission foundation test
```

---

## 59. Health Endpoint

```text
GET /api/v1/health
```

Response:

```json
{
  "status": "ok"
}
```

Must not expose:

```text
database credentials
environment variables
server paths
package secrets
```

---

## 60. Git Strategy

Initial branch:

```text
main
```

Development branches:

```text
feat/m0-foundation
feat/m1-identity
feat/m2-parties
feat/m3-projects
```

Small, meaningful commits:

```text
chore: initialize Next.js frontend
chore: initialize Laravel backend
feat: configure bilingual routing
feat: add Sanctum authentication
feat: add application shell
docs: add Claude coding instructions
```

Never a single commit named:

```text
finished app
```

---

## 61. README Root

`README.md` must at minimum contain:

```text
Project overview
Technology stack
Repository structure
Requirements
Local setup
Environment configuration
Development commands
Testing commands
Architecture links
```

---

## 62. Documentation Folder

Created from M0 onward:

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
├── DECISIONS.md
└── CHANGELOG.md
```

---

## 63. CLAUDE.md

`CLAUDE.md` at the repository root is the coding constitution for the assistant.

The authoritative version is the file already present at the repository root. An earlier,
shorter draft exists in the source material for this specification; it is superseded and
must not be reintroduced.

---

## 64. Claude Task Rule

Do not give Claude:

```text
Build the Notary application.
```

Use bounded tasks:

```text
Implement only the M0 frontend application shell.

Do not create Project, Matter, Party, Notary, PPAT,
Property, Document, or Warkah business features.

Follow CLAUDE.md and docs/04_UI_DESIGN_SYSTEM.md.
```

---

## 65. First Claude Prompt

Once the empty project folder exists and `CLAUDE.md` is available, the first prompt:

```text
Read CLAUDE.md and all files in /docs before making changes.

We are implementing M0 Foundation only for the
Notary & PPAT Office Management System.

First inspect the repository and report:
1. current project structure,
2. missing prerequisites,
3. files you intend to create or modify.

Then implement only the foundation setup:

Frontend:
- Next.js App Router with TypeScript and src directory
- Tailwind CSS
- shadcn/ui foundation
- next-intl with locales id and en
- locale routes /id and /en
- TanStack Query provider
- centralized Axios API client
- base design tokens
- basic AppShell
- sidebar
- header
- locale switcher
- user menu placeholder
- protected dashboard route placeholder

Backend:
- Laravel API foundation
- PostgreSQL configuration
- Sanctum SPA authentication
- /api/v1/health
- /api/v1/me
- login/logout endpoints
- Spatie permission package foundation

Rules:
- Do not implement Projects or any business module yet.
- Do not invent legal workflows.
- Do not hardcode UI strings.
- Do not store auth tokens in browser storage.
- Do not expose sensitive configuration.
- Keep controllers thin.
- Add appropriate tests.
- Run formatter, tests, lint, typecheck, and build.
- Stop and report any prerequisite that cannot be safely resolved.

At completion, report:
1. files created,
2. files modified,
3. commands run,
4. test results,
5. remaining M0 issues.
```

---

## 66. Better Execution Strategy

Even though the prompt above describes M0 as a whole, execution should be split:

```text
M0.1  Repository & environment
M0.2  Frontend initialization
M0.3  Backend initialization
M0.4  PostgreSQL & Redis
M0.5  i18n
M0.6  design system
M0.7  authentication
M0.8  authorization foundation
M0.9  application shell
M0.10 tests & documentation
```

Do not let the assistant work through all of M0 without checkpoints.

---

## 67. M0.1 — Repository

Acceptance:

```text
✓ Git repository initialized
✓ root folders created
✓ root README
✓ CLAUDE.md
✓ docs directory
✓ .editorconfig
✓ .gitignore
```

---

## 68. M0.2 — Frontend

Acceptance:

```text
✓ Next.js runs
✓ TypeScript works
✓ Tailwind works
✓ shadcn initialized
✓ lint passes
✓ typecheck passes
✓ build passes
```

---

## 69. M0.3 — Backend

Acceptance:

```text
✓ Laravel runs
✓ API route works
✓ Pest works
✓ Pint works
✓ APP_KEY configured locally
```

---

## 70. M0.4 — Infrastructure

Acceptance:

```text
✓ PostgreSQL reachable
✓ Laravel database connection works
✓ Redis reachable
✓ migrations run successfully
```

---

## 71. M0.5 — i18n

Acceptance:

```text
✓ /id available
✓ /en available
✓ locale switch works
✓ refresh preserves locale
✓ static labels use translation keys
✓ Indonesian is default
```

---

## 72. M0.6 — UI Foundation

Acceptance:

```text
✓ semantic tokens
✓ PageContainer
✓ AppShell
✓ Sidebar
✓ Header
✓ language switch
✓ loading skeleton
✓ base error state
```

---

## 73. M0.7 — Authentication

Acceptance:

```text
✓ CSRF cookie initialized
✓ login works
✓ session survives page refresh
✓ /api/v1/me works
✓ protected routes redirect anonymous users
✓ logout works
✓ invalid login handled cleanly
✓ no auth token stored in localStorage
```

---

## 74. M0.8 — Authorization

Acceptance:

```text
✓ Spatie package configured
✓ User supports roles/permissions
✓ /me exposes effective permissions
✓ PermissionGuard component exists
✓ backend authorization remains authoritative
```

The complete role seed is created in M1.

---

## 75. M0.9 — Application Shell

After login:

```text
┌──────────────────────────────────────────────────────┐
│ Search        + New      Notifications   ID   User   │
├─────────────┬────────────────────────────────────────┤
│ Dashboard   │                                        │
│             │        Dashboard Placeholder           │
│             │                                        │
└─────────────┴────────────────────────────────────────┘
```

No dummy charts yet.

---

## 76. M0.10 — Quality Gate

Before M0 can be declared complete:

Frontend:

```bash
pnpm lint
pnpm typecheck
pnpm build
```

Backend:

```bash
./vendor/bin/pint --test
php artisan test
```

Database:

```text
migration fresh succeeds
```

Authentication:

```text
login/logout integration test passes
```

---

## 77. Definition of Done — M0

M0 is complete only when:

```text
[ ] Fresh clone can be set up from README
[ ] PostgreSQL can be started
[ ] Backend migration succeeds
[ ] Backend tests pass
[ ] Frontend install succeeds
[ ] Frontend build passes
[ ] /id/login works
[ ] /en/login works
[ ] login establishes a secure session
[ ] dashboard is protected
[ ] language switching works
[ ] logout works
[ ] permission architecture exists
[ ] no hardcoded auth secret
[ ] no first-party token in localStorage
[ ] no business module implemented prematurely
[ ] CLAUDE.md exists
[ ] docs structure exists
```

---

## 78. What Comes After M0

Once M0 is declared stable:

```text
M1
Identity & Access Management
```

M1 covers:

```text
Organization
Office

Users
Roles
Permissions
Data Scope

User Management
Role Management
Permission Matrix

Profile
Preferred Language
Account Security
```

Then:

```text
M2
Party / Individual / Company
```

then:

```text
M3
Projects
```

This order must not be reversed without a strong architectural reason.

---

## 79. M0 Final Architecture

```text
BROWSER
   │
   ↓
NEXT.JS 16
   │
   ├── next-intl
   ├── shadcn/ui
   ├── TanStack Query
   ├── React Hook Form
   └── Axios
   │
   ↓
SANCTUM SESSION AUTH
   │
   ↓
LARAVEL 13 API
   │
   ├── Validation
   ├── Authorization
   ├── Domain Logic
   └── Audit Foundation
   │
   ├──────── PostgreSQL 18
   │
   ├──────── Redis
   │
   └──────── Private Storage
```

---

## 80. Architecture Status After This Specification

Decisions now frozen:

```text
Repository:
Single repository

Frontend:
Next.js

Backend:
Laravel API

Database:
PostgreSQL

Authentication:
Sanctum cookie/session

Authorization:
Spatie + Policies + custom Data Scope

Frontend server state:
TanStack Query

HTTP Client:
Axios centralized client

Bilingual:
next-intl

Languages:
Indonesian + English

Primary UI language:
Indonesian

Legal terminology:
Original Indonesian legal terms preserved where required

UI:
Tailwind + shadcn/ui

Files:
Private and versioned

Development:
AI-assisted but specification-driven
```

This document is the implementation baseline for **M0 — Foundation** of the Notary & PPAT
Office Management System.

---

**Status:** Final baseline v1.0 — not yet executed
