# Notary & PPAT Office Management System
## Security Rules — v1.0

## 1. Security Objective

The application handles sensitive identity, legal, property, tax, corporate, and document data.

Security is a core requirement, not a later enhancement.

---

## 2. Security Principles

Use:

- least privilege;
- defense in depth;
- backend authorization;
- private file storage;
- auditability;
- secure session handling;
- controlled finalization;
- controlled data exposure;
- secure defaults.

---

## 3. Authentication

Use Laravel Sanctum SPA cookie/session authentication.

Do not store first-party auth tokens in:

```text
localStorage
sessionStorage
```

Use secure session cookies.

Production must use HTTPS.

---

## 4. Password Storage

Passwords must use Laravel's secure password hashing.

Never store or log plain-text passwords.

Never expose password hashes through APIs.

---

## 5. Login Protection

Apply rate limiting to login.

Consider future protections such as:

- MFA;
- suspicious session monitoring;
- account lockout or throttling strategy;
- password reset protections.

MFA should be available for sensitive/high-privilege users.

---

## 6. Session Security

Production session cookies should be configured appropriately for:

- Secure;
- HttpOnly;
- SameSite;
- domain;
- expiration.

Users should be able to revoke sessions in a later security milestone.

---

## 7. CSRF

Do not disable CSRF protection merely to simplify development.

Use Sanctum-compatible CSRF flow.

---

## 8. Authorization

Backend authorization model:

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

Never authorize only in the frontend.

Never trust a role or permission value sent by the client.

---

## 9. Permission Principle

Use capability-based permissions.

Example:

```text
ppat.deeds.approve
```

Do not rely on:

```text
role == PPAT_STAFF
```

as the only authorization check.

### A permission code is not an authorization surface

Holding a permission is one input to a decision, not the decision. Asking the
framework or the package about a permission code directly —

```text
FORBIDDEN

$user->can('ppat.deeds.approve')
Gate::allows('ppat.deeds.approve')
$user->hasPermissionTo('ppat.deeds.approve')
$user->getAllPermissions()
```

— answers from package storage alone: no Data Scope, no `user_permission_overrides`,
no canonical registry check, and direct user-permission grants counted despite
D-029 excluding them. Such a check can allow what the resolver refuses.

Backend authorization goes through a Policy or first-party service backed by
`EffectiveAccessResolver` (section 10). The package's generic permission Gate
integration is disabled so the calls above fail closed rather than quietly
succeed — see `DECISIONS.md` D-048.

### No privileged role bypass

`SUPER_ADMIN` is a default technical/system-administration role. It is granted
a broad **explicit** permission set. It carries **no unconditional
authorization bypass** (D-032):

```text
FORBIDDEN

Gate::before(fn ($user) => $user->hasRole('SUPER_ADMIN') ? true : null);
```

Holding the role must never automatically override record state,
FINALIZED / LOCKED rules, legal approval requirements, sensitive-data handling,
Data Scope, business rules, or the append-only audit restriction. A bypass
would make every `can()` in the system return true for those accounts, quietly
defeating precisely the controls sections 20, 22, and 23 exist to enforce.

A role name is never itself a capability check.

---

## 10. Data Scope

Supported scopes:

```text
OWN
ASSIGNED
TEAM
OFFICE
ALL
```

A user may have permission to view a resource but still be restricted by scope.

### Semantics

```text
OFFICE     record.office_id matches the user's primary office_id
ALL        no office restriction within the deployment's Organization
OWN        resource-specific ownership relation; the owning field is defined
           by that resource's Policy
ASSIGNED   resource-specific assignment / PIC relation; likewise Policy-defined
TEAM       RESERVED — no Team entity exists. Not assignable, not seeded, and
           rejected by validation until Team semantics are specified.
```

### Centralized resolution

Authorization is **not** a raw permission lookup. Before any business Policy is
written, a single centralized effective-access resolver must exist that answers
"which permissions does this user hold, and at which scopes" from role grants
and user overrides together.

Policies consume that resolver. Controllers must never implement Data Scope
independently — divergent copies of the rule are how authorization quietly
develops holes. Resolution order is locked in `DECISIONS.md` D-028 and D-029.

---

## 11. Sensitive Data

Examples:

