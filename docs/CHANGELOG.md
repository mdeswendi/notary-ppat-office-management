# Notary & PPAT Office Management System
## Documentation Changelog

Records specification changes and milestone results.

---

## 2026-08-09 — M1.4 Role Management

Branch `feat/m1-identity`. Role **records** only — no permission assignment, no scope
assignment, no user management, no default-role seeding, **no migration**.

### A defect in M1.3, found by building on it

`EffectiveAccessResolver` read its guard from `config('auth.defaults.guard')`. That value
is rewritten mid-request: `Illuminate\Auth\Middleware\Authenticate` calls
`Auth::shouldUse()` on success, so inside any authenticated API request it reads `sanctum`
rather than `web`. The resolver was therefore looking for permissions on a guard no row
was ever written for, and **denying every authenticated request** — while passing all 48
of its own tests, none of which issued an HTTP request through the auth middleware.

Fixed by defining the guard once, as `PermissionRegistry::GUARD` (D-046), and using it in
the resolver, the sync command, role creation, and both Form Requests. Two regression
tests: one resolves access after deliberately calling `Auth::shouldUse('sanctum')`, one
asserts the named guard exists and uses the `session` driver so a rename fails loudly.

### Authorization

Role management requires the canonical `roles.*` permission **and** the `ALL` Data Scope
(D-044). A Role definition is owned by nobody, so `OWN`, `ASSIGNED`, `TEAM`, and `OFFICE`
have no field to match against — `ALL` is the only predicate that can reach one.

This is presence, not precedence: `{OFFICE, ALL}` passes because `ALL` is in the set, and
`DataScope` still exposes no `widest`, `max`, `rank`, or `higherThan`. D-028 is untouched.

`RolePolicy` runs every decision through the resolver, so all of M1.3's rules apply
unchanged — including that Spatie's direct user-permission grants never participate.
A test confirms the package honours such a grant and `can()` returns true for it, while
the endpoint still answers 403.

No role name is ever compared. A test greps the whole authorization path for `hasRole`,
`SUPER_ADMIN`, `Gate::before`, and `Gate::after` and requires all four absent; another
asserts the application registers no Gate callback of its own.

### API and behaviour

```text
GET    /api/v1/roles          roles.view    + ALL
POST   /api/v1/roles          roles.create  + ALL
GET    /api/v1/roles/{role}   roles.view    + ALL
PATCH  /api/v1/roles/{role}   roles.update  + ALL
DELETE /api/v1/roles/{role}   roles.delete  + ALL
```

No nested permission, scope, or member routes — a test asserts the five URIs are all that
exist. The resource exposes `id`, `name`, `guard_name`, `created_at`, `updated_at` and
nothing about capability.

Creating a role creates one row with zero permissions, zero scope rows, and zero members.
Renaming changes only the name, asserted against all three assignment tables. Deleting a
role somebody holds is refused with **409 Conflict** rather than cascading their access
away silently (D-047); users are never detached automatically. The guard is never taken
from request input.

`roles` gained no table, column, or migration, and its key stays the package's integer
(D-045). Role names are validated technically only — no casing rule, since an office may
legitimately create `Notaris Pengganti` — and stored exactly as submitted.

### Frontend

`/[locale]/settings/roles`, reached by direct URL. The sidebar is unchanged:
permission-aware navigation is its own milestone, and an always-visible Settings entry
would show every user a link most cannot use.

List, create, rename, and delete, with loading, empty, error, and forbidden states, delete
confirmation, and field-level validation. The page does not hide itself based on the
browser's permission list — that list cannot express "at `ALL`" (O-026) — so it asks the
API and renders the answer, 403 included. 29 new `roles.*` keys, id and en at exact
parity (74 = 74).

### Verified

**Backend** 301 tests, 1010 assertions, all passing — 94 new, every M0/M1.1/M1.2/M1.3 test
still green. Pint clean. `migrate:status` unchanged at 10 migrations.

**Frontend** format, lint (0 errors), typecheck, and production build all pass. The single
lint warning is pre-existing in `login-form.tsx`.

**PostgreSQL, over the real Sanctum session flow** — 26/26 checks. Logged in as an
administrator holding `roles.*` at `ALL` and exercised list, create, detail, rename,
delete, duplicate name (422), blank name (422), injected `guard_name` (ignored), missing id
(404), non-numeric id (404), and assigned-role delete (409, role intact). Then repeated
every endpoint as a user holding the same four permissions at `OFFICE` only — all 403 — as
a user holding `roles.view` only as a direct package grant — 403 — and unauthenticated —
401. All temporary data removed afterwards; the database returned to 171 canonical
permissions and zero everywhere else.

### Also recorded

**O-026** — `/api/v1/me` reports permissions via `getAllPermissions()`, which includes
direct grants and carries no Data Scope, so it does not agree with the resolver.
Presentation-only and not relied on here; M1.7 should derive it from the resolver.

**O-027** — Spatie's own `Gate::before` answers any ability named after a held permission,
from direct grants and with no scope check, so `$user->can('roles.view')` bypasses the
resolver. Currently unexploited — nothing calls it, and the policy's ability names are
chosen so the callback cannot answer them — but it needs a decision before more endpoints
are written.

**O-024 and O-025** were re-read and neither blocks M1.4: O-024 concerns
`user_permission_overrides`, which M1.4 does not touch, and O-025 concerns orphaned pivot
rows after a user mass-delete, which M1.4 does not perform. O-025 did inform the smoke
test's own cleanup, which removes `model_has_permissions` rows explicitly.

---

## 2026-08-09 — M1.3 Data Scope model & effective-access resolver

Branch `feat/m1-identity`. Authorization metadata and calculation only — **no Policy, no
role seeding, no permission assignment, no API, no UI, no frontend change**.

### Schema

Two migrations; no earlier migration was edited.

```text
role_permission_scopes      id ULID, role_id bigint, permission_id bigint,
                            scope varchar(20), timestamps
                            UNIQUE (role_id, permission_id), both FKs CASCADE

user_permission_overrides   id ULID, user_id ULID, permission_id bigint,
                            effect varchar(10), scope varchar(20) NULL,
                            expires_at NULL, created_by ULID, created_at
                            UNIQUE (user_id, permission_id)
                            user_id + permission_id CASCADE, created_by RESTRICT
```

ULID primary keys because the tables are ours; bigint references because Spatie's keys
are the package's (D-038). CASCADE rather than M1.1's RESTRICT — these are derived
authorization metadata, and an orphan row in an authorization table is worse than no row.
`created_by` restricts, because it points at the override's author rather than its
subject. No `updated_at` on overrides, per `03_DATABASE_ERD.md` section 5; see O-024.

### The resolver

```text
app/Domains/Authorization/Enums/{DataScope, UserPermissionEffect, AccessSource}.php
app/Domains/Authorization/EffectiveAccess.php
app/Domains/Authorization/EffectiveAccessResolver.php
app/Models/{RolePermissionScope, UserPermissionOverride}.php
```

One question answered: which permission does this user hold, and at which Data Scopes.
Deliberately not "may this user touch this record" — that needs ownership fields,
assignment relationships, record state, and legal workflow rules, none of which exist yet
(D-040).

Fail-closed throughout (D-039). A name outside the registry is denied even when a role
grants it with scope metadata attached, because `permissions:sync` preserves stale rows
and the table is therefore not the authority. A role grant carrying **no scope row** is
denied rather than read as `ALL` — the difference between an administrator forgetting a
field and a privilege escalation.

Multi-role scopes are a distinct union in canonical order, never collapsed to a widest
value (D-028). `OWN + ALL` stays `{OWN, ALL}`. `DataScope` exposes no `widest`, `max`,
`rank`, or `higherThan`, and a reflection test asserts none appears on the enum, the value
object, or the resolver.

Overrides follow D-029: active DENY wins outright, active ALLOW *replaces* the role
result with its own authoritative scope, and expiry is evaluated at check time by binding
the current instant into the query — strictly, so an override expiring exactly now is
already expired. Spatie's direct-user permissions are excluded from first-party
resolution (D-041); the resolver reads the role pivots and never `model_has_permissions`,
and never uses `can()` or `getAllPermissions()`.

Two queries for the role path regardless of how many roles a user holds, and no caching
of results (D-043).

### Verified

205 tests, 808 assertions, all passing — 93 new, and every M0, M1.1, and M1.2 test still
green. Pint clean. No frontend diff against the M1.2 commit.

Migration reversibility is covered by a test that migrates, rolls back, and re-migrates
on its own throwaway SQLite file, so nothing else is disturbed. Independently confirmed
on **PostgreSQL**: rollback dropped both tables, re-migrate restored them, and the 171
canonical permissions were untouched throughout.

A real-engine smoke run built Organization → Office → User with two roles granting
`projects.view` at `ASSIGNED` and `OFFICE`, and confirmed in order: the union
`{ASSIGNED, OFFICE}`; unchanged by a directly attached package permission that Spatie
itself honours; active DENY denies; active ALLOW replaces with `{OWN}`; expired override
falls back to the role union; future expiry is honoured again; `ALLOW` with a null scope
fails closed; a stale name stays denied. All temporary data was removed and the database
returned to exactly its prior state — 171 permissions, everything else zero.

