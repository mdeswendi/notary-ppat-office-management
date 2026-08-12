# Notary & PPAT Office Management System
## API Conventions — v1.0

## 1. Purpose

This document defines shared conventions for the Laravel REST API consumed by the Next.js frontend.

---

## 2. Base API Prefix

All business APIs use:

```text
/api/v1/
```

Examples:

```text
GET  /api/v1/health
GET  /api/v1/me
GET  /api/v1/projects
POST /api/v1/projects
GET  /api/v1/projects/{id}
```

---

## 3. Authentication

Use Laravel Sanctum for first-party SPA authentication.

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

Do not store auth tokens in browser localStorage or sessionStorage for the first-party web app.

---

## 4. Current User Endpoint

```text
GET /api/v1/me
```

Conceptual response:

```json
{
  "data": {
    "id": "01...",
    "name": "Rina",
    "email": "rina@example.com",
    "preferred_locale": "id",
    "roles": [
      "PPAT_STAFF"
    ],
    "permissions": [
      "ppat.matters.view",
      "ppat.matters.update",
      "documents.upload"
    ]
  }
}
```

Frontend should not need to request every permission individually.

---

## 5. Health Endpoint

```text
GET /api/v1/health
```

Response:

```json
{
  "status": "ok"
}
```

Do not expose:

- database credentials;
- environment variables;
- file paths;
- framework secrets.

---

## 6. Success Response

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

Optional links may be included for paginated responses.

---

## 7. Validation Error

Use Laravel-standard validation behavior.

Example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

Frontend maps field errors into React Hook Form.

---

## 8. Common HTTP Status Codes

```text
200 OK
201 Created
204 No Content

400 Bad Request
401 Unauthenticated
403 Forbidden
404 Not Found
409 Conflict
419 Session / CSRF related condition where applicable
422 Validation Error
429 Too Many Requests
500 Internal Server Error
```

Use semantics consistently.

---

## 9. Pagination

Collection endpoints should support pagination.

Example request:

```text
GET /api/v1/projects?page=1&per_page=25
```