- NIK;
- NPWP;
- identity scans;
- tax documents;
- deeds;
- Minuta Akta;
- Warkah;
- certificates;
- corporate legal documents.

Sensitive information must be protected by:

- authorization;
- masking;
- private storage;
- auditing where appropriate;
- secure transport.

---

## 12. NIK and NPWP

Default UI should mask full values.

Example:

```text
3174********1234
```

Full reveal requires explicit permission.

Avoid returning full values in APIs when unnecessary.

**Strengthened by D-082 (M2.0).** For Party-domain data the rule is stricter than
"avoid when unnecessary": a browser that is not authorized for a raw identifier
**never receives it**. Masking is computed server-side and enforced at
serialization — not hidden by CSS, not masked in React, absent from the payload.
A reveal control must fetch from the identity surface rather than unhide a value
the page already holds; if the page already holds it, the payload was wrong.

Reveal is authorized **per field**, in two tiers:

```text
parties.identity.view            opens the identity surface; NIK and NPWP stay masked
parties.identity.update          mutation; confers no full readback
parties.identity.nik.view_full   raw NIK only
parties.identity.npwp.view_full  raw NPWP / tax identifier only
```

Neither tier-2 code implies the other, and `parties.view` / `companies.view`
imply neither surface access nor reveal. See `12_M2_PARTY_ARCHITECTURE.md`
sections 10 and 11 for the storage contract and the threat review.

---

## 13. File Storage

Legal and identity documents must use private storage.

Never use predictable public paths.

Bad:

```text
https://domain.com/uploads/ktp-budi.pdf
```

Preferred:

```text
Authorized backend download endpoint
or
short-lived signed access mechanism
```

---

## 14. File Versioning

Never overwrite an existing legal document version.

Store:

- document;
- versions;
- uploader;
- timestamps;
- checksum;
- current-version flag.

---

## 15. File Validation

Backend must validate:

- MIME type;
- extension consistency where practical;
- size;
- authorization;
- intended related resource;
- document category.

Future malware scanning may be added for production.

---

## 16. File Names

Do not rely on user-supplied filenames for storage location.

Use generated storage names.

Preserve original filename only as metadata.

---

## 17. Checksum

Store SHA-256 checksum for document versions where implemented.

Purpose:

- integrity verification;
- duplicate detection support;
- audit support.

---

## 18. Audit Log

Audit logs are append-only.

Do not implement:

```text
audit.update
audit.delete
```

Audit records may include:

```text
actor_user_id
event
resource type
resource id
old values
new values
IP address
user agent
reason
timestamp
```

---

## 19. Logging Restrictions

Never log:

- passwords;
- session cookies;
- CSRF tokens;
- auth headers;
- API secrets;
- private keys;
- full document content;
- unnecessary full NIK;
- unnecessary full NPWP.

Remove sensitive frontend `console.log()` calls before production.

---

## 20. Finalized Legal Records

Legal record lifecycle may include:

```text
DRAFT
UNDER_REVIEW
APPROVED
FINALIZED
LOCKED
```

After `LOCKED`:

```text
normal update = denied
```

Do not silently edit finalized legal data.

---

## 21. Correction Process

Corrections should use controlled mechanisms such as:

```text
CORRECTION
AMENDMENT
SUPERSEDE
VOID
```

according to documented business/legal rules.

Do not re-open a finalized record simply by toggling a boolean.

---

## 22. Delete Policy

Operational temporary data may use soft delete.

Finalized legal records should generally not be hard deleted.

Prefer:

```text
ARCHIVED
VOID
SUPERSEDED
CANCELLED
```

Audit logs must not be deletable from normal application UI.

---

## 23. Critical Actions

The following should require explicit permission and audit:

- deed approval;
- deed finalization;
- deed numbering;
- register finalization;
- PPAT report approval;
- sensitive document access where configured;
- role changes;
- permission changes;
- security-setting changes;
- invoice cancellation;
- correction of finalized data.

---

## 24. Database Transactions

Critical multi-step actions must use database transactions.

Example:

```text
Finalize Deed
```

should not leave partial changes if one step fails.

---

## 25. Secrets

Never commit:

- Laravel APP_KEY;
- production database passwords;
- S3 secrets;
- SMTP secrets;
- private keys;
- API keys.

