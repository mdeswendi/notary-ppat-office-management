# Notary & PPAT Office Management System
## Architecture Specification — v1.0

## 1. Architecture Goal

The system shall use a clear separation between frontend, backend, database, and private file storage.

Core architecture:

```text
Browser
   ↓
Next.js Frontend
   ↓
REST API
   ↓
Laravel Backend
   ├── PostgreSQL
   ├── Redis
   └── Private File Storage
```

The Laravel backend is the authoritative source of business rules.

---

## 2. Repository Strategy

Use one Git repository.

```text
notary-ppat-office-management/
├── frontend/
├── backend/
├── docs/
├── infra/
├── scripts/
├── .github/
├── .editorconfig
├── .gitattributes
├── .gitignore
├── CLAUDE.md
├── README.md
└── docker-compose.yml
```

This is the canonical root structure. It must stay in agreement with
`docs/10_M0_FOUNDATION.md` section 7 and `docs/DECISIONS.md` decision D-003.

`docker-compose.yml` provides local development infrastructure only — PostgreSQL and Redis.
It is not a production deployment specification.

Advantages:

- frontend and backend changes can be reviewed together;
- shared documentation remains centralized;
- Claude can inspect the complete architecture;
- simpler for small-team or solo development;
- easier milestone management.

---

## 3. Frontend Stack

Use:

- Next.js
- React
- TypeScript
- App Router
- Tailwind CSS
- shadcn/ui
- Lucide React
- next-intl
- TanStack Query
- TanStack Table
- React Hook Form
- Zod
- Axios
- date-fns

Package manager:

```text
pnpm
```

Do not mix npm, Yarn, and pnpm.

---

## 4. Backend Stack

Use:

- Laravel
- PHP
- Laravel Sanctum
- Spatie Laravel Permission
- Pest
- Laravel Pint

The backend owns:

- authentication;
- authorization;
- business validation;
- workflow transitions;
- legal record state;
- numbering;
- document access;
- approval;
- finalization;
- audit events.

---

## 5. Database

Use PostgreSQL.

Business-domain IDs should use ULID unless a documented exception exists.

Do not use legal deed numbers as primary keys.

Example:

```text
id = ULID
deed_number = business/legal identifier
```

---

## 6. Redis

Redis may be used for:

- cache;
- queue;
- rate limiting;
- session support if required;
- notifications;
- future background jobs.

M0 should prepare Redis infrastructure without making core features unnecessarily dependent on it.

---

## 7. Private File Storage

Development:

```text
Laravel private local storage
```

Production:

```text
S3-compatible private object storage
```

Never use:

```text
/public/uploads/
```

for legal or identity documents.

The database stores metadata and paths, not large binary files.

---

## 8. Frontend Structure

Recommended:

```text
frontend/
├── public/
├── messages/
│   ├── id.json
│   └── en.json
├── src/
│   ├── app/
│   ├── components/
│   │   ├── ui/
│   │   ├── layout/
│   │   ├── navigation/
│   │   ├── forms/
│   │   ├── workflow/
│   │   ├── documents/
│   │   └── shared/
│   ├── features/
│   │   ├── auth/
│   │   ├── dashboard/
│   │   ├── projects/
│   │   ├── parties/
│   │   ├── companies/
│   │   ├── matters/
│   │   ├── notary/
│   │   ├── ppat/
│   │   ├── properties/
│   │   ├── documents/
│   │   ├── warkah/
│   │   └── tasks/
│   ├── hooks/
│   ├── i18n/
│   ├── lib/
│   ├── providers/
│   ├── services/
│   ├── types/
│   └── config/
├── .env.example
├── next.config.ts
├── package.json
└── tsconfig.json
```

---

## 9. Backend Structure

Preserve Laravel conventions while organizing business logic by domain.

```text
backend/
├── app/
│   ├── Domains/
│   │   ├── Identity/
│   │   ├── Authorization/
│   │   ├── Party/
│   │   ├── Company/
│   │   ├── Project/
│   │   ├── Matter/
│   │   ├── Workflow/
│   │   ├── Document/
│   │   ├── Task/
│   │   ├── Notary/
│   │   ├── PPAT/
│   │   ├── Property/
│   │   ├── Audit/
│   │   └── MasterData/
│   ├── Http/
│   ├── Models/
│   ├── Policies/
│   ├── Providers/
│   └── Support/
├── database/
├── routes/
├── storage/
└── tests/
```

Do not over-customize Laravel structure merely for aesthetics.

---

## 10. Thin Controller Rule

Controllers should primarily:

1. authorize;
2. accept validated request;
3. call Action or Service;
4. return Resource/response.

Avoid large business logic in controllers.

Use when appropriate:

- Form Requests
- Actions
- Services
- DTOs
- Policies
- Resources
- Enums

---

## 11. Business Logic Location

Example:

```text
app/Domains/PPAT/Actions/ValidateMatterForSigning.php
```

Business rules such as:

```text
Can this Matter move to Signing?
```

must be decided by backend domain logic, not the frontend.

---

## 12. Authentication Architecture