### Worth flagging

Cleanup surfaced a package behaviour worth recording: Spatie's morph pivots have no
foreign key on `model_id`, so a mass-delete of a user leaves `model_has_permissions` rows
behind. Harmless today — nothing in the product deletes users, and no first-party path
reads that table — but recorded as **O-025** before someone writes a deletion path.

`TEAM` resolves like any other scope and is never converted to `OFFICE`, but no Team
entity was created and none was inferred from Office or role membership (D-042). It stays
unenforceable at record level until Team semantics are specified.

---

## 2026-08-09 — M1.2 Canonical Permission Registry

Branch `feat/m1-identity`. Registry and synchronization command only — **no migration, no
table, no role, no seed, no assignment, no API, no UI, no bootstrap**.

### What was added

```text
app/Domains/Authorization/PermissionRegistry.php     171 canonical permissions
app/Console/Commands/SyncPermissionsCommand.php      php artisan permissions:sync
```

The registry is first-party PHP rather than a seeder, config file, or table (D-035), and
touches no database — enforced by a test that fails if a query is issued. Names come from
`02_MENU_AND_PERMISSIONS.md` sections 7–21, grouped by source section so each entry stays
traceable to the document that authorizes it.

Most of these protect modules that do not exist yet. That is the point: a permission name
is inert until something checks it, and registering the whole surface at once lets role
configuration be designed against the finished capability set instead of a moving target.

```text
projects 8   parties 8   companies 8   notary 25   ppat 31   properties 6
documents 9  tasks 8     calendar 5    billing 17  reports 6  master data 14
users & roles 11   organizations & offices 6   settings 2   security 5   audit 2
```

Deliberately **absent**, each covered by a test: `audit.update` and `audit.delete`
(section 21 lists them under "Do not create"; audit is append-only), the three superseded
aliases from D-001, and `organizations.create` / `organizations.delete` /
`offices.delete`.

### Synchronization

`permissions:sync` is additive and idempotent (D-036). It creates what is missing inside
one transaction, clears the Spatie cache on both sides of the write, and grants nothing —
no role, user, Organization, Office, or assignment is created, and existing assignments
are left alone.

Rows in the table that the registry does not declare are **reported by name and
preserved**, never pruned. The command cannot tell an obsolete leftover from something an
operator added on purpose, and a role may already depend on it.

It is a deployment step, never a request side effect — a test asserts that serving an
HTTP request creates no permission rows.

### Verified

112 tests, 560 assertions, all passing — 55 new, and every M0 authentication,
authorization, ULID and M1.1 schema test still green. Pint clean.

Against **PostgreSQL**, not only the SQLite suite: `migrate:status` shows the same 8
migrations as M1.1 with nothing pending, the first sync created 171 rows, the second
created 0 with 171 distinct names all on guard `web`, and `roles`, `role_has_permissions`,
`model_has_permissions`, `model_has_roles`, `users`, `organizations` and `offices` all
remained at 0. A deliberately unmanaged probe row survived a sync, was reported by name,
and was then removed. Spatie's cache is Redis-backed here, and a separate process
(`permission:show`) read all 171 through it, so the invalidation is real rather than
in-process only.

The transcription itself was verified mechanically rather than by reading: every
permission-like token inside the fenced blocks of sections 7–21 was extracted from the
document and diffed against the registry in both directions — 171 = 171, zero in either
difference, and the two "Do not create" names correctly detected and excluded.

### Also recorded

**O-023 direction fixed** as `UNIQUE (organization_id, code)` (D-037) — recorded only.
No migration was added; the constraint is scheduled to land with Office management so the
database rule and the Form Request rule arrive together.

---

## 2026-08-09 — M1.1 Organization & Office schema foundation

Branch `feat/m1-identity`. Schema and domain models only — **no API, UI, permission
registry, role, Data Scope, seed, or bootstrap**.

### Schema

Three new migrations; no M0 migration was edited.

```text
organizations   ULID PK, name, legal_name (nullable), timezone,
                default_locale, is_active
offices         ULID PK, organization_id (required, RESTRICT), code, name,
                address/city/province/postal_code/phone/email (nullable),
                timezone, is_active
users           + office_id  ULID, NON-NULL, indexed, RESTRICT
```

Defaults follow canon: `timezone` `Asia/Jakarta` (D-004), `default_locale` `id`,
`is_active` true. Both foreign keys are **RESTRICT**, so deleting an Organization cannot
silently take its Offices, and deleting an Office cannot silently take its people.

`users.office_id` went in non-null directly (D-027): the table held zero rows, so no
nullable interim phase and no fabricated placeholder Office were needed. No
`organization_id` on `users` — the Organization is reached through the Office — and no
`user_offices` pivot.

### Models and factories

`Organization` and `Office` use `HasUlids`, with `Organization hasMany Office`,
`Office belongsTo Organization`, `Office hasMany User`, `User belongsTo Office`.
`organization_id`, `office_id`, and `is_active` are deliberately **not fillable** —
reparenting and retirement are authorized operations, not mass-assignable fields.

`UserFactory` now builds User → Office → Organization when nothing is supplied, and
reuses an explicitly supplied hierarchy instead of creating a second Organization. That
is test convenience only; production creation is the bootstrap command's job (D-034).

### Verified

57 tests, 161 assertions, all passing — 20 new, and every M0 authentication,
authorization, and ULID test still green. Migrations run from zero on in-memory SQLite
and on PostgreSQL; a full rollback and re-migrate also passes, which exercises the
`down()` methods. Runtime relationship smoke on PostgreSQL confirmed 26-character ULIDs
and all four relation directions, then removed its rows.

### Two things worth flagging

**No uniqueness constraint was added to `offices.code`.** No canonical document defines
one — the word "unique" appears nowhere in the specification — and a composite
`organization_id + code` rule would be a long-term product rule invented inside a
migration. Raised for decision as **O-023** rather than encoded silently.

**Foreign keys do not imply indexes on PostgreSQL.** `constrained()` created the
constraint but no index; both FK columns now carry an explicit `index()`, verified
present as `users_office_id_index` and `offices_organization_id_index`.

`Organization` and `Office` live in `app/Models` alongside `User` rather than under
`app/Domains/Identity`, so the identity models stay in one place. Relocating the set is a
deliberate refactor, not M1.1 work.

---

## 2026-08-09 — M1.0A Identity & Access architecture lock

Branch `feat/m1-identity`. **Documentation only** — no migration, model, controller,
route, page, or seed. Locks the decisions M1.0 planning found missing, before any of
them can be baked into code.

Nine decisions recorded, **D-026 … D-034**:

```text
D-026  one active Organization per deployment; not a SaaS tenant
D-027  Office belongs to one Organization; users.office_id required;
       no user_offices many-to-many
D-028  multiple role grants UNION their scopes, never collapse to "widest"
D-029  user_permission_overrides is the only per-user exception mechanism;
       DENY wins, ALLOW replaces and its scope is authoritative,
       expiry evaluated at check time; Spatie direct user permissions
       are not exposed
D-030  settings.* and security.settings.* are distinct, not aliases;
       organizations.* and offices.* codes locked
D-031  users.email_verified_at retained, nullable, verification not required
D-032  SUPER_ADMIN gets explicit permissions and NO Gate::before bypass
D-033  audit_logs stays out of M1 (ERD batch 7); no parallel audit table
D-034  deployment bootstrap is a one-time interactive Artisan command
```

**O-020 is resolved** by D-032 — on the security review it asked for, not for tidiness.
O-017, O-018, O-021, O-022 remain open; O-006 and O-019 stay resolved.

Two documentation gaps closed rather than papered over: the Organization existed only
as an ERD schema block with no product definition anywhere, and the permission matrix
carried a "System Settings" row with no permission codes while `security.settings.*`
existed with no matching row.

Registry additions: `organizations.view/update`, `offices.view/create/update/disable`,
`settings.view/manage`. No `organizations.create` and no hard-delete for either —
retirement uses `is_active`.

`TEAM` stays in the canonical scope vocabulary but is **not assignable** until a Team
entity is specified: not offered in UI, not seeded, rejected by validation.

Changed: `02_MENU_AND_PERMISSIONS.md`, `03_DATABASE_ERD.md`, `07_SECURITY_RULES.md`,
`DECISIONS.md`, `CHANGELOG.md`. M1 order recorded with M1.1 as schema foundation only —
no management endpoints before M1.2 supplies the permissions to protect them.

---

## 2026-08-09 — M0 Foundation closed

`feat/m0-foundation` merged into `main` with `--no-ff`, preserving the fourteen M0 commits.

```text
merge commit   8be0ad0
parents        2f8a1d8 (main) + 2bdf80b (feature, CI-green)
conflicts      none
```

The merge carried no code change of its own: the feature HEAD merged is exactly the commit
whose CI had been verified, so nothing untested reached `main`.