Example response:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 125,
    "last_page": 5
  }
}
```

Do not return unbounded operational datasets.

---

## 10. Sorting

Recommended query convention:

```text
?sort=created_at
?sort=-created_at
```

or another consistent convention selected by the team.

Negative prefix may represent descending order.

Do not invent different sorting syntax per endpoint.

---

## 11. Filtering

Examples:

```text
GET /api/v1/projects?status=IN_PROGRESS
GET /api/v1/matters?domain=PPAT
GET /api/v1/matters?pic_user_id=...
```

Use stable machine codes.

---

## 12. Search

Example:

```text
GET /api/v1/projects?search=PT%20ABC
```

Global search will be a separate endpoint in a later milestone.

Do not prematurely implement a full universal search system.

---

## 13. Resource Naming

Prefer plural REST resource names:

```text
/projects
/matters
/documents
/tasks
/properties
```

Nested endpoints should only be used when the relationship is meaningful and does not create excessive nesting.

---

## 14. IDs

Use ULID strings for domain resources.

Example:

```text
GET /api/v1/projects/01J...
```

Do not expose database sequence assumptions to the frontend.

---

## 15. Stable Codes

Store and transfer stable codes for status and types.

Example:

```json
{
  "status": "IN_PROGRESS"
}
```

Do not return localized status labels as the authoritative value.

Frontend may display localized labels.

---

## 16. Date and Time

Use ISO 8601 timestamps in API responses.

Example:

```text
2026-08-08T08:30:00Z
```

Frontend localizes the display.

---

## 17. Money

For financial data, avoid floating-point ambiguity.

Preferred approaches:

- decimal numeric database types;
- clear currency field where necessary.

Example:

```json
{
  "amount": "1500000.00",
  "currency": "IDR"
}
```

---

## 18. Boolean Naming

Use explicit names:

```text
is_active
is_sensitive
is_current
requires_approval
```

Avoid ambiguous flags.

---

## 19. Create Endpoint

Example:

```text
POST /api/v1/projects
```

Success:

```text
201 Created
```

Response:

```json
{
  "data": {
    "id": "...",
    "project_number": "PRJ-2026-000001"
  }
}
```

---

## 20. Update Endpoint

Use:

```text
PATCH /api/v1/projects/{id}
```

for partial update.

Use PUT only when the team intentionally treats the request as full replacement.

---

## 21. Archive vs Delete

For important operational/legal records, prefer explicit actions or state transitions.

Example:

```text
POST /api/v1/projects/{id}/archive
```

rather than assuming DELETE always means destructive deletion.

For finalized legal records, normal hard deletion must not be exposed.

---

## 22. Action Endpoints

Domain actions may use explicit verbs when they represent commands rather than CRUD.

Examples:

```text
POST /api/v1/matters/{id}/move-stage
POST /api/v1/notary/deeds/{id}/approve
POST /api/v1/notary/deeds/{id}/finalize
POST /api/v1/ppat/warkah/{id}/verify
```

These actions must be authorized and validated on the backend.

---

## 23. Workflow Transition

A workflow transition request may conceptually contain:

```json
{
  "target_stage": "SIGNING",
  "reason": "All mandatory requirements are complete."
}
```

Backend determines whether the transition is allowed.

Frontend must not be the source of truth for transition validity.

---

## 24. Error Handling Contract

Frontend should recognize:

```text
401 → redirect/login handling
403 → permission denied
404 → not found
419 → session/CSRF recovery
422 → form validation
429 → rate limited
500 → generic server error
```

Raw exceptions must not be shown to users.

---

## 25. API Resources

Use Laravel API Resources where appropriate.

Avoid returning raw Eloquent models directly from controllers when the response contract should be controlled.

---

## 26. Form Requests

Use Laravel Form Requests for request validation.

Examples:

```text
CreateProjectRequest
UpdateProjectRequest
MoveMatterStageRequest
FinalizeDeedRequest
```

---

## 27. Policies

Use Policies or equivalent backend authorization for resource access.

Examples:

```text
ProjectPolicy
MatterPolicy
DocumentPolicy
NotaryDeedPolicy
PPATDeedPolicy
```

Do not rely on frontend PermissionGuard.

---

## 28. API Versioning Policy

Current API version:

```text
v1
```

Breaking response changes should be considered carefully.

Do not create `v2` casually.

---

## 29. Frontend API Client

Use one centralized Axios client:

```text
frontend/src/lib/api/client.ts
```

Configuration should include:

```text
baseURL
withCredentials = true
withXSRFToken = true
Accept = application/json
```

Shared interceptors may handle common session/error behavior.

---

## 30. TanStack Query

Use TanStack Query for remote/server data.

Example keys:

```text
["auth", "me"]
["projects", filters]
["project", id]
["matter", id]
["documents", relatedResource]
```

Invalidate relevant queries after mutations.

---

## 31. File Upload

Use multipart/form-data.

The backend validates:

- MIME type;
- size;
- authorization;
- related resource;
- document type;
- sensitivity rules.

Uploaded file storage must remain private.

---

## 32. File Download

Downloads must use an authorized backend endpoint or secure temporary mechanism.

Do not expose permanent public storage URLs.

---

## 33. Sensitive Data

API responses should avoid returning unnecessary full sensitive data.

Examples:

- NIK;
- NPWP;
- document storage paths;
- internal security metadata.

Use explicit endpoints/permissions where full-value reveal is required.

**The concrete convention, established for the Party domain in M2.2:** a reveal
is a `POST` to a per-field sub-resource, authorized by that field's own
permission, answering `Cache-Control: no-store, no-cache, must-revalidate,
private` and carrying nothing but the field name and its value.

```text
POST /api/v1/individuals/{individual}/identity/nik/reveal
POST /api/v1/individuals/{individual}/identity/npwp/reveal
POST /api/v1/companies/{company}/identity/tax-id/reveal
```

A reveal is `POST` rather than `GET` because the value must never be
expressible as a URL or land in a cached response. The ordinary list and detail
resources carry masked values only, so there is nothing in them to un-hide.
See `07_SECURITY_RULES.md` section 12 and `12_M2_PARTY_ARCHITECTURE.md`
section 11.

**Historical collections use add-and-close, not CRUD.** Where a resource records
history rather than current state — `company_people` is the first, from M2.4 —
the surface offers exactly two mutations, and no `DELETE`, `PUT`, or `PATCH`
exists on an existing row at any level:

```text
POST /api/v1/companies/{company}/management                       add
POST /api/v1/companies/{company}/management/{relationship}/end    close
```

Closing writes the end date and nothing else; closing an already-closed row is a
**409**, not a silent success, because it asks to change a recorded fact. See
`DECISIONS.md` D-085.

---

## 34. Audit

Critical action endpoints should produce audit records.

Examples:

- approval;
- finalization;
- legal numbering;
- permission changes;
- sensitive document access where configured.

---

## 35. Rate Limiting

At minimum apply rate limiting to:

- login;
- repeated sensitive endpoints;
- future global search;
- document-heavy endpoints if necessary.

---

## 36. Idempotency

For critical operations where duplicate submission could create serious effects, consider idempotency or backend duplicate protection.

Examples:

- payment posting;
- deed finalization;
- numbering;
- report generation.

Implementation may be added when the relevant module is developed.

---

## 37. API Documentation

As endpoints mature, maintain API documentation through one consistent method.

Possible approaches:

- generated OpenAPI;
- structured Markdown;
- API test collections.

Do not let the actual API diverge silently from documentation.

---

**Status:** Final baseline v1.0