Use Laravel Sanctum SPA authentication with cookie/session model for the first-party web app.

Flow:

```text
GET /sanctum/csrf-cookie
POST /login
GET /api/v1/me
```

Logout:

```text
POST /logout
```

Do not store first-party auth tokens in:

```text
localStorage
sessionStorage
```

---

## 13. Local Development URLs

Recommended:

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

Keep development ports stable.

---

## 14. API Versioning

All business API endpoints use:

```text
/api/v1/
```

Examples:

```text
GET /api/v1/health
GET /api/v1/me
GET /api/v1/projects
POST /api/v1/projects
```

---

## 15. Frontend Server State

Use TanStack Query for server state.

Examples:

```text
["auth", "me"]
["projects", filters]
["matter", matterId]
```

Do not introduce Redux or Zustand solely to store API responses.

Use client state tools only when there is a clear need.

---

## 16. HTTP Client

Use one centralized Axios client:

```text
frontend/src/lib/api/client.ts
```

It should configure:

- base URL;
- credentials;
- CSRF/XSRF;
- Accept headers;
- shared error behavior.

Do not create separate Axios instances per feature without a documented reason.

---

## 17. Forms

Use:

```text
React Hook Form
+
Zod
```

Frontend validation improves UX.

Laravel Form Request validation remains authoritative.

---

## 18. Internationalization Architecture

Supported locales:

```text
id
en
```

Default locale:

```text
id
```

Routes:

```text
/id/dashboard
/en/dashboard
/id/projects
/en/projects
```

Do not translate route names.

Static translations:

```text
frontend/messages/id.json
frontend/messages/en.json
```

Dynamic bilingual master data may use:

```text
name_id
name_en
description_id
description_en
```

---

## 19. Design System

Use:

- Tailwind CSS
- shadcn/ui
- semantic color tokens
- Lucide icons
- shared components

Avoid repeated hardcoded hex values.

Example:

```text
bg-primary
text-primary-foreground
border-border
text-muted-foreground
```

---

## 20. Navigation Architecture

Navigation should be configuration-driven.

Recommended:

```text
frontend/src/config/navigation.ts
```

Each item may contain:

```text
key
translationKey
href
icon
requiredPermission
children
```

Menu visibility is permission-based, not role-name-based.

---

## 21. Permission Architecture

Authorization follows:

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

Backend policies are authoritative.

Frontend permission guards only improve UX.

---

## 22. Database Transaction Rule

Critical multi-step actions must run inside transactions.

Example:

```text
Finalize Deed
1. validate
2. assign number
3. update status
4. create register entry
5. lock record
6. write audit event
```

All steps should commit or roll back together.

---

## 23. Numbering

Never use:

```text
MAX(number) + 1
```

for important sequential numbering under concurrent use.

Internal references and legal numbers are different concepts.

Internal references may use patterns such as:

```text
PRJ-2026-000001
N-2026-000001
P-2026-000001
PROP-000001
DOC-2026-000001
```

Legal deed numbering follows separately documented legal/business rules.

---

## 24. Logging

Do not log:

- passwords;
- session cookies;
- CSRF tokens;
- authorization headers;
- private keys;
- full legal document content;
- unnecessary full NIK/NPWP.

Frontend production builds must not contain sensitive debug logging.

---

## 25. Testing

Frontend quality gate:

```text
pnpm lint
pnpm typecheck
pnpm build
```

Backend quality gate:

```text
./vendor/bin/pint --test
php artisan test
```

Backend tests should cover:

- authentication;
- authorization;
- validation;
- workflow transitions;
- critical legal finalization;
- security-sensitive access.

---

## 26. Environment Variables

Frontend `.env.example` may include:

```text
NEXT_PUBLIC_APP_NAME=
NEXT_PUBLIC_API_URL=
```

Never put secrets into `NEXT_PUBLIC_*`.

Backend `.env.example` may include placeholders for:

- application URL;
- frontend URL;
- PostgreSQL;
- Redis;
- queue;
- session;
- mail;
- storage.

Never commit production secrets.

---

## 27. Docker Scope

For local development, Docker Compose may initially run only:

```text
PostgreSQL
Redis
```

Frontend and backend can run natively for easier hot reload.

Optional future local services:

- MinIO;
- Mailpit.

---

## 28. Development Milestones

```text
M0 Foundation
M1 Identity & Access
M2 Party / Individual / Company
M3 Project
M4 Matter & Workflow
M5 Documents & Tasks
M6 Notary
M7 PPAT
M8 Dashboard, Billing & Reports
```

Architecture decisions should not be bypassed by generating future modules prematurely.

---

## 29. M0 Definition of Done

M0 is complete when:

- repository can be set up from README;
- frontend runs;
- backend runs;
- PostgreSQL connection works;
- migrations run;
- `/id/login` works;
- `/en/login` works;
- login establishes session;
- `/api/v1/me` works;
- protected route works;
- logout works;
- bilingual switch works;
- permission foundation exists;
- lint/typecheck/build pass;
- backend formatter/tests pass;
- no business module has been prematurely implemented.

---

**Status:** Final baseline v1.0