**GitHub Actions on `main` at `8be0ad0` is green — frontend and backend both pass**, the
backend on PHP 8.3. That closes the last outstanding verification. **O-006 is resolved**;
its full history, including the CI failure that caught the PHP 8.3 lockfile defect, is kept
in `DECISIONS.md` rather than tidied away.

M0 is complete end to end: clean-clone reproducibility, the full 18-item Definition of Done,
feature-branch CI, merge, post-merge local gates, and main-branch CI.

`O-017`, `O-018`, `O-020`, `O-021`, and `O-022` remain **open and non-blocking**, with their
scope unchanged. `feat/m0-foundation` is retained as the M0 historical checkpoint.

No business module exists. M1 — Identity & Access Management — has not begun.

---

## 2026-08-09 — Composer lock aligned with PHP 8.3

Branch `feat/m0-foundation`. Fixes the backend CI failure that the first real GitHub Actions
runs exposed. Frontend job was already passing and is untouched.

### Cause

The workstation runs PHP 8.4.23; the project supports `^8.3`. Composer resolves against the
PHP it runs on, so the committed lockfile selected Symfony 8.1.x, which requires
`php >=8.4.1`. CI on PHP 8.3.33 reported `Your lock file does not contain a compatible set
of packages`. The reported blocker named one package; `composer prohibits php 8.3.33`
showed **sixteen**.

### Fix

Added `config.platform.php = "8.3.0"` to `backend/composer.json` and re-resolved narrowly:

```bash
composer update "symfony/*" --with-all-dependencies --minimal-changes
```

Result — 16 Symfony packages downgraded 8.1.x → 7.4.x, `symfony/polyfill-php83` added,
**zero upgrades, zero removals, zero non-Symfony changes**. Laravel 13.24.0, Sanctum 4.3.3,
Spatie 8.3.0, Pest 4.7.8, Pint 1.30.4, and PHPUnit 12.5.33 are all unchanged. Laravel 13
already accepts `symfony/* ^7.4.0 || ^8.0.0`, so no requirement was relaxed to achieve this.

The project floor stays `php: ^8.3` and CI stays on PHP 8.3. Raising either would have
hidden the defect rather than fixed it — no required dependency needs 8.4. Recorded as
**D-025**.

### Verified

`composer prohibits php 8.3.0` and `php 8.3.33` both report no prohibitions;
`composer validate --strict` passes; a clean `composer install` from the committed
`composer.json` + `composer.lock` alone (no vendor, no `.env`) succeeds and installs Symfony
packages requiring only `php >=8.2`. On the local 8.4 runtime, Pint passes and all 38 tests
pass. Frontend has no tracked change and all four gates still pass.

Local checks cannot prove a PHP 8.3 runtime — the CLI here is 8.4, so GitHub Actions was the
verification. **Confirmed: both jobs subsequently passed on PHP 8.3.**

---

## 2026-08-09 — M0.10 Foundation Acceptance — **M0 COMPLETE**

Branch `feat/m0-foundation`. No feature work; this milestone proves the foundation is
reproducible and accepts it.

### The one real defect found

The README still described the **M0.1** state — it claimed the frontend and backend were
not yet initialized and carried no setup, migration, or quality commands. A new developer
could not have set the project up from it. That is a reproducibility failure, so it was
rewritten before the clean-clone test and the clone was then set up by following it
literally.

The rewrite documents the D-019 gap explicitly: `composer install` creates neither `.env`
nor `APP_KEY`, because those hooks only run on `create-project`, so both are manual on
every clone. It also records that the frontend needs no environment file, that Docker runs
only PostgreSQL and Redis, and that `docker compose down -v` destroys the named volumes.

### Clean-clone verification

Cloned fresh from `origin` into a separate directory — not copied — with no `node_modules`,
`.next`, `vendor`, or `.env`. Following the README verbatim:

```text
docker compose up -d          idempotent; reused the running containers, volumes intact
composer install              OK
.env + key:generate           new APP_KEY, verified different from the primary checkout
php artisan migrate:fresh     all 5 migrations from zero
pint --test / artisan test    PASS — 38 tests, 119 assertions
pnpm install --frozen-lockfile / format:check / lint / typecheck / build   PASS
```

Both servers booted from the clone, and a 22-point acceptance passed end to end:
`/` → `/id`; both login pages render without the shell; anonymous dashboards return real
307s to the same-locale login; CSRF-less login is rejected with 419; invalid credentials
return a generic 422; login 204; `/api/v1/me` returns a 26-character ULID with `roles` and
`permissions` and no credential fields; the session survives repeated requests; the
authenticated shell renders in both locales; the locale switch preserves `/dashboard`;
logout 204, then 401, then redirect; replayed pre-logout cookies still redirect.

Compose sets `name: notary-ppat-office` explicitly, so project identity does not depend on
the directory — which is why the clone reused the existing stack rather than building a
second one. Recorded as **D-024**.

### O-006 resolved on its own terms

CI was deferred until executable quality gates existed on both sides. They now do, so
`.github/workflows/quality.yml` was added, running exactly the README commands. The backend
job pins **PHP 8.3**, the canonical minimum, while the workstation runs 8.4 — that gap is
the point. No PostgreSQL or Redis service is needed because the Pest suite uses in-memory
SQLite. No secrets, no deployment. Validated locally at the time; its first real runs then
exposed a PHP 8.3 lockfile defect, which was fixed and verified green — see the entries
above.

### Open items

O-017, O-018, O-020, O-021, and O-022 were each classified against the Definition of Done
and **none blocks M0**. None was closed for checklist tidiness; the reasoning is recorded in
`DECISIONS.md`.

### Result

All eighteen Definition of Done items in `10_M0_FOUNDATION.md` section 77 verified.
**M0 Foundation is complete.** No business module exists — M1 begins with Identity & Access
Management.

---

## 2026-08-09 — M0.9 Authenticated Application Shell

Branch `feat/m0-foundation`. Frontend composition only — **no backend change**, verified
with `git status -- backend`.

### Structure

Authenticated pages now share `src/app/[locale]/(app)/layout.tsx`. `(app)` is a route
group, so URLs are unchanged: `/id/dashboard`, not `/id/app/dashboard`. The layout verifies
the session once by asking Laravel through the existing `fetchCurrentUser()`, redirects to
the same-locale login on 401, and renders `AppShell`. Future pages inherit the check
instead of each repeating one.

Login stays outside the group and renders no shell — confirmed in the served HTML: no
`<aside>`, no account menu, no navigation trigger.

No `loading.tsx` was added at or above the authenticated boundary. The M0.7 defect it would
reintroduce is recorded in D-022, and anonymous protection was re-verified as a real 307.

### Composition

`AppShell` now takes the already-resolved user rather than fetching its own. The header
carries the mobile navigation trigger, application name, locale switch, and an account menu
showing name and email with sign out. Desktop sidebar is 256px and hidden below `lg`; a
`Sheet` drawer takes over there, rendering the **same** `SidebarNav`, so there is one menu
definition rather than two that drift.

Navigation filters generically on `requiredPermission` against effective permissions —
never on role names. Dashboard has none, so it is visible to any authenticated user, and it
remains the only destination: no links were created to modules that do not exist.

The `["auth", "me"]` cache is seeded from the server layout via `HydrationBoundary`, so
client components read the user the server already fetched. No second store, no context
mirror, nothing in browser storage.

**Search, quick create, and notifications were omitted, not stubbed.** Each depends on
modules that do not exist; a disabled control invites "why is this greyed out?" and an
enabled one would lie. They are documented as reserved header slots.

The dashboard is a placeholder: heading, subtitle, and one sentence. Full visible text on
the page is the shell chrome plus those three strings — no counts, charts, deadlines, or
activity.

### Verified

Fourteen checks over a real cookie jar, redirects never followed. Anonymous `/` → `/id` →
`/id/dashboard` → `/id/login`, and `/en/dashboard` → `/en/login`, all real 307s.
Authenticated `/id/dashboard` returns 200 with sidebar, header, `<main>`, placeholder,
user identity, locale switch, active `aria-current="page"`, and `lang="id"`; refresh keeps
it; `/en/dashboard` renders English. The locale switch on `/en/dashboard` targets
`/id/dashboard`, preserving the route. Logout returns 204, after which the dashboard
redirects to login and replayed pre-logout cookies do too.

Backend regression: Pint passes and all 38 tests pass, unchanged. Translation parity exact
at 49 keys. Temporary user and authorization rows removed.

Desktop sidebar collapse (72px rail) is deferred — see the open item.

---

## 2026-08-09 — M0.8 Authorization Foundation

Branch `feat/m0-foundation`. `spatie/laravel-permission` **8.3.0**. Package foundation only:
the real role matrix, Data Scope, and office isolation all remain M1.

### Backend

Config and migration published, then the migration was **corrected before it ran**: the
package ships `unsignedBigInteger` for the morph key in both `model_has_permissions` and
`model_has_roles`, which cannot hold a ULID. Both were changed to `ulid()`, applying the
consequence D-023 already recorded. The column keeps its default semantic name `model_id`.

```text
roles.id / permissions.id          bigint      package-native, unchanged
model_has_roles.model_id           char(26)    ULID
model_has_permissions.model_id     char(26)    ULID
```