Use `.env.example` with placeholders.

---

## 26. Frontend Environment Variables

Any variable prefixed with:

```text
NEXT_PUBLIC_
```

is considered visible to the browser.

Never put secrets in these variables.

---

## 27. Database Security

Production database should:

- not be publicly exposed to the Internet;
- use separate credentials;
- use least privilege;
- use encrypted transport where appropriate;
- be backed up;
- have restore procedures tested.

---

## 28. Backups

Production should have:

- automated database backups;
- private document storage backups;
- retention policy;
- off-site or separate-failure-domain copy;
- restore testing;
- backup access control.

A backup that has never been restored in testing should not be considered sufficient.

---

## 29. Data Retention

Retention rules for legal and protocol data must be validated with applicable law and office obligations before production.

Do not automatically purge legal data based only on generic software retention practices.

---

## 30. CORS

Configure allowed origins narrowly.

Development may allow:

```text
http://localhost:3000
```

Production should allow only approved frontend origin(s).

Credentials should be configured correctly for Sanctum.

---

## 31. Security Headers

Production should implement appropriate headers such as:

- Content-Security-Policy;
- X-Content-Type-Options;
- Referrer-Policy;
- clickjacking/frame protection;
- Strict-Transport-Security where appropriate.

---

## 32. XSS

React escaping must not be bypassed unnecessarily.

Avoid unsafe raw HTML.

If rich text is introduced later, sanitize it carefully.

---

## 33. SQL Injection

Use Eloquent/query builder parameterization.

Do not concatenate untrusted input into raw SQL.

Raw queries must be reviewed carefully.

---

## 34. Mass Assignment

Laravel models must be configured intentionally.

Do not allow sensitive fields to be mass-assigned merely for convenience.

Examples:

- role;
- permission;
- finalized_by;
- locked_at;
- approval fields;
- office ownership.

---

## 35. IDOR Prevention

Every resource endpoint must authorize the resource itself.

Knowing a ULID must not grant access.

Example:

```text
GET /api/v1/documents/{id}
```

must still check:

- permission;
- scope;
- resource relationship;
- sensitivity.

---

## 36. Office Isolation

Even if the first deployment has one office, data queries should be designed so future office isolation is possible.

Do not assume every authenticated user can access every office record.

---

## 37. Sensitive Download

Sensitive document downloads should be auditable if required.

Download URLs should not be long-lived public links.

---

## 38. Rate Limiting

Apply or prepare rate limiting for:

- login;
- password reset;
- global search;
- sensitive reveal;
- document-heavy endpoints;
- high-cost reports.

---

## 39. Validation

Client-side validation is not security.

Laravel validation is authoritative.

Business-rule validation belongs in backend domain logic.

---

## 40. Error Messages

Do not expose stack traces or internal server details to production users.

Return generic user-facing error messages.

Log technical details securely on the server.

---

## 41. Development Debugging

`APP_DEBUG` must be disabled in production.

Do not expose Laravel debug pages publicly.

---

## 42. Dependency Security

Keep dependencies updated.

Review security advisories.

Do not install unnecessary packages suggested by AI without checking their need and maintenance status.

---

## 43. AI Coding Rule

Claude or any coding assistant must not:

- disable authorization;
- expose private files;
- simplify away audit controls;
- add public document routes;
- weaken CSRF;
- add hard delete for legal records;
- invent security exceptions for convenience.

Security-related changes require deliberate review.

---

## 44. Legal Rule Uncertainty

If a legal workflow requirement is unclear:

```text
DO NOT GUESS
```

Document the gap and request domain validation.

Security must not be weakened to work around an undefined legal rule.

---

## 45. Production Security Checklist

Before production:

```text
[ ] HTTPS enforced
[ ] APP_DEBUG=false
[ ] production secrets outside Git
[ ] database not publicly exposed
[ ] secure session cookie configuration
[ ] CORS restricted
[ ] authorization tests passing
[ ] private file storage verified
[ ] backup configured
[ ] restore tested
[ ] audit log protected
[ ] rate limits configured
[ ] privileged accounts reviewed
[ ] MFA decision completed
[ ] legal record locking tested
[ ] sensitive data masking tested
[ ] dependency scan completed
```

---

**Status:** Final baseline v1.0