Package defaults preserved — `teams: false`, `enable_wildcard_permission: false`, cache
store `default` (Redis), guard `web`. `User` gained `HasRoles` alongside `HasUlids`; no role
or permission column was added to `users`.

`GET /api/v1/me` now returns `roles` and `permissions`. Permissions are **effective** —
resolved by the package across direct grants and role inheritance, then de-duplicated and
sorted for stable output. Names only: no ids, pivots, or guard internals.

### Frontend

`CurrentUser` gained `roles: string[]` and `permissions: string[]`. Added a `can()` helper
(exact string match, no wildcards, no role fallback), a `useCurrentUser` hook reading the
existing `["auth", "me"]` query, and `PermissionGuard`. There is no second user store and
nothing in browser storage. **Guards are presentation only** — every protected action is
authorized again by the backend.

### Verified

38 backend tests pass on in-memory SQLite, so the ULID pivot works there as well as on
PostgreSQL. Live PostgreSQL check: a role-derived permission makes `$user->can()` true
through Laravel's Gate, an unrelated permission is false, a user with no role is denied, and
`model_has_roles.model_id` holds the complete 26-character ULID. Over HTTP, `/api/v1/me`
returned the role name and inherited permission for one user and empty arrays for another.
All 20 M0.7 authentication checks still pass unchanged.

No role seed, no `Gate::before` Super Admin bypass, no Data Scope, no business policies or
tables. All temporary users, roles, and permissions were removed — every authorization
table is back to zero rows.

---

## 2026-08-09 — O-019 User primary key aligned with the ULID strategy

Branch `feat/m0-foundation`. Closes O-019. Done before M0.8, not during it: Spatie's
polymorphic `model_has_roles` / `model_has_permissions` keys must match the User key type,
so the correction had to land before the package is installed.

### Cause

The Laravel scaffold created `users.id` as an auto-incrementing bigint. The canonical key
strategy for our own domain tables is ULID — `CLAUDE.md` section 11,
`03_DATABASE_ERD.md` section 2, `06_API_CONVENTIONS.md` section 14. `users` is listed as a
core table in the ERD, so the section 45 exemption for third-party package tables does not
apply. The documents agree with each other; only the scaffold disagreed.

### Change

```text
users.id          bigint            ->  char(26) ULID, primary key
sessions.user_id  bigint nullable   ->  char(26) ULID nullable, index preserved
User model                          ->  HasUlids
CurrentUser.id    number            ->  string (opaque identifier)
```

The scaffold migration was corrected in place rather than layered with a conversion
migration, so a clean clone builds the right schema from the first migration. That is a
deliberate exception to D-019, permitted here because the users table held zero rows,
nothing has shipped, and Spatie is not installed. Recorded as **D-023**.

`sessions.user_id` had to change with it — a bigint there would silently fail to store
`Auth::id()` once anyone logged in.

### Verification

Local schema rebuilt with `migrate:fresh` after confirming `APP_ENV=local`,
`DB_DATABASE=notary_ppat_office`, zero users, and no business tables. PostgreSQL now shows
`users.id char(26)` as primary key and `sessions.user_id char(26)` with its index intact;
`preferred_locale`, `is_active`, and `last_login_at` survive.

Backend suite 25 passing on in-memory SQLite, so the corrected migrations also build from
scratch there. Full M0.7 flow re-run against a real ULID user: login, `/api/v1/me`
returning `"id":"01kz…"` as a quoted string, protected dashboard in both locales, logout,
401, and stale-cookie rejection. The session row was confirmed to hold the full 26-character
ULID and to join back to `users`.

Temporary user deleted; users table back to zero rows. Pint and all four frontend gates
pass. No Spatie package or tables, no `personal_access_tokens`, no business tables.

---

## 2026-08-09 — M0.7 Authentication Foundation

Branch `feat/m0-foundation`. Laravel Sanctum **4.3.3**, first-party SPA session
authentication. No API token is issued anywhere.

### Backend

Sanctum installed with plain Composer, not `install:api`, which would have rewritten the
existing API routing and added token infrastructure. Sanctum 4.3.3 only *publishes* its
migration rather than loading it, so **no `personal_access_tokens` table exists**.
`statefulApi()` enabled; `config/sanctum.php` and `config/cors.php` published.

CORS names the frontend origin explicitly with `supports_credentials`, replacing the
framework default of `allowed_origins: ['*']` with credentials off — a wildcard is invalid
for credentialed requests and browsers reject it.

New user columns from `10_M0_FOUNDATION.md` section 44: `preferred_locale` (default `id`),
`is_active` (default true), `last_login_at`. `office_id` deliberately omitted — no offices
table exists. `is_active` and `last_login_at` are not fillable.

Routes: `GET /sanctum/csrf-cookie`, `POST /login`, `POST /logout`, `GET /api/v1/me`
(`auth:sanctum`). `GET /api/v1/health` unchanged and still public.

`is_active` is part of the credential lookup rather than a check after the password
matches, so a disabled account fails identically to a wrong password and the response
cannot be used to enumerate accounts. Login throttles on normalized email plus IP, five
attempts, returning 429 with `Retry-After`.

### Frontend

Centralized Axios client (`withCredentials`, `withXSRFToken`), TanStack Query provider,
typed auth service, localized `/id/login` and `/en/login` with React Hook Form and Zod,
and a protected `/id/dashboard` / `/en/dashboard`.

Route protection asks Laravel rather than trusting a cookie: the server component forwards
the browser's cookies to `GET /api/v1/me` and redirects on 401. Cookie presence is never
treated as authentication.

### Verified against both servers running

Twenty checks over a real cookie jar on `localhost` throughout. CSRF cookie issued
(`XSRF-TOKEN` readable, session cookie HttpOnly); **`POST /login` without the CSRF header
is rejected with 419**; `/` → `/id` → `/id/dashboard` → `/id/login` when anonymous; login
204; `/api/v1/me` 200 with id, name, email, preferred_locale and nothing else; session
survives repeated requests; dashboard renders in both locales; logout 204; `/api/v1/me`
then 401; dashboard redirects again; replaying pre-logout cookies still redirects. Login
throttling observed live as five 422s followed by 429.

The temporary smoke user was created with a random password, never printed or committed,
and deleted afterwards — the users table is back to zero rows.

### Two corrections made during the work

`[locale]/loading.tsx` was **removed**. It wrapped every child route in a Suspense
boundary, so the protected dashboard streamed a 200 with a skeleton and resolved the
redirect on the client — anonymous protection stopped being HTTP-verifiable. Without it
the redirect is a real 307. `LoadingSkeleton` remains available for future data pages.

The server-side session check now sends an `Origin` header. Sanctum chooses cookie versus
token authentication by matching Origin/Referer against its stateful domains; a
server-to-server fetch sends neither, so every request looked anonymous and the dashboard
silently redirected even when signed in.

No Spatie package, no roles or permissions, no business tables, no Docker change.

---

## 2026-08-09 — M0.6 UI Foundation

Branch `feat/m0-foundation`. Frontend only. Reusable presentational foundations; the
authenticated shell remains M0.9.

### Added

```text
src/app/globals.css              semantic tokens from 04_UI_DESIGN_SYSTEM sections 5-8
src/config/navigation.ts         menu config, per CLAUDE.md section 47
src/components/layout/           AppShell, AppSidebar, AppHeader, PageContainer
src/components/feedback/         LoadingSkeleton, BaseErrorState
src/components/ui/               shadcn Button, Skeleton, Separator
src/app/[locale]/loading.tsx     route loading boundary
src/app/[locale]/error.tsx       route error boundary
```

Tokens carry the spec's own values — primary `#172554`, page `#F8FAFC`, card `#FFFFFF`,
border `#E2E8F0` — converted to OKLCH with the source hex kept in comments. Added
`success` / `warning` / `info` and the `notary` / `ppat` domain accents required by
`10_M0_FOUNDATION.md` section 32. Dark-mode parity preserved; no theme switch shipped.

AppShell is layout only: it does not read the current user, call `/api/v1/me`, inspect
permissions, or guard routes. The sidebar shows the Dashboard placeholder alone —
advertising modules with no routes would misrepresent what exists. The header carries
application context and the M0.5 locale switch; search, notifications, quick create, and
the user menu are omitted rather than faked.

`error.tsx` never renders or logs the `Error` object Next.js hands it, so a server-side
detail cannot reach the interface through it.

### O-014 resolved

Typography is now **Inter**, the only typeface named in `04_UI_DESIGN_SYSTEM.md`
section 4, self-hosted through `next/font`. Geist is gone. No new decision was needed —
the canonical document was never ambiguous; `D-017` had recorded Geist as an incidental
shadcn preset default awaiting this milestone.

Found while fixing it: `--font-sans: var(--font-sans)` in the scaffold's `globals.css` was
self-referential, so **no** custom sans font had ever applied — the app had been rendering
in the browser default. Verified in the production CSS that `font-family: Inter` now
resolves.

### Two findings worth recording

**`loading.tsx` silently cost static rendering.** Adding it flipped `/id` and `/en` from
prerendered to server-rendered on demand. Next.js gives `loading.tsx` no `params`, so a
server component there cannot call `setRequestLocale`, and next-intl falls back to reading
the locale from the request — which opts the whole segment out. Making `LoadingSkeleton` a
client component, where messages come from `NextIntlClientProvider`, restored SSG. Bisected
against the build output, not guessed.

**A localized `not-found.tsx` was written and then removed.** Next.js uses the *root*
not-found for unmatched URLs; a nested one only catches `notFound()` thrown inside its own
segment, and the proxy guarantees the locale segment is always valid, so it never rendered.
Making it work needs a catch-all route — a routing change outside M0.6. The built-in 404
stays for now. See open item O-017.

`pnpm format:check`, `lint`, `typecheck`, `build` all pass; both locales still prerender.
Message parity exact at 25 keys. No backend, Docker, authentication, authorization, or
business-module change.

---

## 2026-08-09 — M0.5 Internationalization Foundation

Branch `feat/m0-foundation`. Frontend only. `next-intl` 4.13.5.

### Added

```text
frontend/src/i18n/routing.ts      locales id + en, default id
frontend/src/i18n/navigation.ts   locale-aware Link / router helpers
frontend/src/i18n/request.ts      per-request messages
frontend/src/proxy.ts             locale negotiation and prefixing
frontend/src/app/[locale]/        layout + minimal foundation page
frontend/src/components/locale-switcher.tsx
frontend/messages/{id,en}.json    8 canonical namespaces
```

`src/app/layout.tsx` and `src/app/page.tsx` were removed; `[locale]/layout.tsx` is now the
root layout and sets `<html lang>` from the active segment. The scaffold's hardcoded
English strings and the `Create Next App` title are gone — every visible label resolves
through a translation key.

### Verified against a running dev server

```text
/            307 -> /id, including for an en-US browser
/id          200, lang="id", Indonesian
/en          200, lang="en", English
/fr          307 -> /id/fr -> 404, never a third locale
ID <-> EN    switch traverses /id <-> /en, content and lang follow
refresh      /id stays Indonesian, /en stays English
```

Message parity: 13 keys each, no key missing in either direction. The three values that
match across locales are the product name and the two language endonyms.

`pnpm lint`, `pnpm typecheck`, `pnpm build`, `pnpm format:check` all pass. Both locales
prerender as static HTML.

### Two deviations from library defaults, both deliberate

**Locale detection is off** (`localeDetection: false`, `localeCookie: false`). By default
next-intl negotiates from `accept-language` and a cookie, which made `/` non-deterministic
— an English browser landed on `/en`, so Indonesian was not really the default. Measured
before the change. The URL is now the only source of locale. Remembering a person's
language belongs to `preferred_locale` on their profile in a later identity milestone.

**The middleware file is `src/proxy.ts`, not `src/middleware.ts`.** Next.js 16.3 deprecates
the `middleware` convention and warns on every build. next-intl still publishes the handler
as `next-intl/middleware`, but it is a plain `(NextRequest) => NextResponse`, so only the
file name changes.

### Also

`pnpm add next-intl` appended unresolved placeholders to `frontend/pnpm-workspace.yaml`
for `@parcel/watcher` and `@swc/core`, which made every later `pnpm install` — and so
`pnpm lint` — fail with `ERR_PNPM_IGNORED_BUILDS`. Both are optional to next-intl and ship
prebuilt binaries, so both were denied, matching the existing `sharp: false` /
`unrs-resolver: false` posture. `pnpm install --frozen-lockfile` now succeeds.

No backend, Docker, or infrastructure change. No authentication, authorization, app shell,
or business module.

---

## 2026-08-09 — M0.4 PostgreSQL & Redis Application Integration

Branch `feat/m0-foundation`. Connectivity and migration only. No business schema, no
authentication, no authorization.

### Observed infrastructure

```text
PostgreSQL   18.4 (Debian 18.4-1.pgdg13+1)   server_version_num 180004   healthy
Redis        8.10.0 standalone                                           healthy
```

Both containers were already running. `docker-compose.yml` was read but not modified, no
container or volume was recreated, and `docker compose down -v` was never run.

### Laravel → PostgreSQL

Verified through Laravel's own configured connection over TCP `127.0.0.1:5432`, not merely
by `psql` inside the container over a unix socket — the container-side check proves Docker
is alive, the host-side check proves the application's path works.

```text
laravel driver        pgsql
PDO driver            pgsql
PDO server version    18.4
current_database()    notary_ppat_office
current_user          notary_app
encoding              UTF8
```

### Migrations

Only the three standard Laravel 13 scaffold migrations were present, unmodified since
M0.3. No business or domain migration was created.

```text
0001_01_01_000000_create_users_table   Ran   [1]
0001_01_01_000001_create_cache_table   Ran   [1]
0001_01_01_000002_create_jobs_table    Ran   [1]
```

`php artisan migrate` — not `migrate:fresh`. No seeder was run. Nine tables now exist in
`public`, all Laravel infrastructure:

```text
migrations   users   password_reset_tokens   sessions
cache        cache_locks   jobs   job_batches   failed_jobs
```

A pattern check against the domain vocabulary — client, party, project, matter, workflow,
document, task, notary, ppat, property, warkah, billing, deed, minuta, repertorium —
returned no matches.

### Laravel → Redis

Two independent paths were exercised from the application, each with a unique namespaced
M0.4 key, each deleted afterwards.

```text
Redis facade   default connection, database 0   write / read / verify / delete   PASS
Cache store    Illuminate\Cache\RedisStore
               cache connection, database 1     put / get / verify / forget      PASS
```

The client is **phpredis 6.1.0**, the compiled extension already supplied by Herd.
Predis was not installed — it is unnecessary when phpredis is present and Laravel
supports it directly.

Cleanup verified by scanning both databases with `SCAN` for `*m0_4*` and `*probe*`: no
matches, and `DBSIZE` is `0` for database 0 and database 1. Redis was never flushed;
`FLUSHALL` and `FLUSHDB` were not run, and persistence configuration was untouched.

### Driver bootstrap sanity

Configuration resolves and each backing table is present and readable. No worker was
started and no job was enqueued — `Queue::size()` is a COUNT.

```text
session   database   Illuminate\Session\DatabaseSessionHandler   sessions table present
queue     database   Illuminate\Queue\DatabaseQueue              jobs table readable, 0 pending
cache     redis      Illuminate\Cache\RedisStore                 database 1
```

Worth recording: the scaffold's `cache` and `cache_locks` tables were created by
`0001_01_01_000001_create_cache_table` but are **unused**, because `CACHE_STORE=redis`.
They are standard scaffold output and were left in place rather than removed.

### Quality gate

```text
vendor/bin/pint --test   PASS
php artisan test         PASS   3 passed, 4 assertions
```

No test was added and none was rewritten. `phpunit.xml` keeps Laravel's defaults —
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`,
`SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` — so the suite stays runnable on a machine
with no Docker at all. Deliberately **no** infrastructure-dependent test was introduced:
that would couple the quality gate to a running container and turn an environment outage
into a red suite. Connectivity is proven by explicit verification instead.

The first run after `config:clear` took 29.6s; the two runs after it took 0.65s and 0.71s.
Cold-start rebuild, not an infrastructure dependency.

### Configuration

All M0.4 configuration is local and lives in the gitignored `backend/.env`. `DB_PASSWORD`
was set to the development-only credential that `docker-compose.yml` already defines; the
value was read from the running container rather than assumed, and confirmed to be the
compose fallback. It was **not** copied into `.env.example`, which keeps `APP_KEY=` and
`DB_PASSWORD=` empty. `APP_KEY` was not altered. The file's LF endings and absence of a
BOM were preserved.

`php artisan config:clear` was run so no stale configuration could affect verification.

### Not done — deferred by scope

```text
Business schema and models     M2 onward
Sanctum, CSRF, CORS, login     M0.7
/api/v1/me                     M0.7
Spatie Laravel Permission      M0.8
i18n, app shell, dashboard     M0.5, M0.6, M0.9
```

`frontend/` and `docker-compose.yml` unchanged, verified with `git diff`. No open item was
closed: none is resolved by M0.4. No new decision was recorded — connectivity working as
designed is not an architectural decision.

---

## 2026-08-09 — Backend EditorConfig alignment

Branch `feat/m0-foundation`. Closes O-016, raised by M0.3 below. No new decision; D-011
remains the canonical formatting decision and gained a scope note.

### Cause

The Laravel skeleton ships its own `backend/.editorconfig` declaring `root = true`. That
directive halts EditorConfig's upward search, so the repository `.editorconfig` — and with
it D-011 — stopped at the `backend/` boundary and never governed a single file inside it.

Measured with the reference `editorconfig` resolver rather than inferred:

```text
backend/composer.json     indent_size=4     D-011 requires 2
backend/package.json      indent_size=4     D-011 requires 2
backend/vite.config.js    indent_size=4     D-011 requires 2
```

PHP was unaffected only by coincidence — both files happen to specify 4 spaces.

### Fix

```text
deleted   backend/.editorconfig     18 lines
```

Deletion rather than editing. Removing only `root = true` would have left the file's
`[*] indent_size = 4` block in place, and as the nearer file it still wins over the root
file's `[*.{json,jsonc}]` rule — the override would have survived in a less visible form.

Every rule the backend file carried already exists in the root file, with one exception:
`[compose.yaml] indent_size = 4`, a Laravel Sail convention. No `compose.yaml` exists and
`backend/` contains no YAML at all, so nothing regressed. Any future `compose.yaml` would
take 2 spaces from the root `[*.{yml,yaml}]` rule, which is repository policy.

### Verification

Resolved properties after the fix:

```text
backend/**.php                4     backend/composer.json     2
backend/**.blade.php          4     backend/package.json      2
backend/phpunit.xml           4     backend/vite.config.js    2
backend/README.md      trim off     backend/**.css            2
frontend/**.tsx               2     docker-compose.yml        2
```

```text
vendor/bin/pint --test    PASS
php artisan test          PASS   3 passed, 4 assertions
```

No generated file was reformatted. Rewriting `composer.json`, `package.json`, or
`vite.config.js` to 2 spaces would have produced a large diff for no functional gain, and
Composer and npm both write their own indentation when they rewrite those files regardless
of EditorConfig. The policy now applies to hand-edited files; the generated ones keep
whatever their generator emits.

`frontend/` and `docker-compose.yml` unchanged, verified with `git diff`.

---

## 2026-08-08 — M0.3 Backend Initialization

Branch `feat/m0-foundation`. First backend application code in the repository.

### Initialized

```text
Laravel Framework   13.24.0   (skeleton laravel/laravel v13.0.0)
PHP runtime         8.4.23    development runtime only; project floor stays >= 8.3
Composer            2.10.1
Pest                4.7.8     with pest-plugin-laravel 4.1.0
Laravel Pint        1.30.4    shipped with the skeleton
```

Command used:

```bash
composer create-project laravel/laravel backend "^13.0" --no-scripts --no-interaction
```

`--no-scripts` was deliberate, not incidental. The skeleton's `post-create-project-cmd`
runs `key:generate`, creates `database/database.sqlite`, and then runs
`artisan migrate --graceful`. M0.3 must not touch a database, so the scripts were skipped
and `key:generate` was invoked on its own afterwards. No SQLite file exists and no
migration ran. See D-019.

The version constraint `"^13.0"` was explicit rather than relying on "latest", so the
result cannot drift to Laravel 14 on a later clone.

### Added

| Path | Note |
|---|---|
| `backend/` | Laravel 13 application, default structure preserved |
| `backend/routes/api.php` | Created manually; `install:api` was **not** run, because it installs Sanctum, which belongs to M0.7 |
| `backend/app/Http/Controllers/HealthController.php` | Invokable controller, returns a bare status flag |
| `backend/tests/Feature/HealthTest.php` | Pest feature test for the health endpoint |
| `backend/tests/Pest.php` | Created by `pest --init` |

`bootstrap/app.php` gained one line: `api: __DIR__.'/../routes/api.php'` inside
`withRouting()`. The default `api` prefix yields the canonical URL.

```text
GET /api/v1/health   →   200   {"status":"ok"}
```

The response is asserted with `assertExactJson`, so any future addition of runtime,
dependency, or configuration detail to this public endpoint fails the test.

### Environment

`backend/.env.example` aligned with `10_M0_FOUNDATION.md` section 48 — PostgreSQL
connection, `SESSION_DRIVER=database`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=database`,
Redis host/port, and `FRONTEND_URL`. `APP_KEY` and `DB_PASSWORD` are empty placeholders.

A local `APP_KEY` was generated into `backend/.env` with `php artisan key:generate`.
`backend/.env` is ignored by `backend/.gitignore` and was verified unstaged. The key value
is not recorded anywhere in the repository or in this changelog.

`DB_PASSWORD` was deliberately left empty in the local `.env` as well. Supplying the
development credential is part of M0.4, not M0.3.

### Verification

```text
Laravel boot                  PASS   php artisan about
Laravel 13 major              PASS   13.24.0
APP_KEY configured            PASS   local only, not committed
GET /api/v1/health            PASS   200, {"status":"ok"}, application/json
  via 127.0.0.1 and localhost PASS
  /api/api/v1/health          404    confirmed not created
  /v1/health                  404    confirmed not created
Health feature test           PASS
php artisan test              PASS   3 passed, 4 assertions
Pest                          PASS   4.7.8
Pint                          PASS   1.30.4, --test clean
```

Pint was confirmed to be actually inspecting files rather than trivially passing: a
deliberately misformatted throwaway file was rejected with eight fixers, then removed.

The health test runs without PostgreSQL or Redis. `phpunit.xml` keeps Laravel's defaults —
`CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, and an in-memory
SQLite connection that no test touches.

### Not done — deferred by scope

```text
Database connectivity test      M0.4
Migrations                      M0.4
Redis application integration   M0.4
Sanctum, CSRF, CORS, login      M0.7
Spatie Laravel Permission       M0.8
/api/v1/me                      M0.7
```

No migration was executed. No database or Redis connection was opened. No Sanctum or
Spatie package is present — verified against `composer.json` and `composer.lock`. Default
Laravel migrations remain in source as untouched scaffold.

`frontend/` and `docker-compose.yml` were not modified; both verified with `git diff`.
Docker containers were not touched.

### Open item raised

O-016 — `backend/.editorconfig` declares `root = true`, so the repository `.editorconfig`
does not apply inside `backend/`. See `DECISIONS.md`.

---

## 2026-08-08 — M0.2 clean-clone reproducibility fix

Branch `feat/m0-foundation`. Follows the M0.2 initialization below.

### Problem

A clean clone at `D:\Projects\notary-ppat-office-management` failed typecheck even though
`pnpm install --frozen-lockfile` succeeded and lint passed:

```text
src/app/layout.tsx(20,50): error TS2304: Cannot find name 'LayoutProps'.
```

### Root cause

`LayoutProps<"/">` is correct for Next.js 16.3.0. It is a **generated** global type, not a
hand-written one, and `tsconfig.json` already expects it:

```text
include:  next-env.d.ts
          .next/types/**/*.ts
          .next/dev/types/**/*.ts
```

`.gitignore` correctly excludes `/.next/` and `next-env.d.ts` because both are build
artifacts. In a clean clone neither exists, so all three include globs match nothing and the
global type is undefined. The original environment passed only because an earlier
`next dev` / `next build` had left `.next/types/` behind — the check was never reproducible,
it was merely incidentally satisfied.

Confirmed by inspection: `.next/types/routes.d.ts` line 51 declares
`type LayoutProps<LayoutRoute extends LayoutRoutes>`.

### Fix

`next typegen` exists in Next.js 16.3.0 and regenerates both `next-env.d.ts` and
`.next/types/` without a full build. The typecheck script now generates route types first:

```text
before   "typecheck": "tsc --noEmit"
after    "typecheck": "next typegen && tsc --noEmit"
```

A standalone `"typegen": "next typegen"` script was added so route types can be regenerated
on their own.

`layout.tsx` was **not** modified. Replacing `LayoutProps<"/">` with a hand-written children
type would have silenced the symptom and discarded Next.js route-aware typing.

### Verification

Verified from a genuinely clean state — `.next/` and `next-env.d.ts` were deleted before the
run, not merely assumed absent.

```text
pnpm typecheck   PASS   generates route types, then tsc --noEmit clean
pnpm lint        PASS
pnpm build       PASS   4 static routes
```

### Changed

- `frontend/package.json` — `typecheck` script; added `typegen` script

No generated artifact was committed. `.next/`, `next-env.d.ts`, and `node_modules/` remain
ignored.

---

## 2026-08-08 — M0.2 Frontend Initialization

Branch `feat/m0-foundation`. Records D-017 and D-018. First application code in the
repository.

### Generated versions

```text
next                 16.3.0     major 16 as required
react                19.2.8
react-dom            19.2.8
typescript           5.9.3
eslint               9.39.5
eslint-config-next   16.3.0
tailwindcss          4.3.3
@tailwindcss/postcss 4.3.3
packageManager       pnpm@11.20.0
```

`create-next-app@latest` resolved to 16.3.0, so the "stop if not major 16" gate passed. The
`packageManager` field was written by the scaffold itself and already matched the verified
workstation pnpm; no manual edit was needed.

Scaffold flags: `--ts --tailwind --eslint --app --src-dir --import-alias "@/*"` as specified,
plus `--use-pnpm`, `--disable-git`, and `--yes` to keep the run non-interactive. Experimental
options were declined — no React Compiler, no Rspack, no Biome, no `--api`, no `--empty`.

### shadcn/ui foundation

Initialized foundation only. No components added. See D-017 for the two CLI questions that
project documentation did not answer and how they were resolved.

Created `src/lib/utils.ts` and updated `src/app/globals.css`. Added `@base-ui/react`,
`class-variance-authority`, `clsx`, `lucide-react`, `shadcn`, `tailwind-merge`,
`tw-animate-css`.

### Acceptance criteria

```text
Next.js runs         PASS   HTTP 200, ready in 997ms, Turbopack
TypeScript works     PASS   tsc --noEmit clean
Tailwind works       PASS   v4 detected and validated by the shadcn CLI
shadcn initialized   PASS   components.json written
lint passes          PASS   eslint exit 0
typecheck passes     PASS   exit 0
build passes         PASS   4 static routes generated
```

The dev server was started only for the smoke test and shut down afterwards. Port 3000 was
verified released with no stray node processes.

### Added

`frontend/` — 25 files. Scaffold output plus four additions:

```text
.env.example        public placeholders only
.prettierrc.json    tabWidth 2, endOfLine lf, tailwind plugin
.prettierignore
package.json        typecheck, format, format:check scripts
```

One correction to scaffold output: `frontend/.gitignore` ships `.env*`, which would have
excluded `.env.example` from version control. Added `!.env.example` so the placeholder file
stays tracked.

`frontend/AGENTS.md` and `frontend/CLAUDE.md` are standard scaffold output and were kept. See
O-015.

### Changed

- `docs/DECISIONS.md` — added D-017, D-018, O-014, O-015

### Not done

- No next-intl, no locale routing, no TanStack Query, no Axios client.
- No authentication, application shell, sidebar, or dashboard.
- No business modules, no fake statistics, no database integration.
- No backend, Docker, or legal-workflow documentation touched.
- Not merged into `main`.

---

## 2026-08-08 — PostgreSQL 18 Docker data-directory compatibility correction

First infrastructure smoke test. Records D-016.

### Problem

The PostgreSQL container never started. It sat in a restart loop from creation.

```text
old mount   postgres_data:/var/lib/postgresql/data
```

The image entrypoint rejected it and reported `/var/lib/postgresql/data` as an unused
mount/volume. From PostgreSQL 18 the official image places data in a major-version
subdirectory and expects a single mount one level higher, so `pg_upgrade --link` does not
cross a mount boundary.

### Correction

```text
new mount   postgres_data:/var/lib/postgresql
```

`postgres:18`, the database name, the user, the development password mechanism, the
`127.0.0.1` port binding, the healthcheck, the restart policy, and the entire Redis service
were all left untouched.

An inline comment was added at the volume declaration pointing to D-016, because the wrong
form is still widespread in online examples and is easy to reintroduce.

### Smoke-test result

```text
PostgreSQL     18.4 (Debian 18.4-1.pgdg13+1)   healthy
data_directory /var/lib/postgresql/18/docker
database       notary_ppat_office              encoding UTF8
user           notary_app                      connects successfully
binding        127.0.0.1:5432 -> 5432

Redis          8.10.0                          healthy, PONG
binding        127.0.0.1:6379 -> 6379
uptime         unbroken across the repair
```

The observed PostgreSQL minor is 18.4. Per D-005 this is recorded as observed state, not as
a pinned requirement.

### Volume handling

Only `notary_ppat_postgres_data` was removed and recreated. It had been created minutes
earlier by the failed smoke test and contained no application or client data — no Laravel or
Next.js application exists, and no tables were ever created.

`notary_ppat_redis_data` was preserved. `docker compose down -v` was deliberately not used,
as it would have destroyed both volumes. The Redis container was never stopped.

### Changed

- `docker-compose.yml` — PostgreSQL volume target corrected; regression comment added
- `docs/DECISIONS.md` — added D-016 making the PostgreSQL 18+ mount target canonical

### Not done

- No PostgreSQL or Redis version change.
- No credential change.
- No application tables, migrations, or client data.
- No frontend or backend initialization.

---

## 2026-08-08 — M0.2B Backend Toolchain and Package Manager

Resolves O-011, O-012, O-013. Records D-014 and D-015.

### Workstation state

Laravel Herd was reinstalled, which fixed both outstanding backend problems at once.

```text
Herd        1.29.0
PHP         8.4.23   warning-free; 8.5.8 also available
Composer    2.10.1   using php84
Laravel     5.30.0   installer
Node        24.19.0
npm         11.17.0
corepack    0.35.0
pnpm        11.20.0
```

Command resolution, all from `C:\Users\User\.config\herd\bin\`:

```text
herd      herd.bat
php       php.bat
composer  composer.bat
laravel   laravel.bat
node      C:\Program Files\nodejs\node.exe   (nvm symlink -> v24.19.0)
pnpm      C:\Program Files\nodejs\pnpm       (corepack shim)
```

`php --ini` loads `C:\Users\User\.config\herd\bin\php84\php.ini` with no additional scan
directory. `php -m` lists every extension Laravel needs, including `pdo_pgsql` and `pgsql`
for PostgreSQL, plus `redis`, `mongodb`, and `herd` which previously failed to load.

Node 24.19.0 survived the Herd reinstall; the nvm symlink was not reset.

### Changed

**`docs/DECISIONS.md`**

- added D-014: local development PHP is 8.4. **D-005 is explicitly unchanged** — the project
  requirement stays `PHP >= 8.3`. 8.4 is a workstation runtime choice, not a raised floor,
  and code must not assume 8.4-only features
- added D-015: pnpm is provisioned through corepack rather than a global npm install
- O-011 marked resolved: Herd's bin is now on the persisted USER PATH
- O-012 marked resolved: extensions load, verified through `php -m`, not merely silenced
- O-013 marked resolved: pnpm 11.20.0

### Not done

- Docker not installed. Local PostgreSQL 18 and Redis 8 remain unavailable.
- No frontend or backend initialization.
- No packages, migrations, containers, or business modules.

---

## 2026-08-08 — M0.2A Node Runtime Normalization

Resolves O-008. Records D-013 and correction C-001.

### Workstation runtime

Node was migrated off the EOL v25 line onto the 24 LTS line. No repository file was touched
by the migration itself.

```text
before   node v25.9.0   npm 11.12.1
after    node v24.19.0  npm 11.17.0   npx 11.17.0   corepack 0.35.0
```

Method: the existing Node was already managed by nvm-windows 1.1.11, with
`C:\Program Files\nodejs` as a symlink into the nvm store. No MSI uninstall or elevated
installer was needed — `nvm install 24.19.0` followed by `nvm use 24.19.0` was sufficient.

v25.9.0 remains in the nvm store but is not on PATH. Exactly one `node.exe` resolves.

### Changed

**`docs/DECISIONS.md`**

- added D-013: Node 24.x LTS is the runtime line; v25 is rejected as EOL; Next.js target
  16.x and the `>= 20.9` minimum are unchanged
- added C-001: correction to the M0.2 environment audit — PHP, Composer, and the Laravel
  Installer are installed via Laravel Herd; the audit tested PATH resolution only. D-005 is
  unchanged, because both Herd PHP builds satisfy `>= 8.3`
- O-008 marked resolved
- added O-011: Herd's `bin` is not on PATH, so `composer` and `laravel` fail
- added O-012: three Herd PHP extensions fail to load from a missing directory
- added O-013: pnpm not installed; corepack is now available under Node 24

### Not done

- pnpm not installed; the command is reported only.
- No frontend or backend initialization.
- No PHP, Composer, Laravel Installer, or Docker installed.
- No packages, migrations, containers, or business modules.
- v25.9.0 not removed from the nvm store; kept as a rollback path.

---

## 2026-08-08 — GitHub remote connected

Resolves O-009. Updates D-012.

### Repository

```text
remote      origin
url         https://github.com/mdeswendi/notary-ppat-office-management.git
branch      main -> origin/main (tracking)
commit      93ff35b (local and remote identical)
files       25
visibility  private, verified
```

Visibility verification method: an anonymous `git ls-remote` with the credential helper
disabled was rejected; the same call using the stored credential succeeded. A public
repository would have answered the anonymous probe. Visibility is therefore confirmed by
observation, not assumed.

The remote was empty before the push — no GitHub-generated README, `.gitignore`, or LICENSE
— so the first push was a fast-forward with no merge or force.

Pushed content is documentation and tooling configuration only. No application code, no
secrets, no client data.

### Changed

**`docs/DECISIONS.md`**

- D-012 updated: remote URL recorded, visibility marked verified with the method used, and
  a requirement that any future switch to public be recorded here first
- O-009 marked resolved
- added O-010: `gh` CLI still absent, so remote repository administration is not available
  from the terminal; not a blocker

### Not done

- No software installed or upgraded.
- No frontend or backend initialization.
- No packages, migrations, containers, or business modules.
- No branch protection or repository settings configured.

---

## 2026-08-08 — Version control initialized

Resolves O-007. Records D-012.

### Repository

- `git init` on branch `main`
- 25 files tracked, working tree clean
- no remote configured; local only, by decision

Commit history:

```text
3874e77  docs: add Claude coding instructions
8c94dde  docs: add canonical specification set
eb00d82  chore: initialize repository structure and tooling
```

Verified before committing that `.gitignore` does not exclude `backend/.gitkeep`,
`frontend/.gitkeep`, `infra/.gitkeep`, `scripts/.gitkeep`, `.github/.gitkeep`, or
`docker-compose.yml`.

### Changed

**`docs/DECISIONS.md`**

- added D-012: repository initialized, branch naming, GitHub account recorded, remote
  visibility fixed as **private** when created
- O-007 marked resolved
- added O-009: no GitHub remote yet; `gh` CLI absent

### Not done

- No GitHub remote created and nothing pushed.
- No software installed or upgraded.
- No frontend or backend initialization.
- No packages, migrations, containers, or business modules.
- Git identity not modified.

---

## 2026-08-08 — M0.2 Environment Readiness Audit

Configuration and documentation only. No software was installed, upgraded, or started.

### Changed

**`.editorconfig`** — resolves O-005, records D-011

- indentation is now declared per ecosystem instead of one global width
- PHP and Blade: 4 spaces (PSR-12)
- TypeScript, TSX, JavaScript, JSX, CSS, SCSS: 2 spaces (Prettier / Next.js scaffold default)
- JSON, JSONC, YAML, YML: 2 spaces
- general default remains 4 spaces
- Markdown keeps its trailing-whitespace exemption and gains no further whitespace rules
- header comment now points to D-011

No Prettier configuration was created; none exists yet.

**`docs/DECISIONS.md`**

- added D-011 (per-ecosystem indentation)
- O-005 marked resolved
- O-006 marked deferred, with the release condition stated explicitly and a note that it is
  not an M0 blocker
- O-004 marked deferred as cosmetic
- added O-007: the working directory is not a Git repository, so the first M0.1 acceptance
  criterion in `10_M0_FOUNDATION.md` section 67 is unmet
- added O-008: installed Node.js is a Current release rather than an LTS line

### Not done

- No CI/CD workflows. `.github/.gitkeep` remains sufficient.
- No milestone naming changes.
- No software installed or upgraded.
- No containers started.
- No frontend or backend initialization.
- No packages, migrations, or business modules.
- No Git identity modified and nothing committed.

---

## 2026-08-08 — M0.1 Repository Foundation

Resolves O-001, O-002, O-003. Records D-009 and D-010 in `DECISIONS.md`.

### Added

| File | Note |
|---|---|
| `.editorconfig` | UTF-8, LF, final newline, trim trailing whitespace, 4-space default, 2 spaces for JSON/YAML, CRLF for `.bat`/`.cmd`, trailing whitespace preserved in Markdown. |
| `.gitattributes` | `* text=auto eol=lf` plus explicit text rules for Markdown, TypeScript/JavaScript, PHP, JSON, YAML, XML, shell, and config files. Binary marking limited to images, fonts, and archives; no application source is marked binary. |
| `docker-compose.yml` | Local development infrastructure only: `postgres:18` and `redis:8-alpine`, named volumes, healthchecks, ports bound to `127.0.0.1`. No frontend or backend containers. |
| `.github/.gitkeep` | Reserves the directory. No CI/CD workflows yet. |

### Changed

**`CLAUDE.md`** — O-002, O-003

- section 3: added explicit versions — Next.js 16.x, Node.js >= 20.9, Laravel 13.x,
  PHP >= 8.3; added Database subsection (PostgreSQL 18.x, latest supported minor) and
  Infrastructure subsection (Redis 8.x, private file storage); PostgreSQL moved out of the
  Backend list into its own subsection
- section 58: documentation list replaced with the full 14-entry canonical set; added the
  `DECISIONS.md` precedence rule, the 08/09 `DRAFT — DOMAIN VALIDATION REQUIRED`
  restriction, and the scope limit of `11_LEGAL_REFERENCES.md`

**`docs/01_ARCHITECTURE.md`** — O-001

- section 2: root structure updated to the canonical 12 entries, adding `.github/`,
  `.editorconfig`, `.gitattributes`, and `docker-compose.yml`; added cross-references to
  `10_M0_FOUNDATION.md` section 7 and D-003, and a note that Compose is local-only

**`.gitignore`** — minimal M0.1 corrections

- removed the Python block; it does not apply to this stack, and its `env/` entry would
  have ignored any directory named `env`
- added a PHP/Laravel block: `vendor/`, `.phpunit.result.cache`, `.phpunit.cache/`,
  `.php-cs-fixer.cache`
- scoped `uploads/`, `storage/`, `media/`, `backups/`, and `logs/` to the repository root.
  The bare `storage/` pattern would have excluded Laravel's `backend/storage/` tree, which
  ships its own `.gitignore` files and must stay tracked. This would have broken M0.3.

**`README.md`** — minimal M0.1 corrections

- status now states M0.1 explicitly and that Next.js and Laravel are not initialized
- repository structure updated to the canonical 12 entries
- added Technology Baseline table with the caveat that versions are unverified
- replaced the previous hand-written scope list with a documentation index; scope is owned
  by `00_PROJECT_OVERVIEW.md` and must not be duplicated
- added local infrastructure commands and the M0.1–M0.10 sequence

### Not done

- No frontend or backend initialization.
- No package installation.
- No migrations.
- No CI workflows.
- No containers started.
- No Notary or PPAT legal requirements authored.

---

## 2026-08-08 — Documentation normalization

Applies the canonical decisions recorded in `DECISIONS.md` (D-001 … D-008).

### Added

| File | Note |
|---|---|
| `08_NOTARY_WORKFLOW.md` | Placeholder. `DRAFT — DOMAIN VALIDATION REQUIRED`. No legal workflow authored. |
| `09_PPAT_WORKFLOW.md` | Placeholder. `DRAFT — DOMAIN VALIDATION REQUIRED`. No legal workflow authored. |
| `10_M0_FOUNDATION.md` | M0 Foundation Implementation Specification, 80 sections, including M0.1–M0.10 acceptance criteria, quality gates, commands, environment examples, and Definition of Done. Specification only; not executed. |
| `11_LEGAL_REFERENCES.md` | Legal reference register only. Confers no operational rules. |
| `DECISIONS.md` | Canonical decisions register with precedence rule. |
| `CHANGELOG.md` | This file. |

### Changed

**`02_MENU_AND_PERMISSIONS.md`** — D-001

- section 13: `documents.view_sensitive` → `documents.sensitive.view`
- section 13: `documents.download_sensitive` → `documents.sensitive.download`
- section 16: added `billing.amount.view`
- section 8 already used the canonical `parties.identity.*` form; unchanged

**`03_DATABASE_ERD.md`** — D-004, D-005

- section 1: added Engine Configuration — PostgreSQL 18.x, UTF-8, UTC storage,
  `Asia/Jakarta` default office timezone, `notary_ppat_office` local database
- section 6: added `entity_type` and `relationship_type` value lists
- section 7: added `project_parties.role_code` examples
- section 9: added `matter_parties.role_code` examples for PPAT transfer and corporate matters
- section 16: added `right_type` value list and `matter_properties.role_code` examples
- section 27: added internal numbering patterns
  `PRJ-{YYYY}-{SEQ:6}`, `N-{YYYY}-{SEQ:6}`, `P-{YYYY}-{SEQ:6}`, `PROP-{SEQ:6}`, `DOC-{YYYY}-{SEQ:6}`
- section 34 added: Notifications
- section 35 added: Referential Delete Strategy — `RESTRICT` for legal relationships,
  `CASCADE` selectively for non-legal dependent data

**`04_UI_DESIGN_SYSTEM.md`** — D-006, D-007

- section 14: status badge restored to `●` for all four states; item lifecycle legend
  `○ ● ✓ !` added; SLA Indicator subsection added (GREEN / YELLOW / RED)
- sections 15, 18: checkbox restored to `□`
- sections 19, 20, 21: status marker restored to `●`
- section 22: requirement markers restored to `✓` and `●`
- section 30: Warkah markers restored to `✓` and `●`
- section 33: lock restored to `🔒`

Sections 1–13, 16, 17, 23–29, 31, 32, 34–37 unchanged.

### Not changed

- `00_PROJECT_OVERVIEW.md`, `01_ARCHITECTURE.md`, `05_I18N_LEGAL_TERMINOLOGY.md`,
  `06_API_CONVENTIONS.md`, `07_SECURITY_RULES.md` — no instruction to modify.
  `05_I18N_LEGAL_TERMINOLOGY.md` already conformed to D-002.
- `CLAUDE.md` — no instruction to modify. See Open Items O-002 and O-003 in `DECISIONS.md`.

### Not done

- No frontend or backend code.
- No Next.js or Laravel initialization.
- No package installation.
- No migrations.
- No Notary or PPAT legal requirements authored.

---

## 2026-08-08 — Initial documentation import

- Imported `00_PROJECT_OVERVIEW.md` … `07_SECURITY_RULES.md` as baseline v1.0.
- Repaired UTF-8/cp1252 mojibake introduced in transfer; box-drawing, arrows, dashes, and
  `²` restored. Symbols that could not be determined at the time were replaced with a
  neutral ASCII marker and reported rather than guessed — later restored under D-006.
- Created repository skeleton: `frontend/`, `backend/`, `docs/`, `infra/`, `scripts/`,
  `CLAUDE.md`, `README.md`, `.gitignore`.
