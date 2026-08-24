# Notary & PPAT Office Management System
## Canonical Decisions Register

## How This File Works

This file records explicit decisions that resolve conflicts in the source material.

```text
When older PDF or chat material conflicts with DECISIONS.md, the newer explicit decision
in DECISIONS.md takes precedence unless later superseded.
```

Each decision carries a date. A later dated decision supersedes an earlier one on the same
subject. Superseded decisions are kept, marked, and never deleted.

---

## 2026-08-08 — Documentation Normalization

Origin: explicit instruction resolving the conflicts reported in the source-material audit.

### D-001 — Sensitive-data permission names

Canonical:

```text
parties.identity.nik.view_full
parties.identity.npwp.view_full
documents.sensitive.view
documents.sensitive.download
billing.amount.view
```

Superseded variants, which must not be retained as aliases:

```text
party.identity.nik.view_full
documents.view_sensitive
documents.download_sensitive
```

Applied in: `02_MENU_AND_PERMISSIONS.md` sections 13 and 16.

### D-002 — Workflow stage codes

```text
REGISTRATION   workflow stage
COMPLETION     workflow stage
COMPLETED      record / status state
```

`REGISTRATION_PROCESS` is replaced by `REGISTRATION` wherever it denotes a workflow stage.

`COMPLETION` must not be replaced by `COMPLETED` where it denotes a workflow stage. The two
codes describe different concepts and both remain valid in their own domain.

Applied in: `05_I18N_LEGAL_TERMINOLOGY.md` sections 8 and 9, which already conformed.

### D-003 — Repository root structure

The documented root structure additionally includes:

```text
.github/
.editorconfig
.gitattributes
docker-compose.yml
```

alongside the previously documented entries.

Recorded in: `10_M0_FOUNDATION.md` section 7.

Note: `01_ARCHITECTURE.md` section 2 still shows the shorter list. See Open Items below.

### D-004 — Encoding and timezone

```text
Documentation encoding   UTF-8
Database encoding        UTF-8
Timestamp storage        UTC
Default office timezone  Asia/Jakarta
```

Applied in: `03_DATABASE_ERD.md` section 1.

### D-005 — Technology baseline

```text
Next.js       16.x
Node.js       >= 20.9
Laravel       13.x
PHP           >= 8.3
PostgreSQL    18.x, latest supported minor release
Local dev DB  notary_ppat_office
```

A specific PostgreSQL minor release such as 18.4 must not be recorded as a permanent
application requirement.

Applied in: `10_M0_FOUNDATION.md` sections 2–4, `03_DATABASE_ERD.md` section 1.

These version numbers come from the source material and have not been verified against
current releases. Verify before installation.

### D-006 — UI status symbols

Restored from the source material rather than guessed:

```text
○  Not Started
●  In Progress
✓  Completed / Verified
!  Blocked / Missing
🔒 Locked
□  Unchecked checkbox
```

In the standard status badge, all four states use `●`; colour carries the distinction, with
the text label always present.

Applied in: `04_UI_DESIGN_SYSTEM.md` sections 14, 15, 18, 19, 20, 21, 22, 30, 33.

### D-007 — SLA indicator

```text
GREEN   On Track
YELLOW  Due Soon
RED     Overdue
```

Presentation indicators only. They do not define statutory or legal deadlines.

Applied in: `04_UI_DESIGN_SYSTEM.md` section 14.

### D-008 — Legal workflow documents remain unwritten

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are created as placeholders carrying
`DRAFT — DOMAIN VALIDATION REQUIRED`. No legal workflow content may be authored or inferred
without a cited domain source.

`11_LEGAL_REFERENCES.md` records statutory references only and confers no operational rules.

---

## 2026-08-08 — M0.1 Repository Foundation

### D-009 — Local development infrastructure

Docker Compose provisions local development infrastructure only. It is not a production
deployment specification.

```text
PostgreSQL   postgres:18
Redis        redis:8-alpine
```

Both are pinned to a major tag only. Do not pin a minor release.

Frontend and backend are not containerized at M0. They run natively for faster hot reload,
per `10_M0_FOUNDATION.md` section 47.

The PostgreSQL password uses a development-only fallback expression
`${POSTGRES_PASSWORD:-local-development-only}`. No production secret enters the repository.

Ports are bound to `127.0.0.1` only, so the services are not exposed on the network.

### D-010 — Root structure is documented in three places and must agree

The canonical root structure appears in:

```text
docs/01_ARCHITECTURE.md   section 2
docs/10_M0_FOUNDATION.md  section 7
docs/DECISIONS.md         D-003
```

`01_ARCHITECTURE.md` is the primary reference. Any change must be applied to all three.

---

## 2026-08-08 — M0.2 Environment Readiness Audit

### D-011 — Indentation follows each ecosystem's own convention

Resolves O-005. A single global indent width was rejected because it would fight the
frontend formatter.

```text
General / default    4 spaces
PHP, Blade           4 spaces
TypeScript, TSX      2 spaces
JavaScript, JSX      2 spaces
CSS, SCSS            2 spaces
JSON, JSONC          2 spaces
YAML, YML            2 spaces
Markdown             no indent rule; trailing whitespace preserved
```

Rationale: PSR-12 uses 4 spaces for PHP; Prettier and the Next.js scaffold both default to
2 spaces for TypeScript/JavaScript. Aligning `.editorconfig` with each avoids reformatting
churn once the frontend is initialized.

Applied in: root `.editorconfig`.

**Scope, added 2026-08-09 resolving O-016.** The root `.editorconfig` is the *only*
EditorConfig file in the repository, and it governs `frontend/`, `backend/`, and every
other directory. A nested `.editorconfig` carrying `root = true` halts the upward search
and silently exempts its subtree from this decision, so nested EditorConfig files must not
be introduced. If a scaffold ships one — the Laravel skeleton does — remove it during
initialization rather than leaving repository policy partially applied.

No Prettier configuration is created at this stage. When one is added at frontend
initialization, it must agree with this decision.

### D-012 — Version control and repository hosting

Resolves O-007.

```text
Git repository   initialized 2026-08-08
Initial branch   main
Remote           origin
Remote URL       https://github.com/mdeswendi/notary-ppat-office-management.git
GitHub account   https://github.com/mdeswendi
Visibility       PRIVATE (verified 2026-08-08)
```

The remote must remain **private**.

Visibility was verified, not assumed: an anonymous `git ls-remote` with the credential
helper disabled was rejected, while the same call with stored credentials succeeded. A
public repository would have answered the anonymous probe.

If the repository is ever made public, that is a reversal of this decision and must be
recorded here first.

Rationale: `docs/` already contains the complete permission matrix, the data-scope model,
NIK/NPWP masking rules, and the document access architecture for a working legal office.
The repository will later hold code that processes penghadap identity data, Minuta Akta, and
Warkah. A public repository would publish that design surface and cannot be meaningfully
retracted once indexed or forked. This is consistent with `docs/07_SECURITY_RULES.md`.

Development branch naming follows `10_M0_FOUNDATION.md` section 60:

```text
feat/m0-foundation
feat/m1-identity
feat/m2-parties
feat/m3-projects
```

---

## 2026-08-08 — M0.2A Node Runtime Normalization

### D-013 — Node.js runtime line

Resolves O-008.

```text
Runtime line     Node.js 24.x LTS
Installed        24.19.0
npm              11.17.0
Managed by       nvm-windows 1.1.11
Rejected line    Node.js 25.x — EOL, must not be used
```

Use the latest supported patch in the Node 24 LTS line. This documentation is deliberately
not pinned to a single patch version; 24.19.0 records what is installed today, not a
permanent requirement.

Unchanged by this decision:

```text
Next.js target                16.x
Next.js minimum Node          >= 20.9
```

Node 24.19.0 satisfies the `>= 20.9` minimum with margin, and is an LTS line rather than a
Current line, which the earlier v25.9.0 was not.

Side effect worth noting: Node 24 still bundles `corepack` (0.35.0). Node 25 did not. This
directly affects how pnpm is installed — see O-013.

### C-001 — Correction to the M0.2 environment audit

The M0.2 audit reported PHP, Composer, and the Laravel Installer as **not installed**. That
was wrong. They are installed, via **Laravel Herd** at `C:\Program Files\Herd`:

```text
PHP 8.4.23        C:\Users\User\.config\herd\bin\php84\php.exe
PHP 8.5.8         C:\Users\User\.config\herd\bin\php85\php.exe
Composer          C:\Users\User\.config\herd\bin\composer.phar
Laravel Installer C:\Users\User\.config\herd\bin\laravel.phar
nginx             C:\Users\User\.config\herd\bin\nginx
```

The audit checked PATH resolution only. `C:\Users\User\.config\herd\bin` is not on PATH —
only `...\herd\bin\nvm` is — so every bare command lookup failed. The tools exist and PHP
runs correctly when invoked by absolute path.

Both PHP builds satisfy the `>= 8.3` baseline in D-005. D-005 is therefore **unchanged**;
only the audit finding was wrong.

This correction does not by itself make the backend toolchain usable. See O-011 and O-012.

---

## 2026-08-08 — M0.2B Backend Toolchain and Package Manager

### D-014 — Local development PHP runtime

Resolves the PHP 8.4 vs 8.5 question for the workstation only.

```text
Local development PHP   8.4  (currently 8.4.23, supplied by Laravel Herd)
Also available          8.5.8
```

**D-005 is unchanged.** The project requirement in the documentation remains `PHP >= 8.3`.
PHP 8.4 is the runtime chosen for this workstation today, not a raised project floor. Code
must not assume 8.4-only features.

Herd 1.29.0 supplies PHP, Composer 2.10.1, the Laravel Installer 5.30.0, and nginx.

### D-015 — pnpm is provisioned through corepack

```text
Mechanism   corepack 0.35.0, bundled with Node 24
Command     corepack enable pnpm
Installed   pnpm 11.20.0
```

Chosen over `npm install -g pnpm` because corepack ships with Node, requires no elevation,
and allows the pnpm version to be pinned per project through the `packageManager` field in
`package.json` once the frontend exists.

This replaces the earlier caveat in D-013: under Node 25 corepack was unbundled, so this
path only became available after the migration to Node 24 LTS.

---

## 2026-08-08 — PostgreSQL 18 Docker mount correction

### D-016 — PostgreSQL 18+ persistent mount target

Amends the volume detail of D-009. The image choice `postgres:18` is unchanged.

```text
Correct    postgres_data:/var/lib/postgresql
Wrong      postgres_data:/var/lib/postgresql/data
```

From PostgreSQL 18 the official Docker image stores data in a major-version subdirectory so
that `pg_upgrade --link` works without crossing a mount boundary. It expects **one** mount at
`/var/lib/postgresql`. Mounting `/var/lib/postgresql/data` makes the container refuse to
start; the entrypoint reports that path as an unused mount/volume and exits, leaving the
container in a restart loop.

Verified after the correction:

```text
data_directory   /var/lib/postgresql/18/docker
```

That path is created by the image beneath the single mount. Do not mount it directly and do
not reintroduce the `/data` suffix. `docker-compose.yml` carries an inline comment pointing
here to prevent regression.

This applies to PostgreSQL 18 and later only. Images up to 17 used
`/var/lib/postgresql/data`, which is why the older form is still common in examples found
online.

---

## 2026-08-08 — M0.2 Frontend Initialization

### D-017 — shadcn/ui foundation configuration

The shadcn CLI now asks two questions the project documentation never answered. Both were
answered with the CLI's own default, as instructed, and are recorded here so the choice is
deliberate rather than accidental.

**Component primitive library — `base`**

```text
Offered   Base UI (Recommended) | React Aria | Radix UI
Chosen    Base UI
```

`04_UI_DESIGN_SYSTEM.md` section 55 and `CLAUDE.md` section 40 name shadcn/ui but never the
primitive layer beneath it. Those documents were written when Radix was the only option.
Base UI is now the CLI default. Revisit before adding many components — migrating primitives
later is expensive.

**Preset — `nova`**

```text
Offered   Nova (Lucide / Geist) | Vega | Maia | Lyra | Mira | Luma | Sera | Rhea | Custom
Chosen    Nova
```

Nova uses Lucide icons, which matches `04_UI_DESIGN_SYSTEM.md` section 9 exactly. It also
brings the Geist font, which does not match the Inter recommendation in section 6. See
O-014; typography belongs to M0.6 and was not touched here.

Resulting `components.json`:

```text
style          base-nova
baseColor      neutral
cssVariables   true
iconLibrary    lucide
rsc            true
aliases        @/components, @/lib, @/lib/utils, @/components/ui, @/hooks
```

`baseColor` is `neutral`. No product colour or branding was invented. The navy and domain
accents in `04_UI_DESIGN_SYSTEM.md` are M0.6 work.

### D-018 — Frontend formatting

```text
prettier                     3.9.6
prettier-plugin-tailwindcss  0.8.1
```

`.prettierrc.json` sets `tabWidth: 2` and `endOfLine: "lf"`, agreeing with `.editorconfig`
and D-011. No ESLint rule and no TypeScript strictness setting was weakened to make the
checks pass.

Scripts added to `frontend/package.json`: `typecheck` (`tsc --noEmit`), `format`,
`format:check`. `lint` and `build` came from the scaffold.

---

## 2026-08-08 — M0.3 Backend Initialization

### D-019 — Backend is initialized by Composer, not by `laravel new`

`10_M0_FOUNDATION.md` section 15 specifies `laravel new backend` answered interactively
with PostgreSQL / no starter kit / Pest. That is **superseded for initialization only**.
The application produced is the same; the command that produces it is not.

```text
Used      composer create-project laravel/laravel backend "^13.0" --no-scripts --no-interaction
Instead   laravel new backend
```

Two reasons, both about determinism rather than preference:

**Version constraint.** `laravel new` installs whatever is current. Today that is Laravel
13, but the constraint is implicit, so the same command run after Laravel 14 ships would
silently produce a different major. `"^13.0"` states the requirement in D-005 explicitly.

**Migrations must not run.** The skeleton's `post-create-project-cmd` is:

```text
@php artisan key:generate --ansi
@php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
@php artisan migrate --graceful --ansi
```

M0.3 forbids database execution; M0.4 owns it. `--no-scripts` suppresses all three.
`key:generate` was then run on its own, so the only skipped effects were the SQLite file
and the migration. Verified afterwards: no `database/*.sqlite` exists.

Consequence for future milestones: `composer install` in a clean clone does **not** create
`.env` or set `APP_KEY`, because `post-root-package-install` only fires on a fresh
create-project. Setup documentation must state both steps explicitly.

`routes/api.php` was likewise written by hand rather than generated by
`php artisan install:api`, because that command installs Laravel Sanctum. Sanctum belongs
to M0.7 and must not appear in the dependency tree before it.

---

## 2026-08-09 — M0.5 Internationalization Foundation

### D-020 — The URL is the only source of the active locale

```text
localeDetection: false
localeCookie:    false
```

next-intl by default negotiates the locale from the `accept-language` header and a
`NEXT_LOCALE` cookie. Measured before changing it: `/` redirected an `en-US` browser to
`/en`. Indonesian was therefore not actually the default locale — it was merely the
fallback for browsers that did not ask for something else. `/` must be deterministic, so
detection is off and `/` always resolves to `/id`.

The cookie is disabled as well. With detection off it was still written but never read,
and a cookie that looks authoritative while being inert misleads the next reader.

**Tension with `05_I18N_LEGAL_TERMINOLOGY.md` section 19**, which states that a user's
language choice should persist across sessions. That remains the intended end state, but
it is a property of an authenticated user's `preferred_locale`, not of a header guess made
before anyone has signed in. Section 19 is deferred, not contradicted: whoever implements
profile language preference should apply it as a redirect target for authenticated users
and must not re-enable `localeDetection` to get it, or `/` becomes non-deterministic again.

Locale is never read from `localStorage` or `sessionStorage`.

---

## 2026-08-09 — M0.6 UI Foundation

### D-021 — Status and domain-accent colour values

`04_UI_DESIGN_SYSTEM.md` gives exact hex for the core palette — primary `#172554`,
page `#F8FAFC`, card `#FFFFFF`, border `#E2E8F0` — and those were used verbatim. It names
the status concepts (section 5) and the domain accents (section 6) only as colour
*families*, with no values. Those values are therefore chosen here rather than derived:

```text
success  #16A34A      warning  #D97706      info  #0284C7
notary   #4338CA      ppat     #0F766E
```

`notary` is indigo rather than the brand navy so a domain badge stays distinguishable from
primary chrome; `ppat` is teal rather than emerald so it does not read as `success`.
Section 5 also requires that status never be carried by colour alone, so these tokens are
always paired with text or an icon.

Stored as OKLCH to match the existing token file, with the source hex in comments. Anyone
adding a status or domain colour should extend this list rather than introduce a parallel
palette.

---

## 2026-08-09 — M0.7 Authentication Foundation

### D-022 — Protected routes are verified server-side against Laravel

The Sanctum cookie/session architecture itself is already canonical and is not restated
here. Three consequences of implementing it are, because each one silently breaks
authentication if it is undone later.

**Protection asks Laravel; it never inspects a cookie.** A protected page forwards the
browser's cookies to `GET /api/v1/me` and redirects on 401. The presence of a session
cookie proves nothing — it may be stale, forged, or belong to an invalidated session — so
it is never treated as authentication. This also keeps anonymous rejection verifiable over
plain HTTP.

**The server-side check must send an `Origin` header.** Sanctum chooses cookie versus token
authentication by matching Origin/Referer against `SANCTUM_STATEFUL_DOMAINS`. A browser
sends this automatically; a server-to-server fetch does not, and without it the session
cookie is ignored and every request appears anonymous. `NEXT_PUBLIC_APP_URL` supplies the
value and must remain listed in `SANCTUM_STATEFUL_DOMAINS`.

**No `loading.tsx` may sit above a protected route.** A parent loading boundary makes
Next.js stream a 200 with the fallback and deliver the redirect inside the stream, so
protection degrades to a client-side redirect and stops being HTTP-verifiable. The
locale-level `loading.tsx` added in M0.6 was removed for exactly this reason.

Also recorded: Sanctum 4.3.3 only *publishes* its migration rather than loading it, so
**no `personal_access_tokens` table exists**. First-party authentication is session-only
and issues no token. Should third-party API tokens ever be required, that migration must
be published deliberately — it is not an accident that the table is missing.

---

## 2026-08-09 — O-019 User Primary Key Alignment

### D-023 — The users scaffold migration was corrected in place

Resolves O-019. `users.id` is now a ULID, and the change was made by editing the original
`0001_01_01_000000_create_users_table.php` rather than adding a bigint-to-ULID conversion
migration.

That contradicts the standing rule in D-019 against editing an already-executed migration,
so the exception is recorded rather than taken quietly. It applies to this correction only.

Why editing was the right call here:

```text
no application data      users table held 0 rows
pre-release              M1 has not started; nothing has shipped
Spatie not installed     no morph keys exist yet to convert
SQLite compatibility     ALTER COLUMN type changes are awkward in SQLite,
                         which the test suite runs on
```

A conversion migration would have been permanent: every clean clone would create a bigint
key and immediately rewrite it, and the incorrect foundational schema would stay in the
history forever. Correcting the create statement leaves a clean schema from the first
migration.

**Why `users` is ours and not a package table.** `10_M0_FOUNDATION.md` section 45 exempts
third-party package tables from the ULID rule. `users` is not one — it is listed as a core
table in `03_DATABASE_ERD.md` section 4, and `CLAUDE.md` section 11 and
`06_API_CONVENTIONS.md` section 14 both apply. The documents agree; only the Laravel
scaffold disagreed.

**Consequence for M0.8.** Spatie Laravel Permission creates polymorphic
`model_has_roles` / `model_has_permissions` keys whose type must match the model key.
Those tables must use the ULID-compatible morph key (`ulidMorphs`, via Spatie's
`model_morph_key` configuration), not the default `bigint`. Getting the key type right
before installing Spatie is why this correction was done first.

`sessions.user_id` was changed to a nullable ULID in the same migration. Leaving it as a
bigint would have silently failed to store `Auth::id()` once a user logged in.

Identifiers are opaque: nothing may parse a ULID, sort by it, or infer creation order from
it. The frontend `CurrentUser.id` is typed `string`.

---

## 2026-08-09 — M0.10 Foundation Acceptance

### D-024 — M0 Definition of Done verified from a clean clone

Every item in `10_M0_FOUNDATION.md` section 77 was checked against a repository cloned
fresh from `origin`, not against the working directory. Recorded because the distinction is
what makes the result meaningful: the working directory had accumulated `node_modules`,
`vendor`, a `.env`, and an `APP_KEY` that a new developer would not have.

Two properties worth keeping:

**The README was wrong and that counted as a failure.** It still described the M0.1 state —
frontend and backend "belum diinisialisasi", no setup, migration, or quality commands. It
was rewritten before the clone test, and the clone was then set up by following it
literally. Future milestones should treat README drift the same way, as a reproducibility
defect rather than a documentation nicety.

**`docker-compose.yml` sets `name: notary-ppat-office` explicitly**, so Compose project
identity does not depend on the directory name. The clean clone therefore observed the
already-running containers instead of creating a second stack, and `docker compose up -d`
was idempotent. Removing that `name:` key would silently break this.

The clean clone generated its **own** `APP_KEY`, verified different from the primary
checkout's. Migrations ran from zero, both quality gates passed, both servers booted, and a
22-point full-stack acceptance passed end to end.

---

## 2026-08-09 — Composer resolution baseline

### D-025 — Dependency resolution is pinned to the minimum supported PHP

```json
"config": { "platform": { "php": "8.3.0" } }
```

The project supports `php: ^8.3` (D-005), but the workstation runs 8.4.23. Composer resolves
against the PHP it is running on, so the lockfile generated locally selected Symfony 8.1.x,
which requires `php >=8.4.1`. Everything worked locally and the committed lockfile was
simply **not installable on the minimum supported version**. The first real CI run on PHP
8.3.33 caught it: `Your lock file does not contain a compatible set of packages.`

`config.platform.php` fixes the resolution baseline at the supported floor, so a lockfile
produced on a newer runtime cannot silently exclude the version the project claims to
support. Laravel 13 accepts `symfony/* ^7.4.0 || ^8.0.0`, so the correct set exists — the
resolver just had no reason to prefer it.

**The value is a resolution baseline, not a claim about the local runtime.** Development
continues on PHP 8.4; only dependency selection is constrained. Raising the project floor to
8.4 would have been the wrong fix: no required dependency needs it, and it would have
narrowed supported deployments to satisfy an artefact of where the lock happened to be
generated.

Consequences to keep in mind:

- Running `composer update` on any machine now produces the same 8.3-compatible set.
- A package that genuinely requires a newer PHP can no longer be installed by accident; it
  will fail resolution, which is the intended signal.
- If the project floor is ever raised, this value moves with it — the two must agree.

CI keeps `php-version: "8.3"`. Testing the minimum is what surfaced this, and changing CI to
8.4 would have hidden the defect rather than fixed it.

---

## 2026-08-09 — M1.0A Identity & Access architecture lock

Resolves the blockers raised by M1.0 planning. Documentation only — no schema,
no code, no seed. Each decision is recorded before implementation precisely because
getting any of them wrong later would propagate into every business Policy.

### D-026 — One active Organization per deployment

An Organization represents the legal-office group this installation manages. V1 runs
exactly one active Organization.

```text
IS         the parent of every Office
IS         extensible — the table stays plural
IS NOT     a SaaS tenant
IS NOT     selectable by ordinary users
```

No tenancy package, no tenant middleware, no organization selector, no global tenant
scope. The application offers no routine way to create a second Organization; the first
is created once by bootstrap (D-034).

This closes a real gap: the Organization existed only as a schema block in
`03_DATABASE_ERD.md` and was never defined as a product concept anywhere.

### D-027 — Office parentage and one primary Office per user

Each Office belongs to exactly one Organization; `organization_id` is required.

Each operational user has **one primary Office**. `users.office_id` is required
(non-null) for operational users. There is deliberately **no `user_offices`
many-to-many table** in M1 — cross-office access is expressed through permissions and
Data Scope, not through multiple memberships. One membership keeps the `OFFICE` scope
answerable with a single comparison; a many-to-many would make "the user's office"
ambiguous at exactly the point authorization needs it.

The architecture stays *multi-office ready* without becoming multi-tenant.

`10_M0_FOUNDATION.md` section 44 said `office_id` could be "nullable initially if
needed" and M0 omitted it rather than create a foreign key pointing at nothing. That
was correct then. The `users` table currently holds no persistent user, so M1 can
establish the relationship directly without a nullable interim phase.

### D-028 — Multiple role grants union their scopes

When several roles grant the same permission at different scopes, the effective scopes
are the **set union**, never collapsed to a single "widest" value.

```text
role A   permission X -> OWN
role B   permission X -> ASSIGNED
result   { OWN, ASSIGNED }
```

`OWN` and `ASSIGNED` are *resource relationships*, not rungs on a ladder. Treating the
five scopes as a linear hierarchy would silently discard access the administrator
actually granted — a record the user owns but is not assigned to, or the reverse.

### D-029 — User overrides are the single per-user exception mechanism

Roles remain the normal mechanism. `user_permission_overrides` is the exception, and
there must be **at most one active override per `user_id` + `permission_id`**.

```text
1  find a non-expired override for the permission
2  effect = DENY   -> denied, regardless of any role grant
3  effect = ALLOW  -> replaces the role-derived result; the override's scope
                      becomes authoritative, so it can widen OR narrow access
4  no active override -> role grants, scopes unioned per D-028
5  expires_at <= now  -> ignored
```

Expiry is evaluated at **check time**. A cleanup job may later purge or archive expired
rows, but authorization correctness must never depend on that job having run.

**Spatie direct user-permission assignment must not be exposed** in any management UI
or API. The package keeps `Role`, `Permission`, and `Role → Permission`; its
`model_has_permissions` table stays as package infrastructure and is neither dropped nor
customized. Two competing per-user grant mechanisms would make precedence ambiguous, and
ambiguity in an authorization path is a defect, not a detail.

### D-030 — System Settings and Security Settings are distinct capabilities

```text
settings.view / settings.manage                    general system configuration
security.settings.view / security.settings.manage  authentication and security
```

They are **not aliases**. The permission matrix carried a "System Settings" module row
with no matching codes, while `security.settings.*` existed with no matching row — an
implementer would eventually have collapsed the two. Granting `settings.manage` confers
no `security.*` capability.

Also locked: `organizations.view`, `organizations.update`, `offices.view`,
`offices.create`, `offices.update`, `offices.disable`. No `organizations.create`, and no
hard-delete permission for either — retirement uses `is_active`, per section 22 of
`07_SECURITY_RULES.md`.

### D-031 — `users.email_verified_at` is retained

Kept, nullable, as framework-compatible account-security infrastructure. Its existence
does **not** oblige M1 to implement email verification. The column was in the schema but
missing from the ERD field list; the divergence is resolved by documenting the column
rather than dropping it.

### D-032 — SUPER_ADMIN has no authorization bypass — resolves O-020

**Model B** of the three evaluated in M1.0. `SUPER_ADMIN` receives a broad explicit
permission set and **no** `Gate::before` bypass.

Holding the role must never automatically override record state, FINALIZED / LOCKED
rules, legal approval requirements, sensitive-data handling, Data Scope, business rules,
or the append-only audit restriction.

Rationale: the matrix grants SUPER_ADMIN "F" on every module, which an explicit
permission set satisfies exactly, and `02_MENU_AND_PERMISSIONS.md` section 4 already
says the role *"should not be used as the normal day-to-day legal working account"* —
it is not meant to exercise legal authority at all. A `Gate::before` bypass would grant
precisely that, and would do so invisibly.

The cost is a deliberate permission-sync step whenever the registry grows. That cost is
the control.

### D-033 — Audit storage stays out of M1

No `audit_logs` table in M1. `03_DATABASE_ERD.md` section 32 places audit in migration
batch 7, and that is the only explicit ordering statement in the canonical documents.

M1 identity and security actions may use structured application logging where it already
exists. **No parallel M1 audit table** may be created — a second audit store would
fragment the append-only guarantee that section 18 depends on.

`audit.view` and `audit.export` remain reserved registry capabilities even before the
module exists.

### D-034 — Deployment bootstrap is an interactive command

A fresh deployment must never depend on a seeded admin address, a default password, a
committed credential, or manual SQL.

Once the permission registry and default roles exist, a one-time interactive Artisan
command creates:

```text
Organization -> first Office -> first administrator User -> SUPER_ADMIN role
             -> explicit permission set
```

Requirements for that implementation: hidden password input, no default password, no
secret printed or logged, idempotent or refusing an unsafe second run, a documented
local/test automation path, and no business data.

Not implemented here.

### D-035 — The canonical permission list is first-party PHP, not data

`App\Domains\Authorization\PermissionRegistry` is the single source of truth for
permission names. It held **171** entries when this decision was recorded, transcribed
from `02_MENU_AND_PERMISSIONS.md` sections 7–21, grouped by source section, exposed flat
through `all()` — de-duplicated and sorted. **173 since M3.4** *(D-098)*; the exact total
is pinned in one place, `PermissionRegistryTest`, so a legitimate addition is a
one-line change rather than a six-file one.

Not a seeder, not a config file, not a database table. A seeder runs once and leaves no
authority behind; a config file invites per-environment drift, and permission names
diverging between environments is an authorization bug that only appears in production.
Code can be asserted against in CI, and it is: the count, the ordering, the absence of
duplicates, and the absence of forbidden names are all tested.

The registry performs **no database access** — enforced by a test that fails if a query
is issued. It is readable before the container is booted and cannot become a runtime
dependency of the authorization path.

Registered now, though most of the modules do not exist. A permission name creates no
route, controller, policy, table, menu entry, or grant — it is inert until something
checks it. Registering the full surface at once means role configuration can be designed
against the finished capability set rather than a moving target, which is what D-032
requires for SUPER_ADMIN's explicit permission set.

Three exclusions are deliberate and tested:

- **`audit.update` and `audit.delete`** — section 21 lists them under "Do not create".
  Audit records are append-only (`CLAUDE.md` section 31). A registered name would let a
  role be configured to imply a capability that must never exist.
- **`party.identity.nik.view_full`, `documents.view_sensitive`,
  `documents.download_sensitive`** — superseded aliases (D-001). Registering an old name
  would let a role be granted a permission nothing checks, which reads as access granted
  and behaves as access denied.
- **`organizations.create`, `organizations.delete`, `offices.delete`** — the single
  Organization is a deployment concern (D-026, D-034), and Offices retire through
  `is_active` because users reference them (D-027).

The transcription was verified mechanically rather than by reading: every permission-like
token inside the fenced blocks of sections 7–21 was extracted from the document and
diffed against the registry in both directions. 171 = 171, zero in either difference.

### D-036 — Synchronization is explicit, additive, and never prunes

`php artisan permissions:sync` reconciles the registry into the `permissions` table. It
is run deliberately as a deployment step — **never on boot, never during a request**.
A test asserts that serving an HTTP request creates no permission rows.

The command creates what the registry declares and is missing, inside one transaction,
and clears the Spatie cache on both sides of the write. A partially applied permission
set is worse than none, because roles would then be configured against a surface that
only partly exists.

Rows present in the database but absent from the registry are **reported and preserved,
never deleted**. The command cannot distinguish an obsolete leftover from something an
operator added deliberately, and a role may already depend on it — deleting one silently
strips capability from every holder. Removal stays a human decision with the name in
front of them.

It grants nothing. No role, user, Organization, Office, or assignment is created, and
existing assignments are untouched. Tested for each of those.

Guard is `web`, resolved from `auth.defaults.guard`, which is the only guard configured.

Verified against PostgreSQL, not only the SQLite test suite: first run created 171, the
second created 0 with no duplicates, and an unmanaged probe row survived a sync and was
reported by name before being removed.

### D-037 — `offices.code` uniqueness will be `UNIQUE (organization_id, code)`

Direction recorded for O-023. A code is a short handle that is only meaningful inside its
Organization, so uniqueness is composite rather than global.

**Not implemented here.** M1.2 adds no migration. The constraint belongs with the Office
management submilestone that also needs the matching Form Request rule, so the database
and the validation layer land together rather than disagreeing in between. It stays cheap
to add while `offices` holds no rows.

### D-038 — Authorization metadata tables are first-party ULID over package bigint

`role_permission_scopes` and `user_permission_overrides` are ours, so their primary keys
are ULIDs (`CLAUDE.md` section 11). Their references to `roles` and `permissions` stay
`unsignedBigInteger`, matching the package's native `$table->id()`. Converting the
package's keys would mean editing vendor migrations, which D-023 already ruled out; a
mixed-key table is the honest consequence of owning one side of the relationship and not
the other.

```text
role_permission_scopes      id ULID, role_id bigint, permission_id bigint,
                            scope varchar(20), timestamps
                            UNIQUE (role_id, permission_id)
                            role_id, permission_id -> CASCADE

user_permission_overrides   id ULID, user_id ULID, permission_id bigint,
                            effect varchar(10), scope varchar(20) NULL,
                            expires_at NULL, created_by ULID, created_at
                            UNIQUE (user_id, permission_id)
                            user_id, permission_id -> CASCADE
                            created_by -> RESTRICT
```

**CASCADE here, RESTRICT in M1.1.** These rows are derived authorization metadata, not
legal records: a scope row describing a deleted role describes nothing, and an orphan row
in an authorization table is worse than no row. `created_by` is the exception, because it
points at the override's *author* rather than its subject — provenance should not vanish
quietly. The registry defines no `users.delete` capability at all, so that restriction
mostly states the position at the database level.

**No `updated_at` on overrides**, following the `03_DATABASE_ERD.md` section 5 field
list. See O-024 for what that costs.

`scope` is nullable because DENY needs no scope to deny. Both columns are `VARCHAR`
carrying stable machine codes backed by PHP enums rather than PostgreSQL native `ENUM`,
per `CLAUDE.md` section 13.

Only the unique indexes are declared. They already cover every query the resolver makes,
and an index for a query nobody has written yet is a guess.

### D-039 — Authorization metadata that cannot be trusted grants nothing

Every branch of `EffectiveAccessResolver` that cannot produce a confident grant produces
a denial. Explicitly:

```text
name not in PermissionRegistry            denied — the registry is the authority,
                                          not the table, which keeps stale rows (D-036)
canonical name with no database row       denied — the sync has not been run; the
                                          resolver does not create it mid-check
role holds permission, no scope row       denied for that grant
stored scope is not a canonical value     that grant contributes nothing
ALLOW override with scope NULL            denied
ALLOW override with unrecognized scope    denied
override with unrecognized effect         denied, and does *not* fall through to roles
```

The load-bearing one is the third. Data Scope is required metadata, so reading its
absence as `ALL` would turn an administrator forgetting a field into a privilege
escalation — silently, and in the direction that hurts.

The last one matters for the same reason: a row that exists and cannot be understood must
not quietly become "no override", because that would let a corrupt DENY behave as an
absent DENY.

An authorization check never writes. A missing permission row is an operator's unrun
sync, not something to paper over inside a request.

### D-040 — One resolver, capability metadata only

`App\Domains\Authorization\EffectiveAccessResolver` is the single answer to "which
permission does this user hold, and at which Data Scopes"
(`07_SECURITY_RULES.md` section 10). Future Policies consume it; controllers never work
out Data Scope themselves, because divergent copies of an authorization rule are how
holes appear quietly.

It deliberately does **not** answer whether a user may touch a particular record. That
needs ownership fields, assignment relationships, record state, and legal workflow rules
— none of which exist yet. `OWN` and `ASSIGNED` are returned as metadata precisely
because their meaning differs per resource: no generic `created_by` or `pic_user_id`
convention is canonical, and inventing one here would bake a guess into every domain at
once. `OFFICE` is likewise returned without consulting the user's office, since no record
type exists yet to compare against.

`ALL` is a Data Scope and nothing more. It lifts the record restriction for one
permission and confers nothing else — not record state, finalization locks,
sensitive-data access, legal workflow, or any other permission.

Eloquent models exist for both tables under `app/Models` alongside `User`, `Office`, and
`Organization`; the enums, value object, and resolver live under
`app/Domains/Authorization`. That split follows `10_M0_FOUNDATION.md` section 9 — the
domain folders hold our business logic, and the framework's own structure is left where
Laravel puts it.

Both models are **fully guarded**: every column is an authorization decision, so no mass
assignment path exists for request input to reach. M1.3 exposes no API, Form Request,
Policy, or UI for either table.

### D-041 — Spatie direct-user permissions are outside first-party access

D-029 kept them out of any management UI or API. M1.3 adds the enforcement: the resolver
reads `model_has_roles` and `role_has_permissions` and never `model_has_permissions`.

It therefore does not use `$user->can()` or `getAllPermissions()`. Both fold direct
grants in with role grants, and neither carries Data Scope — the answer they give is the
wrong shape as well as the wrong set. A regression test attaches a permission directly
through the package, confirms the package itself honours it, and confirms the first-party
resolver still denies.

Roles are also filtered by the configured guard, so a role from another guard cannot leak
a grant into the `web` one.

### D-042 — TEAM is representable but not yet enforceable

`TEAM` stays in `DataScope` so the vocabulary is stable, and the resolver returns it
unchanged when a scope row carries it. It is never silently converted to `OFFICE`.

No Team entity, table, membership, or inferred relationship exists, and M1.3 created
none. `02_MENU_AND_PERMISSIONS.md` section 22 keeps it **not assignable, not seeded, and
rejected by validation** — so whichever submilestone adds role management must reject it
in its Form Request, and any Policy that meets `TEAM` in an effective scope set must fail
closed rather than approximate it. Record-level TEAM evaluation is unavailable until Team
semantics are specified.

### D-043 — Effective access is not cached in M1.3

The resolver reads the database on every check, going around Spatie's cached permission
collection so an authorization change is visible on the next request.

No custom cache, no Redis key for resolution results. Role management and override
management do not exist yet, so an invalidation rule written now would be one more
security surface with nothing to validate it against — and a stale authorization cache
fails in the direction that grants access. Spatie's own supported permission cache is
untouched. Revisit only with a measured problem.

### D-044 — Deployment-global records require the `ALL` Data Scope

A Role definition belongs to nobody. It is not owned, not assigned, not held by
an office, not part of a team. `ALL` is therefore the only Data Scope that can describe
reaching one — the other four predicates have no field to match against.

So all five role-management abilities require the canonical `roles.*` permission **and**
`ALL` in the effective scope set:

```text
roles.view + ALL              allowed
roles.view + {OFFICE, ALL}    allowed — ALL is present
roles.view + OFFICE           denied
roles.view + OWN              denied
roles.view + ASSIGNED         denied
roles.view + TEAM             denied
active ALLOW override + ALL       allowed
active ALLOW override + OFFICE    denied — the override replaces the role result
active DENY override              denied
```

**This is not a ranking, and D-028 is untouched.** Nothing says `ALL` outranks `OFFICE`;
it says this *kind of record* needs the unrestricted predicate. An office-scoped grant
stays fully valid for office-scoped records. The check is presence — `hasScope(ALL)` —
not comparison, and `DataScope` still exposes no `widest`, `max`, `rank`, or
`higherThan`, asserted by test.

Implemented as `EffectiveAccessResolver::allowsGlobally()`, one method reusable by the
future Organization, Office, Settings, and Master Data policies, all of which manage
deployment-global records. It is not a general authorization framework and should not
grow into one.

`RolePolicy` ability names (`viewAny`, `view`, `create`, `update`, `delete`) deliberately
are not permission names — see O-027 for why that matters.

M1.4 has no scope-assignment path at all, so the `TEAM` validation restriction recorded
in D-042 has nothing to attach to here. It carries forward to the milestone that assigns
scopes (M1.6).

### D-045 — The package's Role record is the role record

`roles` stays exactly as spatie/laravel-permission defines it: an auto-incrementing
integer key, `name`, `guard_name`, timestamps. M1.4 added no table, no column, and no
migration.

No `code`, `slug`, or `display_name` was invented — no canonical document defines one, and
a second name field immediately raises which one is authoritative. No `organization_id` or
`office_id` either: one deployment runs one Organization (D-026), and role definitions are
deployment-global rather than per-office copies.

The integer key is returned to the frontend as-is. `06_API_CONVENTIONS.md` section 14 asks
for ULIDs on *domain resources*; `roles` is a third-party table already exempted by D-023,
and converting its key would mean editing vendor migrations. The client treats the value as
an opaque handle and derives nothing from it.

**A role name is not an authorization primitive.** Nothing anywhere compares one — a test
greps the entire authorization path for `hasRole`, `SUPER_ADMIN`, `Gate::before`, and
`Gate::after` and requires all four absent. This is what makes renaming safe.

Validation is technical only: required, string, at most 255 characters, unique within the
guard. No casing or shape is imposed, because an office may reasonably create
`Notaris Pengganti`, and the submitted name is stored exactly as given rather than
normalized — an interface that silently rewrites what someone typed is lying about what it
saved.

The nine names in `02_MENU_AND_PERMISSIONS.md` section 4 —

```text
SUPER_ADMIN  PRINCIPAL  OFFICE_MANAGER  NOTARY_STAFF  PPAT_STAFF
FRONT_OFFICE  FINANCE  ARCHIVE_STAFF  AUDITOR
```

(`ARCHIVE_STAFF`, not `ARCHIVE`) — are a **default configuration**, not authorization
logic and not protected records. They are not seeded by M1.4, not hardcoded in the
frontend, and not enforced by any recurring synchronization command. Provisioning them is
the deployment bootstrap's job (D-034), and any of them may be renamed or deleted like any
other role.

### D-046 — First-party authorization is defined against a fixed guard

A permission's identity is `(name, guard_name)`, so the registry, the sync command, the
resolver, and role creation must all name the same guard or nothing authorizes.
`PermissionRegistry::GUARD` is that single definition, and it is the literal `web`.

It is deliberately **not** `config('auth.defaults.guard')`. That value is mutable at
runtime: on a successful check `Illuminate\Auth\Middleware\Authenticate` calls
`Auth::shouldUse($guard)`, which rewrites the default guard for the remainder of the
request. Every authenticated API request passes through `auth:sanctum`, so any code
reading that config inside a controller, policy, action, or Form Request sees `sanctum`.

Found while building M1.4, and it was not theoretical. The M1.3 resolver read the config
and consequently looked for permissions on the `sanctum` guard on every authenticated
request, found none, and denied everything — while passing all 48 of its own tests,
because none of them issued an HTTP request through the auth middleware. The same trap
would have made role creation write roles onto a guard nothing could ever grant, and
uniqueness validation compare against a guard holding no roles at all.

`web` is the session guard the SPA authenticates against. Sanctum's stateful mode
authenticates that same session — it is a wrapper over this guard, not a second permission
namespace. A test asserts the named guard exists and uses the `session` driver, so
renaming it fails loudly instead of letting authorization go quiet, and a regression test
resolves access after deliberately calling `Auth::shouldUse('sanctum')`.

### D-047 — A role that somebody holds is not deleted

`model_has_roles.role_id` cascades, so deleting a held role would strip capability from
everyone holding it, and the first sign would be a user unable to do their job. The delete
endpoint therefore refuses with **409 Conflict** — the request is well formed and the
caller is authorized; the system's state is what blocks it.

Detaching users automatically is deliberately not offered. That is a user-administration
act and belongs to whoever manages those users, made explicitly. The check reads the pivot
table rather than a `users` relation, since any model type may hold a role.

Deleting a role nobody holds does remove its own permission grants and Data Scope rows
through the existing foreign keys — those describe the role, and with the role gone they
describe nothing (D-038). Canonical permission rows are never touched.

Known limit, recorded rather than papered over: the check and the delete are not proof
against a role being assigned in the instant between them. Closing that would require
restricting the package's own pivot, which M1.4 must not modify, and no assignment path
exists yet in any case — it arrives with User Management.

Creating a role creates exactly one `roles` row with zero permissions, zero scope rows, and
zero members. Renaming one changes only the name. Both are asserted against all three
assignment tables, because these are the invariants that make role administration safe to
hand to an office manager.

### D-048 — A canonical permission code is not an authorization surface

Resolves O-027.

`EffectiveAccessResolver` is the canonical first-party permission resolver. It is the
only thing that answers "may this user do this", because it is the only thing that
consults all five inputs the authorization model depends on: canonical registry
membership, role-derived grants, Data Scope, `user_permission_overrides` with check-time
expiry, and the exclusion of direct user-permission grants.

**Spatie's generic permission Gate integration is not a first-party authorization
surface, and is now disabled.** `config('permission.register_permission_check_method')`
is `false` — the package's own documented switch for "if you want to implement custom
logic for checking permissions", which is exactly this situation. Left enabled, it
registers a `Gate::before` answering any ability whose name matches a held permission,
straight from package state:

```text
$user->can('roles.view')          -> true from a direct grant, no scope checked,
                                     no override consulted, no registry check
resolver->allowsGlobally(...)     -> false
```

Two answers to the same question, and the more idiomatic one was wrong. Nothing had
exploited it — `RolePolicy`'s abilities are named `viewAny`, `view`, `create`, `update`,
`delete` precisely so the callback could not answer them — but the next endpoint written
with `middleware('can:users.create')` would have bypassed the entire model in one line.

Therefore:

```text
FORBIDDEN as first-party authorization
    User::can('resource.action')          Gate::allows('resource.action')
    User::cannot('resource.action')       Gate::denies('resource.action')
    hasPermissionTo() / hasAnyPermission() / hasAllPermissions()
    getAllPermissions() as a backend authority
    any role-name comparison

REQUIRED
    Controller  $this->authorize('<ability>', <resource>)
    Policy      delegates to EffectiveAccessResolver
    Policy      enforces the scope the resource context requires
```

Laravel's Gate and Policy infrastructure stay in full use. Only the *ability name* changes
meaning: `viewAny` is a policy ability, `roles.view` is a permission code, and the two must
never be the same string.

Data Scope remains mandatory where the resource context requires it — deployment-global
records need `ALL` (D-044). Direct Spatie user-permission grants remain excluded (D-029,
D-041); `model_has_permissions` keeps its schema and the package keeps its API, because
`givePermissionTo()` and friends are storage operations, not authorization decisions.

Package storage is untouched: roles, permissions, `role_has_permissions`,
`model_has_roles`, `HasRoles`, and every relationship behave exactly as before. Nothing
else in the package depends on the disabled callback — `registerPermissions()` has one
caller, guarded by that flag. **No vendor file was modified.**

Enforced rather than merely documented: a test asserts zero Gate before/after callbacks
exist, another asserts a canonical name given to the Gate is refused even for a user who
genuinely holds it at `ALL`, and a source scan of `app/` fails the suite if any file
authorizes a `resource.action` string through those calls.

**O-026 is a different problem and stays open.** `/api/v1/me` reporting permissions via
`getAllPermissions()` is a *presentation* defect — it shapes menu visibility. O-027 was a
*backend authorization* defect. No backend security decision reads the `/me` payload, and
M1.4A did not change it; M1.7 owns that.

### D-049 — A User is an Office-owned resource, so all five scopes mean something

Unlike a Role definition, a user record has an owner field: `users.office_id` is
required (D-027). `OFFICE` is therefore a working predicate here, and user management
does **not** require `ALL` the way role management does (D-044).

```text
users.view      ALL       every user in the deployment
                OFFICE    target.office_id == actor.office_id
                OWN       target.id == actor.id
                ASSIGNED  nothing — a user is not assigned to anybody
                TEAM      nothing — no Team entity exists (D-042)

users.create    ALL       any active Office
                OFFICE    the actor's own Office only
users.update    ALL       any user, and may move them to any active Office
                OFFICE    same-Office targets only, and the Office may not change
users.disable   ALL / OFFICE as above
```

**`OWN` is not an administrative predicate.** It grants visibility of oneself and nothing
more: `users.update` at `OWN` would otherwise let anyone edit their own administrative
record, including moving themselves to another Office. Editing your own details is
self-service with its own capability (M1.8), not administration.

Still union, never ranking (D-028). `{OWN, OFFICE}` matches the actor plus their
colleagues. `{OFFICE, ALL}` matches everyone because `ALL` independently matches
everyone, not because it outranks `OFFICE`.

Implemented in `App\Domains\Identity\UserVisibility`, which turns scopes into a SQL
constraint. **The record check runs that same constraint against a single key** rather
than reimplementing the rule, so the list and the detail endpoint cannot drift apart —
the failure mode where a record is hidden from a listing yet still fetchable by id.
Filtering happens in the query, so an office-scoped caller's SQL never selects another
Office's rows and the pagination total leaks no count.

A filter narrows what is already visible; it never widens it. Passing another Office's id
to `?office_id=` returns nothing rather than bypassing the predicate.

An Office must be **active** to receive a user. Retiring an Office is not a reason to
delete or rewrite the people already in it, but it is a reason not to add more.

### D-050 — Users are retired, never deleted

The permission registry defines no `users.delete`, so M1.5 exposes no deletion: no
`DELETE /api/v1/users/{user}`, no restore, no hard delete. Accounts are turned off with
`users.disable`.

`deleted_at` exists anyway, and `User` uses `SoftDeletes`, because the canonical ERD
carries the column and because a legal office cannot afford the alternative: a person's
account is referenced by the Minuta Akta they prepared and the audit trail they appear
in, so the record must survive them leaving. The column is foundation, not a feature —
nothing in the product calls `delete()` on a user today.

This also lowers the practical risk in **O-025**: Spatie's morph pivots have no foreign
key on `model_id`, so a hard delete would orphan a user's role and permission rows. With
no deletion path and soft deletes in place, the product cannot reach that state. O-025
stays open because the underlying package behaviour is unchanged, and whoever eventually
builds a purge path must still detach package assignments explicitly.

### D-051 — Initial password only, and no password lifecycle

An account cannot exist without a password, so `POST /api/v1/users` accepts one, hashed
by the model's `hashed` cast and never returned, echoed, or logged. Validation uses
Laravel's own `Password::default()` — no password policy is canonicalized anywhere in the
specification, and inventing complexity rules, expiry, or history here would be inventing
account security.

`PATCH` does not accept a password at all. Changing somebody else's credentials is a
security operation, not an edit to an administrative form.

Nothing else about password lifecycle exists: no temporary-password flag, no
`must_change_password` column, no expiry, no history, no email delivery, no invitation
flow.

**`users.reset_password` stays in the registry, unimplemented.** The capability is
canonical; the flow is not — no document defines how a reset is delivered, whether the
administrator sees the new secret, or how the user is notified. Implementing it would
mean designing an account-security flow inside a user-management milestone. Deferred to
M1.9, and the permission is neither removed nor renamed in the meantime.

Role assignment is likewise absent (M1.6): a new account holds zero roles, zero direct
permissions, and zero overrides, and an update touches none of the three. Granting
capability from a screen that never asked about capability is how authorization drifts
away from anybody's intent.

### D-052 — Activation is a deliberate act with its own endpoint

`is_active` is not writable through `PATCH /api/v1/users/{user}`. It changes only through
`POST .../disable` and `POST .../enable`, both requiring `users.disable` and the same
Office predicate as any other administration.

Splitting it out means turning off somebody's access can never happen as a side effect of
editing their phone number, and it makes the audit question — who disabled this account —
answerable against one operation rather than a diff. Both directions are idempotent.

**Disabling your own account is refused with 409**, at every scope including `ALL`. The
actor is authorized, so 403 would be a lie; what blocks it is that the operation ends the
requester's own access and, if they are the only active administrator, leaves nobody able
to undo it. Reactivation is another authorized user's job. This is a technical safety
rule — no role name is consulted, and it is not a privileged-account exception.

Existing sessions are deliberately not revoked. `LoginRequest` already folds `is_active`
into the credential lookup, so a disabled account cannot authenticate again; terminating
sessions already open is session management, which M1.9 owns and which needs its own
design.

### D-053 — A permission grant and its Data Scope are one operation

`PUT /api/v1/roles/{role}/permissions` replaces a role's whole configuration.
`role_has_permissions` and `role_permission_scopes` are written, re-scoped, and removed
**together, in one transaction**. Removing a grant removes its scope row; adding one adds
both.

The resolver treats a grant without scope metadata as no grant at all (D-039), so a
half-applied save would produce a role that looks configured in every listing and does
nothing in practice — the worst possible failure for an authorization screen. The M1.6
write path cannot create one, and a test asserts grants and scope rows stay equal in
number across additions, removals, and re-scopings.

Complete replacement rather than deltas: the matrix shows the entire configuration, so
saving it means "this is the configuration". Omitted permissions are revoked.

Rejected outright, each tested: a permission the registry does not declare, a stale row
`permissions:sync` preserved (D-036), a permission from another guard, the same code
listed twice, and any scope the permission does not allow. Duplicates are refused rather
than resolved last-wins — guessing which the administrator meant is how a saved
configuration stops matching the screen that produced it.

Spatie's cache is cleared after the commit, so a saved grant takes effect on the next
check rather than whenever the cache happened to expire.

**`TEAM` is never assignable.** It stays a canonical `DataScope` because the vocabulary is
fixed (D-042), but no Team entity exists, so a grant carrying it could never be evaluated
against a record. `PermissionScopeRules` excludes it everywhere, the catalogue never
offers it, and the write endpoint rejects it. A legacy `TEAM` row is reported as-is,
never reinterpreted as `OFFICE` and never silently rewritten.

### D-054 — Permission administration is global and requires ALL

`permissions.view` and `permissions.assign` both require the `ALL` Data Scope, exactly as
role management does (D-044). The permission catalogue, role grants, and role membership
are deployment-global metadata owned by nobody, so `OFFICE`, `OWN`, `ASSIGNED`, and `TEAM`
have no record to match against.

Presence, not precedence: `{OFFICE, ALL}` passes because `ALL` is in the set. No ranking
was introduced, and `PermissionScopeRules` is asserted to expose no `widest`, `max`,
`rank`, or comparison method.

The scope rules themselves live in one place so the interface and the backend cannot
disagree — the catalogue serves `allowed_scopes` from the same rules the write endpoint
enforces. Only the rules the specification has settled are encoded:

```text
roles.* / permissions.*                                    ALL only
users.view                                                 OWN, OFFICE, ALL
users.create / update / disable / reset_password           OFFICE, ALL
everything else                                            OWN, ASSIGNED, OFFICE, ALL
```

The last line is deliberate. Narrowing it would mean deciding what
`notary.deeds.approve` at `OWN` means before the Notary domain has been designed, and a
domain's Policy is what should decide that.

### D-055 — Role assignment is permission administration, not user administration

`GET` and `PUT /api/v1/users/{user}/roles` are guarded by `permissions.view` and
`permissions.assign`, **never by `users.update`**.

Granting somebody a role changes what they can do. Someone trusted to correct a
colleague's phone number is not thereby trusted to make them an administrator, and putting
both behind one capability would make that distinction impossible to express. A test
gives a user every `users.*` permission at `ALL` and confirms the role endpoints still
refuse them.

Membership is a complete replacement of `model_has_roles` and touches nothing else — role
permissions, scope metadata, direct package permissions, overrides, and every profile
field are asserted unchanged across a save.

Direct Spatie user-permission assignment remains unavailable in every direction (D-029,
D-041): no endpoint offers it, the matrix does not mutate `model_has_permissions`, and
bootstrap gives its administrator capability solely through a role.

### D-056 — At least one active user must retain permissions.assign at ALL

M1.6 makes the authorization configuration editable, which means it can be edited into a
state nobody can edit back. Remove `permissions.assign` from the only role that grants it,
narrow its scope, or unassign the last administrator, and the deployment keeps running
while becoming permanently unconfigurable, with no in-product recovery.

So every mutation of role permissions, role scopes, role membership, or account
activation runs inside a transaction that ends by asking whether an **active,
non-soft-deleted** user still resolves `permissions.assign` with `ALL`. If not, the
transaction rolls back and the caller receives **409 Conflict**.

The precise invariant is that **this operation must not be what causes the loss** — not
that an administrator must exist unconditionally. A deployment that has none yet (before
bootstrap, or in a fixture that never needed one) is not made worse by an unrelated
change, and refusing every such change would make an unprovisioned deployment
inexplicably read-only. Since no guarded operation can take the count from one to zero, it
never reaches zero this way.

Capability-based, never name-based: the check never looks for `SUPER_ADMIN`, a custom role
satisfies it identically, and holding the famous name without the capability satisfies it
not at all (D-032). Losing your own access is allowed as long as somebody else keeps
theirs. Disabled and soft-deleted users do not count — an account that cannot sign in
cannot administer anything, and treating it as a safety net would be pretending.

Evaluation goes through the real resolver, so overrides, expiry, and missing scope
metadata all come out right. A SQL shortlist narrows the candidates first; it is a
shortlist, not a second implementation of the rule.

This also hardens M1.5's activation path. M1.5 only had to stop you disabling yourself;
now disabling the last remaining administrator is the same lockout by another route, so
it is refused too.

### D-057 — Only bootstrap SUPER_ADMIN receives permissions, and every one explicitly

The canonical documents describe `SUPER_ADMIN` as holding everything, and D-032 forbids a
`Gate::before` bypass. Both are satisfied the only way they can be: bootstrap grants the
role **every canonical permission from `PermissionRegistry::all()`, each at `ALL`**, as
ordinary rows. No wildcard, no `*`, no Gate shortcut, no role-name check. Its power is a
list of grants like any other role's, and revoking one revokes it. The count is never
hardcoded, so a registry change carries through.

**The other eight roles are created empty.** The high-level matrix in
`02_MENU_AND_PERMISSIONS.md` section 5 grades modules `F` / `V` / `A` / `—`, which cannot
be translated into 171 permission codes and their Data Scopes without inventing the
mapping — and invented authorization is worse than absent authorization, because it looks
deliberate. They are shells to configure through the Permission Matrix.

### D-058 — Bootstrap is one-time, interactive, and never re-provisions

`php artisan app:bootstrap` prepares a fresh deployment: one Organization, one Office, the
canonical permissions, the nine default roles, `SUPER_ADMIN`'s grants, and the first
administrator, who receives capability only through that role.

**No default password and no password option.** The secret is typed at a hidden prompt,
hashed, and never printed, logged, stored in plaintext, or accepted on a command line
where shell history would keep it (D-060 in spirit; enforced by test). Validation reuses
the same `Password::default()` rule as user creation (D-051).

Identity provisioning runs in one transaction: a failure creating the administrator leaves
no Organization, Office, or roles behind. Permissions are synchronized *before* it, on
purpose — the sync is idempotent and additive, its rows are exactly what a re-run would
produce, and keeping them costs nothing.

The preflight distinguishes fresh from partially provisioned from already initialized.
Permissions may legitimately already exist, since `permissions:sync` is a normal
deployment step that says nothing about identity. Anything else already present makes the
command **abort before writing** and say what it found: merging into a half-provisioned
deployment cannot be done safely without knowing what is missing and why.

Re-running on an initialized deployment changes nothing and says so. Nothing resynchronizes
default roles, so a role an office deleted stays deleted and a renamed one stays renamed —
tested for both, along with the absence of any scheduled task that could undo them
(D-045).

`SyncCanonicalPermissions` was extracted from the M1.2 command so bootstrap can reuse it
in-process rather than shelling out to another Artisan invocation, which would have run
outside the caller's transaction. `permissions:sync` still behaves exactly as before.

### D-059 — Permission synchronization is a service the command wraps

`SyncCanonicalPermissions` holds the reconciliation; `permissions:sync` is the reporting
layer around it. Extracted at M1.6 so the deployment bootstrap could reuse it **in
process** — shelling out to a second `artisan` invocation would have run outside the
caller's transaction, which is exactly what a bootstrap must not do.

Behaviour is unchanged from D-036: additive, idempotent, never pruning, reporting rows it
does not recognize rather than deleting them.

*(Recorded at M1.7. M1.6's code already cited this number; the decision was implemented
but never written down, and a citation pointing at nothing is worse than no citation.)*

### D-060 — A bootstrap password is never accepted on a command line

`app:bootstrap` takes the administrator password only from a hidden interactive prompt.
There is **no `--password` option, no argument, and no default**, and a test asserts the
command definition exposes neither.

An option would put the secret in shell history, in process listings, and in whatever CI
log captured the invocation — three places nobody remembers to clear. The password is
hashed on the way in and never printed, logged, or stored in plaintext.

*(Recorded at M1.7, for the same reason as D-059.)*

### D-061 — One decision function answers for one permission and for all of them

`EffectiveAccessResolver` exposes `resolve()` for a single permission and `resolveAll()`
for the whole registry, but both load a plain {@see AuthorizationState} and hand it to the
same private `decide()`. Allow/deny, scope union, ordering, and every fail-closed branch
exist **once**.

This is structural, not a convention. A separate projection for the interface would have
been a second implementation of D-028 and D-029, and the first time the two disagreed the
symptom would be a screen offering an action the backend refuses — or worse, hiding one it
allows. A test resolves every canonical permission both ways against a fixture carrying
multi-role unions, an active DENY, an active ALLOW, an expired override, a grant missing
its scope, a corrupt scope value, a stale permission, and a direct package grant, and
requires the two answers to match exactly, including scope order and source.

`resolveAll()` loads its state in **four queries regardless of registry size** — the
permission rows, the active overrides, the user's roles, and the scope rows. A test
asserts resolving 171 permissions costs no more queries than resolving one; anything
proportional would mean the projection re-derives state per permission.

No caching. Role and override administration now exist, so a stale authorization cache
fails in the direction that grants access.

### D-062 — `/api/v1/me` reports effective access — resolves O-026

`permissions` is the list of canonical codes the account **effectively holds**, and
`permission_scopes` maps each to its exact Data Scope set. Both come from
`EffectiveAccessResolver`, the same component every Policy consults.

Until M1.7 the field was Spatie's `getAllPermissions()`. That counted direct
user-permission grants the authorization model excludes (D-029, D-041), carried no Data
Scope, and ignored overrides entirely — so the browser and the backend could disagree
about what somebody could do. It was presentation-only and therefore never a
vulnerability, but it was a defect, and it was O-026.

A permission appears only when granted; denials are absent rather than present and empty.
Excluded exactly as the resolver excludes them: direct package grants, stale codes, grants
missing scope metadata, expired overrides, malformed ALLOW overrides, and canonical names
with no database row. Ordering is canonical for permissions and documentation order for
scopes, so the payload is stable between requests.

`roles` remains, and remains **presentation only**. Nothing may decide visibility from a
role name.

The endpoint stays read-only: it runs no sync, repairs nothing, and cleans no expired
override. A test asserts every statement it issues is a `select`.

### D-063 — Frontend authorization is presentation, and says so

`can()`, `canWithScope()`, `PermissionGuard`, and navigation filtering all read the
effective projection. They exist so the interface offers what the account can actually do
— not to enforce anything. Every route and endpoint is authorized again on the server, and
a browser editing its own state gains nothing.

`canWithScope()` is **exact membership, never comparison**. `{OFFICE}` does not satisfy a
required `ALL`; `{OFFICE, ALL}` does, because `ALL` is present. There is deliberately no
"wide enough" helper and no ordering anywhere in the frontend, mirroring D-028.

**Record-level predicates are not reproduced in React.** An office-scoped administrator
sees an Edit control; whether a *particular* colleague is within their Office is decided by
the Policy when the request arrives. Duplicating that into the browser would be a second
authorization engine with all the drift that implies, and hiding a control the backend
would have allowed is its own kind of bug.

Where a capability splits into read and write — the permission matrix, role membership —
the read-only state is rendered rather than a disabled Save that would only be refused.

### D-064 — A registered permission is not a shipped feature

Bootstrap gives `SUPER_ADMIN` all 171 canonical permissions (D-057), and the registry
deliberately contains permissions for modules that do not exist (D-035). Navigation
therefore requires **two independent conditions**: the destination must be implemented,
and the account must hold the permission.

Without that split, provisioning an administrator would light up Projects, Notary, PPAT,
Billing, and every other future module, linking to routes that 404. `navigation.ts` carries
an explicit `implemented` flag; an entry that is `false` never renders whatever the account
may do, and a test confirms a fully-privileged administrator still sees only Dashboard,
Users, and Roles.

A parent menu renders only when at least one of its children survives filtering
(`02_MENU_AND_PERMISSIONS.md` section 23). An empty Settings menu is worse than no Settings
menu: it advertises something and then does nothing.

Desktop and mobile share one filtered result, so a destination hidden on one is hidden on
the other by construction rather than by discipline.

### D-065 — The current user is refetched after authorization changes

Saving the permission matrix or a user's role membership invalidates `["auth", "me"]`.

Authorization can change under the person doing the changing: the continuity guard permits
an administrator to remove their own access while another remains (D-056), and role edits
routinely affect roles the editor holds. Without a refetch the interface would keep
offering controls the backend has begun refusing, and the only cure would be signing out —
which reads as a broken session rather than a permission change.

Effective permissions stay in the TanStack Query cache and nowhere else: not Redux, not
Zustand, and never `localStorage` or `sessionStorage`. Persisting them would create a copy
that outlives the session that earned it.

### D-066 — Self-service profile needs authentication, not a permission

Every authenticated user reaches `/api/v1/profile`. No canonical permission
guards it, and none was invented: the registry has no `profile.view`, and adding
one so a menu entry could render would put a fake capability in a catalogue whose
whole value is that it describes real ones.

The target is always `$request->user()`. There is no `/profile/{user}`, no id
parameter, and no query string that introduces one — administrative access to
somebody else's record is M1.5's `users.*`, deliberately separate.

Deliberately **not** routed through `UserPolicy`. That policy excludes `OWN` from
administrative update on purpose (D-049), because editing your own
administrative record is self-service rather than administration. Bending it to
fit would weaken the rule it exists to state, so self-service is simply not a
`UserPolicy` question at all.

Editable: `name`, `phone`, `preferred_locale`. Everything else is **rejected
with 422 rather than silently dropped** — `email`, `office_id`, `is_active`,
`password`, `roles`, `permissions`, `email_verified_at`, `last_login_at`,
`deleted_at`. `validated()` would discard them anyway; refusing says so, because
an interface that appears to accept a change it never made is worse than one
that declines.

A profile save touches no pivot. Role memberships, direct permissions, Data
Scope metadata, and overrides are asserted unchanged, and the effective
authorization projection from `/api/v1/me` is asserted byte-identical before and
after each of the three editable fields. Changing your display name must never
change what you can do.

### D-067 — Email and Office are read-only to their owner

Both are displayed on the profile and neither is editable there.

`email` is the authentication identifier, and `email_verified_at` already exists
in the schema. Changing an address needs a verification flow — how the new
address is confirmed, what happens to the session in between, what the old
address is told — and no document specifies one. Inventing it inside a profile
milestone would be designing account security by accident. Deferred to Account
Security review (**O-030**).

`office_id` decides which records a person's Data Scopes reach (D-049). Letting
somebody move themselves between Offices would let them relocate their own
access, which is precisely why M1.5 made it an administrative operation.

Both are rendered as plain text rather than disabled inputs: a disabled field
still reads as "editable, just not right now", and text does not.

### D-068 — Stored locale codes are exactly `id` and `en`

Bare codes, never a regional tag (`id-ID`), never a display name (`Indonesia`),
never a different case. `SupportedLocales` is the backend's boundary and
`src/i18n/routing.ts` is the frontend's; a test asserts the two agree rather than
trusting that they do, because two files naming the same pair is how they start
to disagree.

Indonesian is the default and the fallback.

### D-069 — Preference decides the landing locale; the URL decides everything else

D-020 made the URL the only source of the active locale, and that is unchanged:
`localeDetection` and `localeCookie` stay off, nothing is read from
`localStorage` or `sessionStorage`, and no `accept-language` header is consulted.
This milestone fills the gap D-020 explicitly left for it.

There is exactly **one** moment a stored preference decides a locale: the
redirect immediately after signing in. Until then nobody has identified
themselves, so the URL was all there was to go on; from there, the person's own
choice applies — whichever localized login page they arrived at. `preferred_locale = en`
lands on `/en/dashboard` even from `/id/login`.

After that redirect the URL is authoritative again. Opening `/en/...` with a
stored preference of `id` shows English and is **never rewritten**, and never
quietly updates the preference either. Typing a URL is a navigation, not a
declaration about future sessions.

A stored value the routing configuration does not recognize falls back to `id`
rather than producing a path with no route. **Reading it never repairs it** —
`/me` and login are read paths, and writing to the database as a side effect of a
page load is how a silent "fix" becomes impossible to explain later. Correcting
the row is the user's own explicit choice.

Using the Language Switcher **is** that explicit choice, so it persists the
preference and then navigates once to the same page in the new locale, preserving
pathname and query string. **Persist first, navigate second**: navigating first
would leave the interface speaking English while the stored preference silently
stayed Indonesian if the request failed, and a screen that lies about what was
saved is worse than one that reports the failure. On error nothing moves.

Selecting the locale already displayed is not assumed to be a no-op — somebody
may have typed `/en/...` while their preference is still `id`, and choosing EN
then genuinely records EN.

Signed out, the switcher changes the URL only. There is nowhere to persist a
preference for somebody who has not identified themselves, and inventing a cookie
for it is exactly what D-020 rejected.

One mutation path serves both the header switcher and the profile page, so the
two cannot drift into different persistence behaviour.

### D-070 — One password rule, in one place

User creation, deployment bootstrap, self-service change, and reset completion all
build their password rule from `PasswordRules`. Four independent copies of
`Password::default()` would look identical right up to the day one of them was
tightened and the others were not — at which point the weakest path silently
becomes the real policy, and nobody notices because three of the four look
correct.

The rule is Laravel's own default plus `uncompromised()`, which
`07_SECURITY_RULES.md` section 4 asks for. Deliberately **not** a composition
requirement: "one uppercase, one digit, one symbol" pushes people toward
`Password1!` and predictable substitutions without adding real strength, and it
is not something any canonical document asks for.

The compromised-password check is skipped under `runningUnitTests()`. It calls
the Have I Been Pwned range API, and a network call inside the suite is how a
useful check ends up being disabled entirely. Laravel's verifier already treats
an unreachable API as "not compromised", so an outage cannot lock somebody out of
changing their own password — a safety net, not the policy itself.

### D-071 — An administrator restores access, and never acquires it

This is the constraint the whole administrative surface is shaped around, and
`07_SECURITY_RULES.md` states it directly: an administrator must never choose a
user's password, see a temporary one, receive a reset token, or read it from a
log.

`POST /api/v1/users/{user}/password-reset` therefore sends a link to the
account owner's own mailbox and answers with a message and nothing else. The
token is generated by Laravel's password broker, stored hashed, and exists in
readable form only inside that email. A `password` field submitted alongside the
request is ignored, and a test asserts it is.

Triggering a reset does **not** change the current password. It stays valid until
the link is used, so the action cannot lock somebody out mid-day by accident.

The reason is specific to this domain. Someone who can silently become another
user can sign a deed as them, and in a Notary office that is not a recoverable
mistake. The same logic runs through the rest of M1.9: `security.mfa.manage` can
only *remove* a second factor, never read or set one, and no endpoint anywhere
returns a session identifier, a two-factor secret, or a recovery code for another
account.

Self-service is the mirror of this and needs no permission at all. The `security.*`
codes describe administering *other people's* security; requiring one to change
your own password would mean an account could be forbidden from securing itself.
Authentication plus self-ownership is the whole boundary, exactly as D-066 drew
it for the profile — and enforced the same way, by there being no route that
accepts an id.

Every self-service mutation re-proves the current password. A live session says a
browser is signed in; it does not say the person at the keyboard is the account
owner, and an unattended screen is a live session too.

### D-072 — A credential change ends the other sessions; a reset creates none

Changing a password is usually a response to suspecting somebody else has it.
Leaving their session alive would make the change theatre, so every **other**
session for the account is revoked. The session doing the changing survives —
logging somebody out for securing their own account teaches them not to — and its
identifier is regenerated so the pre-change cookie cannot be replayed.

Completing a **reset** revokes everything, with nothing spared: the person
completing it is not signed in anywhere that has proved anything.

A reset creates **no session**. The user signs in again, which means an account
with two-factor still meets its second factor. Auto-login here would turn a single
emailed link into a complete bypass of MFA — the one thing a second factor exists
to prevent.

A reset is not an account reset either. Roles, permissions, Data Scope metadata,
overrides, Office, profile, locale, and the entire two-factor configuration are
preserved, and tests assert each of them.

Rate limits are named rather than the bare `throttle:6,1`, because the sharing
between buckets is a deliberate decision in one case and a bug in the other.
Laravel's unnamed throttle keys authenticated requests on the user id alone, so
every route carrying it shares one budget by accident — mistyping a password
three times would then block starting a two-factor enrolment. Two buckets, split
on what the endpoint accepts: everything taking `current_password` shares
`security.password` **on purpose**, since an attacker rotating between four
endpoints would otherwise get four times the guesses at it; the two-factor setup
routes submit no password and are limited separately.

### D-073 — The current email address holds until the new one is proven

`email` is the authentication identifier, so changing it is a credential change,
and the flow is built around one failure being unacceptable: a typo must never
cost somebody their account.

The current address therefore **does not change** when a change is requested. It
stays authoritative until the new one demonstrates it can receive mail. Until
then `pending_email` is visible to the account owner — which is also how a request
they did not make becomes visible to them — and a cancel action clears it.

The verification link goes to the **new** address, because the question being
answered is "does this person control that mailbox". The current password is
required at request time, which answers the separate question "is this the account
owner". Only a SHA-256 of the token is stored, so reading the database cannot
complete somebody else's change, and comparison uses `hash_equals`.

Confirmation is **authenticated**. The token alone is not enough: completing the
change needs the token *and* a signed-in session, so a forwarded email cannot move
an account on its own. Every condition is rechecked at that moment rather than
trusted from request time — including whether the address is *still* free, since
otherwise a unique-constraint violation would surface as a 500 where a clear
refusal belongs.

On success the address is replaced, `email_verified_at` is set to that moment, and
other sessions are revoked under D-072.

This resolves **O-030**.

### D-074 — Sessions are enumerable, and a session id is a credential

`SESSION_DRIVER=database`, so sessions can be listed and revoked. That is what
makes "sign out everywhere" and "disabling an account ends its access" real rather
than aspirational.

**A raw session id never leaves the server.** Anyone holding one can forge the
cookie, so the API exposes a SHA-256 digest instead: stable enough to name a row
for revocation, useless for impersonation. Revocation matches on the digest and is
scoped to the user, so a key belonging to somebody else matches nothing and
answers 404 — reporting success for a session that was never revoked would tell
somebody their old laptop is signed out when it is not.

Also never exposed: the session payload, which carries the CSRF token, and the
full user-agent string, which is a fingerprint. The interface shows a coarse
browser-and-platform label, because "was that me?" is what a person is actually
asking.

Where the driver cannot be enumerated the registry returns an empty list rather
than a fabricated one. The test suite runs on the `array` driver by default, and
inventing rows to keep it happy would be inventing evidence; the session tests opt
into the database driver explicitly instead.

**Disabling an account now ends its open sessions.** M1.5 deliberately left this
to M1.9 and in doing so left a real hole: `LoginRequest` refused a disabled
account at authentication, so no *new* session could start, but every session
already open kept working until it expired. Disabling somebody during an incident
has to take effect immediately, not whenever their cookie happens to lapse.
Revocation runs after the D-056 continuity invariant has held, so a refused
disable signs nobody out.

### D-075 — Two-factor is verified before a session exists, not after

An account with two-factor enabled is **never logged in by its password alone**.
`POST /login` validates the credentials through the guard's provider — not
`attempt()`, which would create the session — records a pending challenge, and
answers `202` with `two_factor: true`. Only `POST /login/two-factor-challenge`
creates a session.

The alternative, logging the user in and "requiring" the code afterwards, leaves a
real session that any client ignoring the response could simply use. The
distinction is the entire security value of the feature, so the tests assert it
directly: after the password step, `/api/v1/me` answers 401 and `last_login_at` is
untouched.

The pending state lives in the session — server-side, self-expiring, unforgeable
by the browser — and holds a user id and a remember flag, never a password, secret,
or code. The session id is regenerated before it is stored, so a fixed cookie
cannot inherit the challenge. It expires after five minutes, and the account is
re-read at challenge time, so an account disabled between the two steps cannot
finish a login it started while still active.

The challenge endpoint accepts no email and no user id, so it is not an
alternative way in — it can only continue a challenge that a correct password
created. Six digits is a million possibilities, which is plenty against a person
and nothing against a script, so the rate limit is what actually carries this
endpoint: five attempts per minute keyed on the pending account and source
address, and reaching the limit is not bypassed by finally guessing right.

### D-076 — Enrolment counts only once a code verifies, and secrets are shown once

TOTP is RFC 6238 through `pragmarx/google2fa`, with QR rendering by
`bacon/bacon-qr-code` — the pair Laravel Fortify uses. **No cryptography is
written here.** TOTP is easy to implement subtly wrong, and a subtly wrong
one-time-password scheme fails silently rather than loudly.

`two_factor_secret` and `two_factor_confirmed_at` are separate columns because the
gap between them matters. A secret alone must not require a code at login, or
anybody who closed the setup dialog before scanning would be locked out of their
own account. Enrolment becomes real only when a code from the authenticator
actually verifies; an unconfirmed secret expires after thirty minutes, and a
wrong confirmation code changes nothing so a clock a few seconds out costs a retry
rather than the whole enrolment.

Both the secret and the recovery codes are encrypted at rest, so a database dump
does not hand over the ability to mint valid codes. Recovery codes are
additionally hashed with the application hasher and consumed one at a time.

They are returned raw **exactly once** — at confirmation, and at regeneration —
and are unrecoverable afterwards, including to the user themselves and to any
administrator. That is the point rather than a limitation: a recovery code
readable after the fact is a second password sitting in the database. The
interface says so plainly instead of leaving somebody to discover it, and stores
none of it in `localStorage` or `sessionStorage`.

Regeneration replaces the whole set rather than topping it up. Somebody
regenerating has decided the old list is compromised, and one surviving code keeps
exactly the hole they are closing.

Disabling two-factor and regenerating recovery codes both require the current
password; enabling it does not. Adding protection should be the frictionless
direction, and removing it is where friction belongs.

An administrator holding `security.mfa.manage` can **remove** a second factor and
nothing else — the recovery path for a lost phone with the recovery codes lost
alongside it. There is no endpoint that reads a secret, sets one, or issues
recovery codes for another account, so the worst this permission can do is return
an account to password-only, visibly and in the log. The user re-enrols from their
own screen and is the only one who ever sees the new secret.

Credential state is hidden at the model as well as omitted from every resource:
two independent defences, because a resource that leaks a TOTP secret leaks it to
the log, the browser cache, and every proxy in between.

### D-077 — A documented gate may never be weaker than the enforced one

`CLAUDE.md` sections 51 and 52 listed three frontend commands; CI enforced four.
Work that passed every documented command still failed CI, which is how M1.9
produced a red run on `c231eda` and needed the follow-up `baae1bc`.

The rule is now explicit and stated where the list lives: **adding a gate to
`.github/workflows/quality.yml` means adding it to `CLAUDE.md` in the same
change.** `README.md` already carried all four, which is what makes the failure
mode worth naming — one document being right is not enough when another is the
one being followed.

This generalizes past formatting. Any claim the repository makes about itself
must be checked against the thing it describes, not against memory of it. M1.10
found the same class of defect twice more: the Permission Matrix still badged
`users.reset_password` as "not yet available" nine commits after M1.9 built it,
and `README.md` still told a new developer to create their first user through
`php artisan tinker` after M1.6 shipped `php artisan app:bootstrap` — advice that
by then could not work, since a user requires an Office and permissions.

A status claim with no mechanism to keep it true is a claim that will eventually
be false. Where cheap, prefer one that is checked: the deferred-permission list
is now asserted against the router, so a badge cannot outlive the gap it
describes.

### M1 implementation order

```text
M1.0   Planning
M1.0A  Architecture decision lock          <- this checkpoint
M1.1   Organization & Office schema foundation
M1.2   Canonical Permission Registry
M1.3   Data Scope model + effective-access resolver foundation
M1.4   Role Management
M1.5   User domain / User Management
M1.6   Permission Assignment / Matrix + bootstrap foundation
M1.7   Permission-aware navigation
M1.8   Profile + Preferred Language
M1.9   Account Security
M1.10  M1 quality gate
```

The registry precedes user management because a permission cannot protect an endpoint
before it exists. The scope model precedes role management because
`role_permission_scopes` is part of role-to-permission assignment.

**M1.1 is schema and domain foundation only.** It must not expose management endpoints
before the canonical permissions of M1.2 exist to protect them.

---

## 2026-08-11 — M2.0 Party architecture lock

Branch `feat/m2-parties`. Documentation only — no schema, model, endpoint, or permission
results from these decisions. Full reasoning in `12_M2_PARTY_ARCHITECTURE.md`.

### D-078 — One Party aggregate; "Client" is a word, not a table

Every person and organization the office knows is exactly one row in `parties`, with
person-specific data in `individuals` and organization-specific data in `companies`. There
is **no `clients` table**, no `Client` model duplicating Party, and no `client_id` running
parallel to `party_id`.

A Party becomes a client through use. The same person is a seller in one matter and a
company director in another, and the same organization is a client on Monday and a
counterparty on Thursday — CLAUDE.md section 17 already refuses to freeze a role into the
base Party record, and a `clients` table would be that same mistake under a different name.

Subtype tables take `party_id` as **both** primary key and foreign key. That one choice
enforces the invariants structurally rather than by convention: exactly one subtype per
Party, no subtype without a Party, and no way to write two Individual rows for one Party.
No surrogate id is added to a subtype.

**`party_type` is immutable after creation.** An Individual is never converted in place into
a Company. The subtypes differ in identity semantics, validation, relationships, and every
future legal reference that will point at them, so an in-place conversion would silently
reinterpret existing data and anything already referring to it. A record created with the
wrong type is archived and recreated — visibly. M2 therefore ships no type-conversion
workflow and no merge workflow.

### D-079 — `display_name` is derived, never a third name

`parties.display_name` is a normalized display and index value owned by the aggregate, not
an independently editable field that can drift from the subtype.

```text
Individual   derives from the individual's canonical full name
Company      derives from short_name when intentionally present, otherwise legal_name
```

The Company precedence is a choice, not an inheritance: a short name exists because somebody
wanted the organization displayed that way. Subtype-name changes and the `display_name`
update occur in one transaction — otherwise a rename leaves the directory showing the old
name while the detail page shows the new one, and the directory is what people search.

### D-080 — Party is Office-owned, and OWN/ASSIGNED/TEAM grant nothing

`parties.office_id → offices.id`, required. No `organization_id` on Party — the Organization
is reached through the Office, as D-027 established for User. No `tenant_id`, no
`party_offices` pivot, no global Party table detached from Office ownership, and no automatic
cross-office sharing. Cross-office reach is a Data Scope question, never a copied row.

Data Scopes remain predicates, never ranks (D-028). For Party-domain resources:

```text
OFFICE      party.office_id == actor.office_id
ALL         any Office in the deployment
OWN         grants nothing
ASSIGNED    grants nothing
TEAM        grants nothing
```

The three that grant nothing matter most. `OWN` must not become `created_by`: a Party is a
shared office directory record, and the colleague who typed it in has no special claim on the
human it describes. `ASSIGNED` must not be invented into existence — no Party assignment
entity exists, so there is nothing to match, and creating one to give the word work would be
building a feature to justify a scope. `TEAM` must never alias to `OFFICE`; no Team entity
exists (D-042), and quietly equating them would grant access nobody configured. All three
fail closed.

Creation is authorized against the **intended target Office**: `OFFICE` may create in the
actor's own, `ALL` may create elsewhere where the API exposes the choice, and the other three
grant nothing. Office selection is never a frontend-only rule.

A `company_people` relationship must not silently bridge Offices: the Company Party and the
Individual Party must share an `office_id`. `ALL` governs visibility and administrative
reach; it does not redefine domain ownership.

### D-081 — `deleted_at` is the only archive authority

Party-domain records are archived, never hard-deleted through ordinary operations, and the
**aggregate root** carries the state. Archiving an Individual or a Company archives the Party;
subtypes are not independently soft-deleted, because a live Party root with an archived
subtype is a state nothing could render honestly.

The historical ERD gave `parties` **and** `companies` a `status` column alongside
`deleted_at`. Both `status` columns are dropped. Two sources of truth for "is this record
active" is how a record ends up archived-but-visible, and the disagreement is invisible until
somebody notices the wrong thing on a screen. If a future business state genuinely differs
from archived, it gets its own column and its own name.

No restore capability in M2: the registry defines `parties.archive` and `companies.archive`
but no restore permission, and inventing one is out of scope.

Same reasoning removes `company_people.is_current`, which duplicates what `effective_until`
already says. Current-ness is a query, not a column.

### D-082 — Sensitive identity is two-tier, per field, enforced at serialization

The registry carries four canonical codes (D-001), and they form two tiers:

```text
parties.identity.view            tier 1 — open the identity surface
parties.identity.update          tier 1 — mutate sensitive identity
parties.identity.nik.view_full   tier 2 — reveal raw NIK only
parties.identity.npwp.view_full  tier 2 — reveal raw NPWP / tax identifier only
```

`parties.identity.view` **alone** opens the surface with NIK and NPWP still masked — access
to the surface is not access to the values. Each tier-2 code authorizes exactly one
identifier and implies nothing about the other. `parties.identity.update` authorizes
mutation and confers no full readback of identifiers the actor may not otherwise see: writing
a value is not licence to read a different one. `parties.view` implies neither surface access
nor reveal, and `companies.view` implies no raw tax-identifier reveal.

**Company tax identity uses `parties.identity.npwp.view_full`.** No `companies.identity.*`
family is invented; the identity surface belongs to the aggregate, which is why the registry
places these codes in the `parties` group.

**A browser not authorized for a raw identifier never receives it.** Not hidden by CSS, not
masked in React — absent from the payload. Masking is presentation computed server-side;
the mask is never the stored value. This is the difference between privacy and the appearance
of it, and it is a backend serialization guarantee rather than a UI convention. A reveal
control must fetch from the identity surface, never unhide a value the page already holds.

Raw identifiers never appear in logs, exception text, telemetry, `display_name`, URLs, cache
keys, or browser storage. At rest they use framework encryption primitives — **no custom
cryptography**, for the reason M1.9 refused to hand-roll TOTP. Any future equality-search
fingerprint must be a documented keyed construction, never an unkeyed hash (a 16-digit NIK
is brute-forceable in seconds) and never API-visible.

**NIK and NPWP format validation is deferred**, because no canonical document in this
repository freezes either format and general knowledge is not authority. Encoding a guess
would reject real identifiers.

### D-083 — Company relationships keep history; categories map to two permission surfaces

`company_people` links a Company Party to an Individual Party and **never duplicates the
person's name** — the name lives in one place and stays correct when it changes.

History is preserved. A director change ends the existing relationship by setting
`effective_until` and inserts a new row; it never overwrites. "Who was the director in March"
must remain answerable, because deeds executed in March depend on the answer.

Relationship categories map to the existing permission surfaces:

```text
DIRECTOR, COMMISSIONER, AUTHORIZED_PERSON   -> companies.management.*
SHAREHOLDER, BENEFICIAL_OWNER               -> companies.shareholders.*
```

The split categorises by what the relationship is *about* — who acts for the organization
versus who owns it — and invents no Indonesian corporate law. Nothing here asserts how many
directors a company may have, whether a commissioner is required, that shareholdings total
100%, or how beneficial ownership is determined. Ownership data is not visible merely because
a user can view ordinary Company details, and a frontend tab is never the boundary.

### D-084 — Duplicate detection is advisory and Office-scoped

Detection surfaces candidates to a human. It does not auto-merge, does not overwrite, does
not delete a candidate, and does not assert that two records are the same person — an
assertion the software has no standing to make.

Candidates are confined to the actor's own Office by default. An `OFFICE`-scoped user must
never learn that a matching identifier exists in an Office they cannot see.

**Clarified at M2.5, where "by default" had to be resolved rather than carried.** The bound is
the **target Office** — the Office the record is being created in, or the one the record being
edited already lives in — and **`ALL` does not widen it**. An `ALL`-scoped actor checking a
candidate for Office A compares against Office A and nothing else. `ALL` grants reach to *work*
in another Office; it does not turn duplicate detection into a deployment-wide identity
registry, and the oracle this decision exists to close does not become acceptable because the
person asking has a wide scope. Both constraints are applied together — the target Office and
the actor's own visibility — so the narrower always wins.

`12_M2_PARTY_ARCHITECTURE.md` section 15 previously read that `ALL` "may see across Offices
where a later milestone implements it explicitly". That reading is withdrawn, and the section
is corrected. It was a reasonable inference from "by default"; it is simply not what the
threat model supports.

**A sensitive signal answers to that identifier's own full-view permission**, not to the
lifecycle permission that reached the record: being told "another record here already carries
this NIK" is a disclosure about that record. `parties.identity.update` is explicitly not
sufficient — writing a value is not licence to learn somebody else already has it. A request
for a signal the caller may not receive is a **403**, never a result quietly narrowed to
exclude it, because a caller who could compare a narrowed result against an unnarrowed one
would read the missing signal as the answer.

That constraint is also why **no `UNIQUE` constraint is placed on `nik`, `npwp`, `tax_id`, or
`registration_number`**. A unique index asserts that two rows sharing a value are the same
entity and that the value is always known and correct — none of which holds for optional,
sometimes-mistyped, Office-scoped identifiers. It would also become a cross-office existence
oracle, since a rejected insert reveals a match the user is not entitled to know about. They
remain excellent duplicate *signals*; promoting one to an authoritative key needs its own
decision.

### D-085 — Relationship history is append-and-close: the API offers add and end, and nothing else

**D-083 says history is preserved. This says what the API may therefore expose**, because a
data-model rule that no interface enforces is one a later milestone will break without
noticing: nothing in D-083's wording forbids a `PATCH` that rewrites `relationship_type` on
an existing row, and such an endpoint would contradict its intent while satisfying its letter.

The public mutation surface for `company_people` is exactly two operations:

```text
add     POST   .../{category}                       a new row
end     POST   .../{category}/{relationship}/end    writes effective_until, nothing else
```

There is **no `DELETE` and no generic `PATCH` or `PUT`** on a relationship, at any level —
not on the nested path, and not on `company_people` as a resource of its own. Superseding a
relationship is end-then-add: two rows, both readable. Reappointing the same person after a
gap is likewise a second row, not a reopened first one.

Three fields are the historical fact and are immutable once written:

```text
company_party_id      individual_party_id      relationship_type
```

"Who was the director in March" must stay answerable because deeds executed in March depend
on the answer, and a director who was *later* recorded as a commissioner did not retroactively
attend that signing as one.

**Ending is not idempotent.** A relationship that already carries an `effective_until`
answers **409**, not a silent success — a second end is a request to change a recorded end
date, which is an amendment. M2.4 builds no amendment workflow, and quietly overwriting the
date would be the software correcting a legal record on its own initiative. If corrections
are genuinely needed, they need their own decision covering who may make them and what is
retained.

**The end date is supplied, never defaulted.** Defaulting to today would have the application
inventing a fact about when an appointment ceased. The person recording it knows; the software
asks.

`effective_until IS NULL` remains the only definition of current-ness (D-081), and no rule
compares either date to today: `12_M2_PARTY_ARCHITECTURE.md` section 13 imposes no
date-transition rules, so none is enforced — including any requirement that an end date fall
after a start date.

Archiving neither endpoint touches these rows. Retiring a person from the directory is not a
statement about their past appointments, and archiving a Company does not unmake its history —
`ArchiveCompany` leaves `company_people` alone deliberately.

### D-086 — Sensitive duplicate lookup uses keyed blind fingerprints, derived and non-unique

M2.0 deferred the sensitive-identifier duplicate mechanism and M2.1 added no column, because
locking a cryptographic design before reviewing it is how a weak one ships. M2.5 needs it, so
this settles it.

**The problem.** `nik`, `npwp`, and `tax_id` use Laravel's `encrypted` cast, which is
randomized: the same NIK encrypted twice yields two different ciphertexts. `WHERE nik = ?`
can therefore never match, and every obvious alternative is worse. Decrypting the directory
to compare in PHP does not scale and puts every identifier in memory to answer one question.
A plaintext copy defeats the encryption. **An unkeyed hash is brute-forceable in seconds** —
a NIK has 10^16 possibilities and a GPU does not find that hard — which D-082 already said.

**The construction.**

```text
subkey      = HKDF-SHA-256(APP_KEY material, 32 bytes,
                           info = "notary-ppat/party-identity-fingerprint/v1")
fingerprint = HMAC-SHA-256(conservatively normalized value, subkey)  -> 64 hex
```

**Keyed**, so a stolen database dump cannot be enumerated offline without the application key.
**Derived rather than reusing `APP_KEY` directly**, so this purpose is domain-separated from
encryption and a problem in one use does not hand over the other. **Versioned context**, so a
future construction re-derives from the same key rather than needing a rotation or a second
secret. **Standard primitives only** — `hash_hkdf` and `hash_hmac`, both PHP core — for the
reason M1.9 refused to hand-roll TOTP. No second production secret is introduced.

**Normalization is deliberately conservative: `trim` and nothing else.** Leading zeros,
internal punctuation, and case are all preserved, so `09.123.456.7-890.123` and
`091234567890123` produce **different** fingerprints and do not match. That is an accepted
false negative, not an oversight. No canonical document in this repository defines legal NIK
or NPWP normalization, Indonesian NPWP formats have changed, and a guess encoded here would
silently assert an equivalence nobody approved. Detection is advisory (D-084), so
under-reporting costs a missed hint while over-reporting would make a claim about identity.
**Missing a match is the safe direction**, and this stays true until domain authority defines
the rule — at which point the versioned context allows a rebuild rather than a migration.

**Non-unique, always.** The columns are indexed for equality lookup and carry no `UNIQUE`
constraint, for the reasons D-084 gives: uniqueness asserts that two rows sharing a value are
the same entity and that the value is always known and correctly entered, none of which holds
for optional, sometimes-mistyped, Office-scoped identifiers. It would also make a rejected
insert a cross-office existence oracle, and would convert advisory detection into blocking
enforcement, which M2 did not decide.

**Internal metadata, disclosed to nobody.** Hidden at the model, absent from every Resource
and every frontend type, never logged, never in a URL — and **not disclosed even to a holder
of the full-view reveal permission**, which authorizes the identifier through the reviewed
reveal surface, not the cryptographic material derived from it.

**Rotating `APP_KEY` invalidates every fingerprint**, because they derive from it. A rotation
must be followed by `php artisan parties:rebuild-identity-fingerprints`; until it runs,
duplicate detection under-reports — the safe direction, but an operational fact that belongs
in the runbook rather than in somebody's surprise.

### M2 implementation order

```text
M2.0   Planning + Party architecture lock       <- this checkpoint
M2.1   Party schema + authorization foundation
M2.2   Individual Management
M2.3   Company Management
M2.4   Company relationships / management / shareholders
M2.5   Party directory + duplicate detection + integration polish
M2.6   M2 quality gate
```

M2.1 is schema, authorization predicates, and constraints only — not CRUD UI. **Project
remains M3**: M2 builds no Project, no Matter, and no Party-to-Project assignment.

---

## 2026-08-16 — M3.0 Project architecture lock

Full architecture in `13_M3_PROJECT_ARCHITECTURE.md`. These are the durable rulings.

### D-087 — M3 implements Project only; Matter is a separate aggregate and belongs to M4

Project and Matter are **separate persistence entities**. Neither is a display label for the
other — `CLAUDE.md` section 15 says so directly, `00_PROJECT_OVERVIEW.md` sections 5 and 6
define both, and `03_DATABASE_ERD.md` sections 7 and 9 give each its own table. The M3.0
discovery examined collapsing them in either direction and found canonical support for
neither.

**M3 implements Project only.** Matter persistence, Matter authorization, `matter_parties`,
Notary Matter, PPAT Matter, the `notary.matters.*` and `ppat.matters.*` implementations, and
the Workflow Engine are **M4**.

This resolves a conflict rather than papering over one. The milestone was proposed as
"Project / Matter", while `00_PROJECT_OVERVIEW.md` section 19, `CLAUDE.md` section 2, and the
milestone register above all read **M3 — Project Management** and **M4 — Matter & Workflow
Engine**. The roadmap wins, and the discrepancy was reported rather than silently decided
(`CLAUDE.md` section 58).

Project is the **M3 aggregate root**. Matter is a **future child aggregate with its own
lifecycle**, not a component of Project — so M4 decides Matter's archive and lifecycle rules
rather than inheriting Project's. **M3 invents no Project-to-Matter cardinality**: whether a
Project must have a Matter, may have none, or is capped is an M4 question with a domain
component, and no such constraint is written anywhere in M3.

M3.0 documents the boundary because an aggregate edge cannot be described without naming what
attaches to it. It builds none of the other side.

### D-088 — Project Data Scope predicates, and why `OWN` differs from the Party answer

M2 left `projects.*` in `PermissionScopeRules`' permissive default with an explicit note that
narrowing it would mean deciding what a scope meant for a domain nobody had designed. M3 is
where that becomes legitimate — for Project, and only for Project.

```text
OWN        project.created_by   == actor.id
ASSIGNED   project.pic_user_id  == actor.id
OFFICE     project.office_id    == actor.office_id
ALL        cross-office Project reach
TEAM       no Project-domain grant
```

**Predicates, never a ladder.** `ALL` does not outrank `OFFICE`; it is an independent
condition that happens to subsume it, and multiple grants union their predicates (D-028).
Nothing ranks or collapses them. Unknown or missing scope metadata fails closed (D-039).

**`OWN` is `created_by` here, and that does not contradict M2.** D-080 refused `OWN` for Party
on reasoning specific to Party: a Party is a shared directory record, and the colleague who
typed one in has no claim on the person it describes. A Project is not a shared reference
record — it is a unit of work somebody opened. The reasoning did not transfer, so neither did
the answer. Two domains, two predicates, each argued on its own facts.

**`ASSIGNED` is `pic_user_id` and nothing else.**

**Future Matter or stage assignment must never expand Project `ASSIGNED`.** When M4 adds
`matters.pic_user_id`, and its workflow adds `matter_stage_instances.assigned_user_id`, it
will be tempting to let either widen Project reach on the reasoning that somebody working a
Matter must see its Project. That would be a **new grant wearing an existing scope's name**,
silently widening every role already configured with Project `ASSIGNED`. If Matter workers
need Project visibility, that is its own decision and its own predicate.

### D-089 — Project Office ownership is required and immutable during M3

`projects.office_id` is required. **M3 ships no Project Office-transfer operation** — no
endpoint, no Action, no administrative path.

This is an **engineering boundary, not a claim of legal impossibility**. An office may have a
legitimate reason to move a Project. What M3 refuses is inventing the semantics of that move —
what becomes of participants, of future Matters, of internal references already issued —
before anyone has specified them. Any future transfer requires its own architecture decision.

The same conclusion M2 reached for Party (D-080), argued independently rather than inherited
by analogy.

### D-090 — `view_all` permissions are superseded by Data Scope `ALL` and are not an authorization authority

`projects.view_all`, `notary.matters.view_all`, `ppat.matters.view_all`, `tasks.view_all` and
`calendar.view_all` predate the Data Scope model. They express **reach**, which is exactly
what a Data Scope expresses, and `CLAUDE.md` section 26 warns against duplicating a permission
per scope. `02_MENU_AND_PERMISSIONS.md` lists them as bare entries with no stated meaning.

- The codes **remain registered**, for compatibility and documentation history. **The
  canonical count stays at 171**, and M3.0 removes nothing.
- For **reach semantics they are superseded by Data Scope `ALL`**.
- **No `view_all` code may serve as backend cross-office authorization authority.**
- **No second reach mechanism may exist alongside `EffectiveAccessResolver`.** One resolver
  answers reach, or two answers eventually disagree and the looser one wins by accident.

A supersession, recorded — not a deletion, and not a silence.

### D-091 — Project assignment and status changes are separate capabilities from ordinary update

```text
projects.update          ordinary attributes
projects.assign          project.pic_user_id, and nothing else
projects.change_status   project.status, and nothing else
```

**`projects.assign` means mutating `pic_user_id`.** Generic `projects.update` must not touch
it: reassigning work is a different act from correcting a title, and the registry has always
carried a separate code for it. **Workflow and stage assignees are not Project assignment**;
when they exist they will not write `pic_user_id`.

**`projects.change_status` is separate from `projects.update`**, and generic update must not
mutate status. Status moves through a dedicated action and authorization boundary.

**No transition matrix is invented.** Which status may follow which is an operational rule
nobody has specified. M3 authorizes *who may change status*; it does not encode *which changes
are legal*. Encoding one from memory is the failure `CLAUDE.md` section 62 prohibits, one
domain removed.

### D-092 — Project participation lives on `project_parties`; `primary_client_party_id` is rejected

`project_parties` is the **canonical and only** source of Project ↔ Party participation, and
the **role lives on the relationship**, never on the Party record (`CLAUDE.md` section 17,
D-078).

**`03_DATABASE_ERD.md` section 7's `primary_client_party_id` is rejected as duplicate
persistence.** `project_parties` already carries participation and the ERD gives it an
`is_primary` flag; two mechanisms for one fact drift apart, and the column-shaped one
additionally re-creates the "client" concept D-078 refused. If primary designation is retained
it is represented on `project_parties`.

**No raw Party sensitive identity is copied into any Project-domain table** — no NIK, NPWP,
`tax_id`, mask, or fingerprint. Project references a Party by id and reads identity, if ever,
through the surfaces that already authorize it (D-082). **No Client persistence** (D-078).

**No participant semantics are invented**: no mandatory primary client, no exactly-one-primary
rule, no legal participant role catalogue. The ERD offers *example* role codes and says so; a
real catalogue and any cardinality attached to it need domain authority.

### D-093 — `projects.restore` restores a deleted record, not a business state

Business status `ARCHIVED` and the `deleted_at` column are **different states with
unfortunately similar names**. The awkwardness is named here rather than smoothed over.

`projects.restore` is retained, and means exactly one thing:

> restore a soft-deleted Project persistence record.

It does **not** mean changing business status `ARCHIVED` back to `OPEN`, reversing a workflow,
undoing a completion, or undoing any legal event.

**Party gains no restore for symmetry.** M2 refused to invent one because no restore
permission existed for Party; `projects.restore` is canonical and its Party counterpart is
not. The registry is the reason, and it applies to one domain and not the other.

### D-094 — A Project internal reference is ordinary office identification, never a legal number

A Project's internal reference follows `CLAUDE.md` section 38's internal-reference examples and
is **ordinary office identification**. It is explicitly **not** a deed number, a repertorium
number, a land or government registration number, or any legally significant document number.
Section 38 already separates the concepts; this restates it for Project so nobody later reads
legal weight into `PRJ-2026-000001`.

**No `MAX(number) + 1` allocator**, which is unsafe under concurrency (section 38).

The **allocation and concurrency design is locked before M3.2 implementation** and is
deliberately not guessed here: sequence versus advisory lock versus allocator table, and the
behaviour across offices and year boundaries, are real decisions with real failure modes. M3.2
owns them.

### D-095 — Two M3.1 schema departures: `project_number` is withheld, and `priority` borrows the one vocabulary the ERD defines

*(Added at M3.1, which owns the schema. Both are departures from
`03_DATABASE_ERD.md` section 7 and are recorded rather than silently made, following the
precedent `12_M2_PARTY_ARCHITECTURE.md` section 5 set for M2.)*

**`project_number` is not created at M3.1.** M3.2 owns internal-reference allocation (D-094),
and the column arrives **with its allocator**, not ahead of it.

The alternative — adding it nullable now — looks harmless and is not. Every M3.1 Project would
carry a null reference, so M3.2 would inherit a backfill on top of the allocator it was already
going to design, plus a uniqueness question it has not answered: unique per deployment, per
Office, per Office and year, or not unique at all. Deciding the column's shape before deciding
what fills it is how the answer gets made by accident.

This is exactly the reasoning **D-086** applied to the fingerprint columns: M2.1 added none,
because "a column added on speculation is one somebody fills in wrongly." M3.1 follows it.

**`priority` uses the vocabulary the ERD defines under `tasks`.** The document lists a
`priority` column on `projects` (section 7), `matters` (section 9), and `tasks` (section 23),
and gives the values exactly once — `LOW`, `NORMAL`, `HIGH`, `URGENT` — in the last of those.
M3.1 reads that as one shared vocabulary: the same column name appears three times, one set of
values is offered, and no competing set exists anywhere in the repository.

That is a **transcription with a named source, not an invention** — but it is the one Project
field whose values were not written beside the column they govern, so it is recorded here
rather than left for a reader to reverse-engineer. The column is **nullable**, so an office
that does not use priority is not forced into a value. If Project priorities should differ from
Task priorities, that is a domain decision and a forward migration.

**`status` carries no database default.** The schema records what the application decided; it
does not decide an initial state. A default would be the thin end of the transition matrix
D-091 refuses.

### D-096 — The Project reference namespace is Office-and-year, allocated atomically, and gapped

*(Added at M3.2. D-094 ruled what the reference **is not** — not a legal number, no `MAX+1`
— and deferred the mechanics to this milestone. These are the mechanics, and they are durable
rather than implementation detail because each one is visible from outside the allocator.)*

**The namespace is `(office_id, reference_year)`, and uniqueness is per Office.**

```text
UNIQUE (office_id, project_number)      never a global unique index
```

Each Office runs an independent annual sequence, so Office A and Office B may both legitimately
hold `PRJ-2026-000001`. A global index would fail the second Office's first project of the year
for a reason nobody could explain to them. The namespace is stable because Project Office is
immutable (D-089) — if a Project could move, its reference could collide with one already
issued at the destination.

Two consequences follow and both outlive the allocator. **A reference does not identify a
Project on its own**: any lookup by reference must carry the Office, or it is ambiguous before
it is even an authorization question. And the reference is **never authorization input** — it
identifies a record for people, and reach remains `office_id` / `created_by` / `pic_user_id`
through the resolver (D-088). Allocation adds **no permission**; it is an internal service
concern, not a user-facing ability.

**Allocation is one atomic statement, never a read-then-write.**

```sql
INSERT INTO project_reference_counters (office_id, reference_year, last_value, …)
VALUES (?, ?, 1, …)
ON CONFLICT (office_id, reference_year)
DO UPDATE SET last_value = project_reference_counters.last_value + 1
RETURNING last_value
```

The increment happens inside the database, against a row the engine locks for the duration of
the upsert. Two concurrent callers cannot compute the same value because neither computes it.

**A transaction alone would not be sufficient, and this is the trap worth naming.** Under
`READ COMMITTED` — PostgreSQL's default — two transactions can both read the same `last_value`
before either writes, and the loser gets a unique violation the user sees. Wrapping a
select-then-update in `DB::transaction()` looks like a fix and is not one.

The same statement runs on PostgreSQL and on SQLite 3.35+, verified on both before the
allocator was written, so there is one execution path and **no semantic divergence between the
test engine and the production engine** — which matters, because a concurrency strategy that
only exists on one of them is a strategy nobody tests. PostgreSQL is authoritative and carries
the contention evidence.

**Gaps are expected, and the sequence means nothing.** An allocation handed out and then not
used — a failed create, a rolled-back transaction after the counter moved — leaves a gap by
design. The alternatives are reusing references or serializing every create behind one lock,
and both are worse. Therefore:

- the number is **not a count** of an Office's Projects;
- sequential appearance carries **no legal weight**;
- a reference belonging to a persisted Project is **spent** — archiving does not release it,
  restoring keeps the same one, and no reuse feature exists.

**The year is decided at allocation from the application clock** and stored explicitly on the
counter row. Never from a browser, a request locale, user input, or by parsing an existing
reference — re-deriving it from the formatted string would make displayed text an input to
logic, which is how a cosmetic change becomes a behavioural one.

**The counter is Project-specific and stays that way.** Generalizing it into
`legal_number_sequences`, `deed_sequences`, or `matter_sequences` would pull deed, repertorium,
and register numbering — none of which has a validated domain rule — into a milestone that owns
none of them.

### D-097 — What the system decides when a Project is created, and what a refusal must look like

*(Added at M3.3, the milestone that turned the M3.1 schema and the M3.2 allocator into a
product surface. Each ruling below is the answer to a question the earlier milestones could
defer because nothing yet created a Project.)*

**Creation is always in the actor's own Office, and `office_id` is refused rather than
ignored.**

```text
POST /api/v1/projects  { office_id: … }   ->  422, always
```

This is the ruling most likely to be argued with, so the reasoning is recorded rather than
assumed. `ALL` is cross-office **reach over existing Projects** (D-088) — it answers "which
records may I see and act on", not "where may I file new work". An `ALL`-scoped actor is
therefore refused too. Ignoring the field instead of refusing it would be worse than either
choice: the caller would be told the Project went somewhere it did not.

**Four fields are set by the system at creation and appear in no request shape.**

```text
office_id        the actor's own Office
project_number   allocated by the M3.2 allocator (D-096)
status           OPEN
pic_user_id      null
```

`OPEN` is the initial status because a Project that has just been created has not been worked
on, and no canonical document defines an earlier state. The initial PIC is null because nobody
has been put in charge yet — inventing a default of "the creator" would be a business rule
nobody has stated, and it would immediately grant that person `ASSIGNED` reach they were never
given.

**`ASSIGNED` alone does not authorize creation, and this is not an exception to D-028.** Data
Scopes are predicates over records. The `ASSIGNED` predicate is `pic_user_id == actor.id`, and
a Project being created has no PIC — so the predicate is false for the very record the actor is
asking to create, and there is nothing to except. Only `ALL`, `OFFICE`, and `OWN` can match a
new record. The union rule is untouched: an actor holding `ASSIGNED` **and** `OFFICE` creates
normally, because `OFFICE` matches.

**The person in charge must be an active user of the Project's own Office**, enforced both in
the Form Request and again inside the Action. The restriction is not tidiness: `ASSIGNED` grants
reach when `pic_user_id == actor.id`, so a cross-office assignment would hand somebody reach
over a Project their own scope never included — a privilege grant performed through a work
allocation, with no role changing and nothing in the authorization surfaces to show for it.

The eligibility endpoint that populates the picker answers to `projects.assign` **on that
Project**, invents no permission, and returns `id` and `name` only. Every ineligibility — absent,
disabled, another Office — produces one indistinguishable message, so the endpoint cannot be
used to enumerate the user directory.

**`project_number` becomes `NOT NULL` at M3.3, by forward migration.** M3.1 withheld the column
and M3.2 added it nullable (D-095) because rows already existing could not be given a reference
retroactively. M3.3 owns the only path that creates a Project, and that path always allocates,
so the column can now carry the guarantee the domain always intended. The migration was written
only after inspecting the persistent development database and finding zero Projects and zero
null references; **no backfill was invented**, and a deployment holding null references must
resolve them deliberately rather than have this milestone guess.

**A refusal keys on presence, not on emptiness.**

```text
{ "pic_user_id": null }   ->  422, not 200
```

Recorded because M3.3 shipped the opposite behaviour first and the HTTP smoke caught it.
Laravel's `prohibited` rule means "missing **or empty**", so an explicitly null system-controlled
field satisfied it and the request answered 200 or 201 with the key silently discarded. Nothing
was ever written — the Actions fill an explicit allow-list and the model keeps these columns out
of `$fillable`, so there was no write path and no escalation — but the response told a caller
that an instruction had been accepted when it had been thrown away. `{"pic_user_id": null}` is an
unassign instruction, and unassigning belongs to `projects.assign` like every other assignment
(D-091). A silent no-op is not a refusal, and the empty case is no less silent than the
non-empty one.

**Capability flags on the Project resource are the Policy's answer, computed in bulk.**
`can_update`, `can_assign`, `can_change_status`, and `can_archive` come from the same Policy the
endpoints use, so the interface offers exactly what the server would accept — they remain
presentation, and each endpoint authorizes again. They are computed for a whole page in a fixed
number of queries rather than per row: `EffectiveAccessResolver` is deliberately uncached, so a
per-row Policy call is an N+1, which is the lesson M2.6 paid for on the Party surface.

**A record outside the caller's scope answers 403, not 404**, matching the convention M2
accepted for a Party in another Office. A soft-deleted Project answers 404, because route model
binding never resolves it — the two codes mean different things and neither is a leak worth
trading the other for.

### D-098 — Project participation: dedicated capabilities, a structural Office invariant, and current state rather than history

*(Added at M3.4, which built `project_parties`. D-092 ruled where participation lives and what
must not be invented; these are the questions building it actually raised. The pre-implementation
review found two of them genuinely unanswered by any canonical source and stopped rather than
guessing — what follows is the resolution, not a discovery.)*

**Participation has two dedicated capabilities, and neither implies the other.**

```text
projects.parties.view     read the participation list
projects.parties.manage   add, correct, remove
```

The canonical count moves **171 → 173**, the first addition since the catalogue was transcribed.
Dedicated codes follow the M2.4 precedent exactly: `companies.management.*` and
`companies.shareholders.*` govern Company relationships rather than `companies.update`, and for
the same reason — maintaining who is involved in something is a different act from editing the
thing itself. **`projects.update` reaches neither**, or the dedicated codes would be decoration.

That `manage` does not imply `view` is the half that feels wrong and is nonetheless deliberate.
The registry defines two codes; an administrator who wants both grants both. A silently implied
capability is one nobody configured and nobody can revoke, which is the same discipline D-091
applies to `update`, `assign`, and `change_status`.

There is **no `projects.parties.view_all`**. Reach is Data Scope `ALL` against the parent
Project, and a second reach mechanism is what D-090 refuses.

**Authorization is judged against the parent Project**, by the four D-088 predicates — `OWN` is
its creator, `ASSIGNED` its PIC, `OFFICE` its Office, `ALL` cross-office, `TEAM` nothing. A
participation belongs to the Project the way its title does. Judging against the Party instead
would put Project work behind Party permissions, which govern something else.

**The same-Office invariant is structural, not validated.**

```text
project_parties.office_id  ->  projects (id, office_id)
                           ->  parties  (id, office_id)
```

Two composite foreign keys through the **same** carrier column, so both endpoints must agree
with it and therefore with each other. A cross-office participation is *unrepresentable* rather
than discouraged — the M2.4 pattern (D-080), applied because the same sentence holds here: `ALL`
grants visibility and administrative reach, never permission to redefine domain ownership. An
`ALL`-scoped actor may reach a Project in another Office and link a Party **from that Project's
Office**; it never bridges two.

`parties` already carried `parties_id_office_id_unique`. `projects` did not, so M3.4 adds
`UNIQUE (projects.id, projects.office_id)` — a composite foreign key needs a unique index on the
referenced pair, and without it the invariant could only be checked in code.

**Managing participation is not authority to discover Parties**, and this is the boundary the
milestone exists to get right. Linking requires **both** `projects.parties.manage` over the
Project **and** ordinary Party visibility for the candidate — `parties.view` for an Individual,
`companies.view` for a Company, **evaluated independently**, because an actor may genuinely hold
one branch and not the other (D-028). A submitted `party_id` is **re-resolved through the
authorized candidate query** rather than trusted, so an id obtained elsewhere cannot become a
participation. Every failure — absent, archived, another Office, an unreachable subtype — answers
one indistinguishable 422, because telling them apart would answer a question the caller has no
permission to ask.

Candidates are same-Office and not archived. No User or Party permission was widened to populate
a picker.

**A linked Party is never withdrawn from the list.** A participation the office recorded is
Project data: a reader authorized for the list sees every row, each as a minimal stub — id,
display name, subtype, archived flag, and `can_view_party`. Hiding rows would misreport the
Project's composition to somebody entitled to read it, which is worse than declining to link
onward. The same holds when a Party is archived later: it stays listed and marked, is not
unlinked, and is simply no longer offered as a candidate.

**No sensitive identity crosses this surface** — no NIK, NPWP, `tax_id`, fingerprint, contact, or
address, and **no masks either**, since a mask is still a statement about a sensitive value
(D-082).

**Participation is current working state, not a historical ledger.** This is the sharpest
departure from `company_people` and it is deliberate:

```text
company_people    effective_from  effective_until   history, because deeds depend on it
project_parties   created_at  created_by            what is true now
```

`03_DATABASE_ERD.md` section 7 gives participation no period columns, no `updated_at`, and no
`deleted_at`, and none was added. Removing a participation **hard-deletes the relationship row
and nothing else** — the Project and the Party both remain, unarchived and unaltered. A soft
delete would have created a half-history: rows nobody lists, no mechanism to read them, and a
schema claiming preservation that no surface honours. D-083 keeps history because "who was the
director in March" decides the validity of a deed executed in March; nothing yet depends on who
was listed on a Project last Tuesday, and building the mechanism before the requirement would be
building for an imagined caller. **If participation history is later required it needs its own
decision and its own columns**, not a `deleted_at` added quietly.

Correcting a participation writes `role_code`, `is_primary`, and `notes` only. Re-pointing it at
a different Party is refused: that is a different relationship, not an edit, and allowing it
would let one row silently become another while keeping the first one's attribution — and would
skip the candidate authorization the add path performs.

**`role_code` stays an opaque, nullable classification.** No enum, no `Rule::in`, no `CHECK`. The
ERD's six codes are labelled examples and are not a catalogue, so constraining the column would
invent the participant-role vocabulary M3 has no authority to write (CLAUDE.md section 62). A
code the ERD never mentions stores exactly as one it does.

**`is_primary` is a designation and carries no cardinality.** Not exactly-one, not at-least-one,
not one-per-role, and not an assertion of client or legal authority. Several participants may be
primary at once and none has to be. **A Project with no participants at all is complete, not a
draft** — M3.3's create surface was not retroactively given a participant field, and no
uniqueness constrains `(project_id, party_id)`, so one Party may appear twice under two
classifications. Each of these absences is a rule nobody has stated; inventing any of them
through an index or a validator would be a business rule wearing an implementation's clothing.

### M3 implementation order

```text
M3.0   Project architecture lock                  <- this checkpoint
M3.1   Project schema + authorization foundation
M3.2   Project internal reference foundation
M3.3   Project core management
M3.4   Project <-> Party participation
M3.5   M3 quality gate
```

M3.1 is schema, Policy, Data Scope predicates, the `PermissionScopeRules` Project entry,
constraints, and architecture tests — **not CRUD UI**, following the M2.1 precedent. It is
also where the M2-era guard tests asserting `projects` does not exist are **narrowed rather
than deleted**: what stays true is that Party gains no Project foreign key and that no deed,
Warkah, or property surface appears.

**Matter begins at M4.0** with its own architecture lock.

---

## 2026-08-17 — M4.0 Matter and Workflow architecture lock

Full architecture in `14_M4_MATTER_ARCHITECTURE.md`. These are the durable rulings.
Documentation only — no schema, model, endpoint, permission, or frontend results from them.

### D-099 — Matter is a required child of Project, and its Office is inherited and immutable

`matters.project_id` is **required**. A Matter always belongs to exactly one Project; one Project
may have many Matters; **a Project with zero Matters is complete, not a draft.** Project never
embeds Matter state — no counter, no current-Matter pointer, no rolled-up status. Matter
references Project, never the reverse (D-087), and Matter is a child aggregate with **its own
lifecycle**, so Project's lifecycle actions do not cascade into it.

M3 deliberately fixed no cardinality. M4 fixes only the half it has authority over: the structural
requirement that a Matter names a parent. **No minimum is encoded** — whether an office's practice
expects every Project to carry a Matter is operational, not architectural.

`matters.office_id` is **required, inherited from the parent Project at creation, and immutable
for the duration of M4**. The creating actor neither chooses nor submits it. Enforcement is
**structural**:

```text
matters (project_id, office_id)  ->  projects (id, office_id)
```

`projects` has carried the `UNIQUE (id, office_id)` support key since M3.4 (D-098), so M4 reuses
it rather than adding one; `matters` gains its own equivalent for `matter_parties` to reference
later. A Matter whose Office disagrees with its Project's is **unrepresentable**, not merely
refused — the pattern proven for `company_people` (D-080) and `project_parties` (D-098).

**M4 ships no Office-transfer operation** for Project or Matter. An engineering boundary, not a
claim of legal impossibility, identical in reasoning to D-089: what a transfer would mean for
participants, references already issued, and workflow already run is undesigned.

### D-100 — Matter authorization is independent of Project authorization

```text
OWN        matter.created_by   == actor.id
ASSIGNED   matter.pic_user_id  == actor.id
OFFICE     matter.office_id    == actor.office_id
ALL        cross-office Matter reach
TEAM       no Matter-domain grant
```

`matters.*` sat in `PermissionScopeRules`' permissive default with a note that narrowing it would
mean deciding what a scope meant for a domain nobody had designed. M4 is where that becomes
legitimate — for Matter, and only for Matter. Predicates, never a ladder; grants union (D-028);
unknown or missing scope metadata fails closed (D-039); no widest-scope, rank, or `maxScope`.
`TEAM` is withheld as everywhere — no Team entity exists (D-042).

**Reaching a Project confers no Matter authority.** An actor who may view, update, or archive a
Project gains by that fact alone no right to view or change any Matter beneath it. The easy
answer — "if you can see the Project you can see its Matters" — would make Project reach a silent
superset of Matter reach, so an administrator granting `projects.view` would have granted Notary
and PPAT work visibility without ever naming those capabilities.

**The converse stays forbidden.** D-088 prohibits Matter or stage assignment from widening Project
`ASSIGNED`; this decision adds the symmetric rule, so neither direction leaks.

**One interaction survives, and only one: creating a Matter validates the parent Project through
canonical Project authorization.** A Matter may not be created under a Project the actor cannot
canonically reach, and the check is the ordinary Project reach question answered by the ordinary
Project mechanism — not a new predicate and not a relaxation. It applies to creation, where a
parent is being chosen, and extends to nothing else.

**`matter_stage_instances.assigned_user_id` does not count toward Matter `ASSIGNED`.** Matter
`ASSIGNED` is `pic_user_id`, one column, one comparison. When M4.7 adds stage assignees it will be
tempting to let them widen Matter reach; that would be a **new grant wearing an existing scope's
name**, silently widening every role already configured with Matter `ASSIGNED` — the failure D-088
named one milestone earlier, one domain across.

### D-101 — Domain-split Matter routes, and the permission namespace comes from the route

```text
/api/v1/notary/matters   ->  notary.matters.*
/api/v1/ppat/matters     ->  ppat.matters.*
```

**The generic `/api/v1/matters?domain=...` form is refused**, and `06_API_CONVENTIONS.md` is
corrected at M4.0 rather than left to be discovered by whoever writes the first route. That
document carried the generic form while using domain-prefixed paths for deeds in the same section;
the canonical registry splits the capability surface with **no generic `matters.*` namespace**; and
`02_MENU_AND_PERMISSIONS.md` section 26 splits the sidebar the same way. Three sources against one,
and the one disagreed with itself.

**The namespace is a property of the route context and is never selected from a request-body
`domain` field.** No Policy reads row data to decide which permission to resolve.
`13_M3_PROJECT_ARCHITECTURE.md` section 12 flagged the alternative as a genuinely new
authorization shape — a Policy choosing its permission namespace from the record it is being asked
about. Route-derived namespacing keeps the question ordinary: each route knows its capability
before it touches the database.

**For an existing Matter, the persisted `domain` must match the domain route.** A Notary route
handed a PPAT Matter's id fails closed through the canonical binding convention — the same **404**
M3.4's nested participation binding returns for a foreign parent (D-098), and for the same reason:
a 403 would confirm the record exists in a domain the caller did not name.

### D-102 — M4 builds the Matter root only; extension tables and the service catalogue are deferred

M4 builds **one root table, `matters`, with a canonical `domain` discriminator** (`NOTARY`,
`PPAT`), following `03_DATABASE_ERD.md` section 9.

**M4 builds neither `notary_matters` nor `ppat_matters`**, and persists no field standing in for
one: not `deed_category`, `requires_minuta`, `requires_register_entry`, `land_office_region`,
`tax_processing_required`, or `registration_required`. Those are domain-semantic and unvalidated,
and `01_ARCHITECTURE.md` section 28 places **M6 — Notary** and **M7 — PPAT** after this milestone.
This follows D-095: a column added on speculation is one somebody fills in wrongly.

**M4 owns the service type master-data infrastructure but seeds no catalogue.** Which services the
office actually offers is the first open question in both workflow drafts. **`matters.service_type_id`
is therefore nullable in M4** — a Matter may exist without a service type until domain content is
validated. Requiring it would make Matter uncreatable for as long as the catalogue is empty, which
is the D-095 lesson in reverse: a constraint that outruns the data it constrains blocks the
milestone that would satisfy it.

**M4 ships no Matter archive or restore lifecycle, and M4.0 registers no archive or restore
permission.** The canonical registry gives Matter eight codes per domain and neither is `archive`
nor `restore` — unlike Project, which has both. The absence is the registry's, and M4 does not fill
it by invention. `matters.deleted_at` may exist as reserved schema capability with **no API
lifecycle reaching it**: a column without a surface is honest; a surface without a permission is
not.

**`CANCELLED`, `COMPLETED` and `ARCHIVED` are business statuses and must never be reused as
synonyms for soft deletion.** `ARCHIVED` and `deleted_at` are different states with unfortunately
similar names — the awkwardness D-093 named for Project, restated because the Matter vocabulary
carries the same trap and Matter has **no restore path** to recover from a wrong answer.

**M4 invents no status transition matrix.** It authorizes *who* may change, complete, or cancel a
Matter — three separate canonical capabilities — never *which* status may follow which. The M3
precedent is D-091, taken for the same reason (`CLAUDE.md` section 62).

### D-103 — Matter internal reference: per-domain prefix, Office-and-year namespace, dedicated allocator

A Matter's internal reference is **ordinary office identification**, not a deed number, a
repertorium number, a land or government registration number, or any legally significant document
number (`CLAUDE.md` section 38).

```text
N-YYYY-NNNNNN     Notary
P-YYYY-NNNNNN     PPAT
```

Both prefixes are transcribed from `CLAUDE.md` section 38's internal-reference examples.

**Namespace: Office + calendar year + domain.** Three components, because the two prefixes are
distinct and a shared counter would make `N-2026-000001` and `P-2026-000001` compete for one value.

**A dedicated Matter allocator.** `13_M3_PROJECT_ARCHITECTURE.md` section 9 deliberately refused to
generalize the M3.2 Project allocator into `legal_number_sequences` or anything Matter-shaped; M4
honours that refusal rather than quietly reversing it by extending the same table. The proven
mechanism — one atomic `INSERT … ON CONFLICT … DO UPDATE SET last_value = last_value + 1 RETURNING
last_value`, identical on PostgreSQL and SQLite 3.35+ — is the pattern to follow, not the table.

Forbidden, all unsafe under concurrency: `MAX(number) + 1`, `COUNT(*) + 1`, `latest() + 1`, and any
read-then-write allocation. **Gaps are expected and carry no meaning**; the sequence is not a record
count. **A reference is immutable once assigned.**

### D-104 — M4 builds a workflow mechanism, deliberately without validated workflow content

**The engine's shape is canonical. Its content does not exist.**

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are `DRAFT — DOMAIN VALIDATION REQUIRED`, each
stamped `DO NOT IMPLEMENT FROM THIS DOCUMENT YET`, each stating that no workflow content has been
authored and **none may be inferred from other documents in this repository**. Section 4 of both is
explicit that the structural vocabulary they carry consists of *"architectural facts, not legal
rules"* — and that distinction is what makes M4 possible at all.

M4 may build the mechanism from `03_DATABASE_ERD.md` section 11: `workflow_templates`,
`workflow_stages`, `matter_workflows`, `matter_stage_instances`, `matter_stage_history`, the
current-stage pointer, transition recording, and stage history. **Snapshotting is the point, not
decoration** — `CLAUDE.md` section 18 requires that editing a template must not retroactively
change a Matter already running, which is why stage instances carry `stage_code` and both snapshot
names, and why `matter_workflows` records the version it was instantiated from.

M4 must **not** seed or infer: Notary or PPAT stage sequences, default templates, approval points,
required-before-stage rules, tax gating, deed gating, legal completion conditions, service catalogue
content, or responsible-role-per-stage rules.

**A configurable engine shipped with no content is the correct outcome and is stated plainly rather
than presented as a limitation: the office's actual workflow is blocked on domain validation, not
on engineering.** When a qualified domain source completes the two workflow documents, the content
becomes configuration entered through the master-data surfaces, and no schema change should be
required to accept it.

**Matter Status and Workflow Stage remain separate concepts.** M4 authorizes who may change a stage
and does not encode which stage may follow which.

**Whether a stage transition carries legal state is undecided and recorded as such.** If a
transition ever gates deed finalization, its immutability and audit requirements are stricter than
an operational status field's, so `matter_stage_history` is treated as append-only from the outset
— the safe direction to be wrong in.

### D-105 — Matter participation is independent of Project participation

`matter_parties` is **independent of `project_parties`**: not inherited, not copied, not
synchronized. Project participants may later serve as **candidate context** when adding a Matter
participant — a convenience for whoever is typing, not a data relationship. Two tables that
silently mirror each other drift apart, and the drift is found by somebody reading the wrong one.

**The same-Office invariant is structural:**

```text
matter_parties.office_id  ->  matters (id, office_id)
                          ->  parties (id, office_id)
```

Two composite foreign keys through **one** carrier column, so both endpoints must agree with it and
therefore with each other. **A cross-office Matter participation is unrepresentable, including for
an actor holding `ALL`** — `ALL` grants reach and administrative visibility, never permission to
redefine domain ownership. `03_DATABASE_ERD.md` lists `matter_parties` without `office_id`, exactly
as it listed `project_parties` without one; the carrier is a **recorded departure**, not an
oversight.

**Four permissions are expected at M4.5, not at M4.0:**

```text
notary.matters.parties.view     notary.matters.parties.manage
ppat.matters.parties.view       ppat.matters.parties.manage
173 -> 177
```

Four rather than two, because the domain split is real: `02_MENU_AND_PERMISSIONS.md` section 5 gives
Notary Staff full access to Notary Matters and view-only on PPAT Matters, and the reverse for PPAT
Staff, so a single pair spanning both domains would hand each of them the other's participation.
**`view` and `manage` are independent and `manage` does not imply `view`** (D-098): a silently
implied capability is one nobody configured and nobody can revoke. Neither is reached by
`notary.matters.update` or `ppat.matters.update`. **The count moves when the milestone that gives
them routes registers them**, following the M3.4 precedent.

**Deferred, and each for a stated reason:**

- **`represented_by_party_id` — DOMAIN VALIDATION REQUIRED.** A Party acting through another Party
  is representation, proxy, or legal capacity; which of those it means, when it is permitted, and
  what it implies for a deed are legal questions with no canonical answer here. Guessing would
  invent an Indonesian notarial rule (`CLAUDE.md` section 62).
- **`sequence_no` — semantics unvalidated.** Display order, signing order, legal priority and
  appearance order are four different things and the column name distinguishes none of them. A
  wrong guess is invisible until a deed is drafted from it.

**`role_code` stays nullable and opaque** — no enum, no `Rule::in`, no `CHECK`. The ERD's `SELLER`,
`BUYER`, `SELLER_SPOUSE`, `DIRECTOR`, `COMMISSIONER` and `WITNESS` are labelled **example role
codes**; constraining the column would turn examples into the catalogue the document says they are
not (D-092, D-098). **No cardinality rule is invented** — not a mandatory role, not a required
seller, not an exactly-one anything.

**Participation is current working state, not historical legal participation.** No effective
periods, no historical versioning, no soft delete, no legal audit history. `company_people` keeps
history because deeds executed in March depend on who was a director in March (D-083); nothing yet
depends on a Matter's participant list as it stood last week. Correction semantics, if ever needed,
must be designed explicitly in the milestone that needs them.

**Sensitive identity never enters the Matter domain.** No NIK, NPWP or tax identifier in `matters`,
`matter_parties`, workflow templates, stages, stage instances, stage history, browser storage, URLs,
query keys, or logs (D-082). Participation exposure follows the M3.4 minimal-stub pattern with a
`can_view_party` computed from canonical Party and Company visibility per subtype, **no masks**, and
**bulk evaluation rather than per row** — the N+1 M2.6 measured and M3.4 avoided by construction.
**Free-text audit fields are a leak surface**: `matter_stage_history.reason` and its like must never
persist Party identity.

### M4 implementation order

```text
M4.0   Matter / Workflow architecture lock        <- this checkpoint
M4.1   Service Types + master-data foundation
M4.2   Matter schema + authorization foundation
M4.3   Matter internal reference foundation
M4.4   Matter core management
M4.5   Matter <-> Party participation
M4.6   Workflow templates + stages
M4.7   Matter workflow instances + stage transitions
M4.8   M4 quality gate
```

**M4.1 precedes M4.2** because `matters.service_type_id` references it, even nullably, and a foreign
key cannot point at a table that does not exist. **M4.2 is schema, Policy, predicates, constraints
and architecture tests — not CRUD UI**, following the M2.1 and M3.1 precedent. **M4.3 owns the
allocator**, and the column and its allocator arrive together (D-095). **M4.5 is expected to move
the count 173 → 177.** **M4.6 and M4.7 build mechanism only.**

**Notary and PPAT legal outputs begin at M6 and M7**, each with their own domain content.

---

## 2026-08-17 — M4.1 Service Type master-data foundation

### D-106 — Service Types are Office-owned reference data, retired rather than deleted

One forward migration (23 total) and **no permission — the count stays at 173**; both
`master.services.view` and `master.services.manage` were already canonical. **Backend foundation
only**: no route, controller, request, resource, frontend page, or navigation entry, following the
M2.1 and M3.1 precedent.

**Office-owned, not deployment-global.** `03_DATABASE_ERD.md` section 8 gives `service_types` an
`office_id`, and the genuinely global tables — roles, permissions — carry none. So the
`allowsGlobally()` pattern D-044 built for Role definitions does **not** apply here: two Offices
each maintain their own catalogue, and the same service exists as two rows.

**Data Scope is `OFFICE` and `ALL` only** — the Party answer (D-080) rather than the Project one
(D-088), because the reasoning that separated them applies again. `OWN` would have to mean
`created_by`, and the table deliberately has no such column: a Service Type is a **shared reference
record**, and the colleague who typed it in has no claim on the service the office offers.
`ASSIGNED` has no assignment entity — nobody is the PIC of a catalogue entry. `TEAM` has no Team
entity (D-042).

`PermissionScopeRules` offers exactly the two scopes the visibility class can honour, so an
administrator cannot grant `master.services.view` at `OWN`, see it saved, and receive a silently
powerless grant — the dead control D-080 named. **Only the Service Type family is narrowed**; the
other twelve `master.*` families keep the permissive default, because their domains are still
undesigned and narrowing them would repeat the mistake this entry corrects, one module across.

**`view` and `manage` are independent, and `manage` does not imply `view`** (D-098's answer): the
registry defines two codes, so an administrator who wants both grants both.

**Creation always lands in the actor's own Office, including for an actor holding `ALL`.** `ALL` is
reach over records that already exist, never authority to decide which Office a new one belongs
to — the same line D-098 drew for participation.

**Office, `code` and `domain` are identity, not content**, and the model refuses to change any of
them after creation. Other records classify themselves by all three, so rewriting one silently
redefines what they mean: `code` is the handle, `domain` decides which Matter surface may offer
the service at all (D-101), and Office is the security boundary. Both names, both descriptions,
`sort_order` and `default_duration_days` are ordinary content an office may correct.

`code` is a **stable classification handle, never an internal reference and never legal numbering**
(D-103). It is stored exactly as submitted, with **no case normalization**, because no canonical
document defines one and inventing a rule would silently decide whether `AJB` and `ajb` are the
same code. `UNIQUE (office_id, code)` is composite and never global — the O-023 shape, reached for
the same reason — and **`domain` is deliberately outside that namespace**, so one code cannot mean
two things inside one Office.

**`UNIQUE (id, office_id)` is added now, ahead of its use.** M4.2's `matters.service_type_id` is
intended to carry the same-Office guarantee structurally through `(service_type_id, office_id) ->
service_types (id, office_id)`, and the support key is one index today versus a second migration
later. This is a deliberate exception to "add nothing on speculation": the shape is already fixed
by D-105 and by the `company_people` (D-080) and `project_parties` (D-098) precedents, and
`projects` gained its equivalent at M3.4 for exactly this reason.

**Retirement is `is_active`, and there is no other lifecycle** — no delete, no soft delete, no
archive, no restore. The ERD lists `is_active` and no `deleted_at`; the `offices` migration set the
precedent in the same words; and the registry offers no code that could authorize a deletion. It is
also the only choice that survives M4.2: a Matter referencing a deleted Service Type would lose the
classification a historical record depends on, which `CLAUDE.md` section 63 forbids. **An inactive
entry stays readable and keeps every existing reference intact** — inactive means unavailable for
new selection, never erased from history — and the future Matter foreign key must therefore be
restrictive and **never `SET NULL`**.

**`legal_term` and `preserve_legal_term` are withheld.** They appear in the ERD field list and are
defined nowhere else in the repository, while a separate `legal_terms` table carries its own
`preserve_original_term` concept — a foreign key, a free-text term, and a display-fallback flag are
all plausible readings. Withheld until validated, exactly as M3.1 withheld `project_number` until
its construction was settled (D-095, D-086).

**Zero production rows, and fixtures that cannot be mistaken for a catalogue.** No validated
service catalogue exists (D-102), so nothing seeds one and the factory emits `UJI_` codes and
`Layanan Uji` names rather than plausible legal services somebody could later copy into a seeder.

**Verified on real PostgreSQL, and it caught a false claim.** The migration originally documented
`unsignedInteger` as making `default_duration_days` non-negative. **PostgreSQL has no unsigned
integer type and silently maps it to `integer`** — proven by inserting `-1`, which the database
accepted. Non-negativity is now enforced by an explicit CHECK beside the domain one, and the
comment says what is actually true.

---

## 2026-08-17 — M4.2 Matter schema and authorization foundation

### D-107 — The Matter root, its two structural invariants, and where the domain comes from

One forward migration (24 total) and **no permission — the count stays at 173**; the sixteen
Matter codes were already canonical. **Backend foundation only**: no route, controller, request,
resource, frontend page, or navigation entry, following M2.1, M3.1 and M4.1.

**Two invariants are structural rather than validated**, because a rule the database cannot
express is one somebody eventually routes around:

```text
matters (project_id, office_id)              -> projects (id, office_id)
matters (service_type_id, office_id, domain) -> service_types (id, office_id, domain)
```

The first makes a Matter whose Office disagrees with its Project's unrepresentable — the Office is
**inherited from the parent** (D-099), never caller-selected. The second does **two jobs with one
key**: same Office *and* same domain, so a Notary Matter classified with a PPAT service cannot
exist. That required adding `UNIQUE (id, office_id, domain)` to `service_types`, because M4.1
shipped only `(id, office_id)` and a composite foreign key needs a unique index on exactly the
referenced columns. `service_type_id` stays nullable and PostgreSQL treats a composite key with a
NULL component as satisfied, so a Matter with no Service Type remains valid (D-102). Never
`SET NULL`: erasing a classification because a catalogue was tidied would lose data a historical
record depends on.

`matters` also gains `UNIQUE (id, office_id)`, the support key M4.5's `matter_parties` will
reference — the M4.1 pattern, one index now against a second migration later.

**Deferred and deliberately not stubbed.** `matter_number` belongs to M4.3 *with* its allocator
(D-095's rule, proven twice now), and `current_stage_id` to M4.7 *with* the real stage-instance
foreign key. A nullable placeholder for either would be a column somebody fills in wrongly or a
pointer validated by nothing.

**`deleted_at` is reserved schema capability and the model uses no `SoftDeletes`.** The column
exists because the ERD carries it; the trait would install a global scope silently filtering every
query — including `MatterVisibility` — making "invisible because soft-deleted" indistinguishable
from "unreachable by scope", and settling visibility semantics before the milestone that owns
archiving exists to settle them. `ARCHIVED` remains a **business status**, never soft deletion.

**Matter Data Scope** is the four D-100 predicates: `OWN` = `created_by`, `ASSIGNED` =
`pic_user_id`, `OFFICE` = `office_id`, `ALL` = cross-office reach, `TEAM` nothing. Fourteen
actionable codes get that scope set; **`view_all` is excluded from the rules and consulted by no
ability** (D-090). Two branches must never enter `MatterVisibility` and both are pinned by source
guards: a parent-Project join, which would make Project reach a silent superset of Matter reach,
and a stage-assignment branch, which would widen `ASSIGNED` for every role already holding it.

**The domain comes from the caller, never from the row.** There is one `MatterPolicy`, and every
ability takes an explicit `MatterDomain` that selects the permission namespace. Reading
`$matter->domain` to choose the permission would be the new authorization shape the M3 lock
flagged; route-derived namespacing keeps the question ordinary. A **separate** rule keeps the row
honest — the supplied domain must equal the persisted one, or the ability refuses — and at M4.4 the
route binding turns that mismatch into the canonical 404 (D-101). The two answer different
questions, and collapsing them would reinstate the row-derived namespace by the back door.

Eight abilities, each answering to its own code, **none implying another**: `update` does not reach
assignment, `assign` does not reach update, `change_stage` does not imply `complete`, `complete`
does not imply `cancel`. No umbrella `manage` code, and no archive, restore, or delete.

**Creation requires four things**, and the third is the one worth stating: the domain's own
`create` code at a scope that can describe a record about to exist (`OWN`, `OFFICE`, `ALL` —
`ASSIGNED` cannot, because a new Matter has no PIC); **`projects.view` on the parent**, which is
the minimum coherent proof somebody may open work beneath it and is the *only* place Matter
authorization consults the parent (D-100 keeps them independent everywhere else); **the parent in
the actor's own Office, refused even at `ALL`**, because `ALL` is cross-office reach over existing
Matters and not authority to file new work elsewhere (D-097's ruling, one domain across); and a
Project that is **not archived**, which falls out of using the canonical reach check rather than a
separate lookup.

**Same-Office PIC is locked and enforced at M4.4**, where the assignment surface lives: `ASSIGNED`
grants reach when `pic_user_id == actor.id`, so a cross-office assignment would hand somebody reach
their scope never included. No `(pic_user_id, office_id)` composite key is added here — `users`
carries no matching support key, and building one for an invariant another milestone owns would be
construction ahead of requirement.

**`MatterDomain` is its own enum**, not a reuse of `ServiceTypeDomain`: Matter is not a master-data
concept, and naming its domain after the Service Type type would make the aggregate depend on a
master-data detail. A parity test keeps the two lists identical so a divergence must be deliberate.
**`priority` reuses `ProjectPriority`**, because that enum already records that the ERD names the
column on projects, matters and tasks and defines the vocabulary exactly once — one vocabulary, one
enum, and no refactor of accepted M3 ownership for naming elegance.

---

## 2026-08-17 — M4.3 Matter internal reference foundation

### D-108 — A dedicated Matter allocator over an Office, year, and domain namespace

One forward migration (25 total) and **no permission — the count stays at 173**. Reference
allocation is system-controlled infrastructure, not a user capability, so there is no
`matters.number`, `matters.allocate`, or `matters.reference` code and no route that could write
one. **Backend foundation only.**

```text
N-YYYY-NNNNNN     Notary
P-YYYY-NNNNNN     PPAT
```

**Ordinary office identification and nothing more** — not a deed number, a repertorium number, a
minuta or Warkah number, a PPAT register entry, or a land or government registration number. The
`N` and `P` prefixes carry no legal meaning.

**Three namespace dimensions: Office + calendar year + domain.** Project counts per Office and
year; Matter adds the domain, because a shared counter would make `N-2026-000001` and
`P-2026-000001` compete for one value. Office A's Notary and PPAT sequences, Office B's Notary
sequence, and Office A's next-year sequence are four independent counters, each starting at 1.

**A dedicated counter table, `matter_reference_counters`**, with the natural composite primary key
`(office_id, reference_year, domain)` and no ULID surrogate — allocator infrastructure is not a
business-domain entity. `office_id` cascades on delete, following the Project counter: a counter
row is infrastructure, not work. **The M3.2 allocator is reused as a pattern, never as a table**;
`13_M3_PROJECT_ARCHITECTURE.md` section 9 refused to generalize it into anything Matter-shaped,
and the generic configurable numbering engine `03_DATABASE_ERD.md` section 27 sketches — prefix
patterns, monthly resets, `master.numbering.*` — is deliberately not used.

**One atomic statement, no read-then-write:** `INSERT … ON CONFLICT (office_id, reference_year,
domain) DO UPDATE SET last_value = last_value + 1 RETURNING last_value`. The increment happens
inside the database against a row the engine locks for the duration of the upsert, so two
concurrent callers cannot both compute the same value — neither computes it at all. `MAX+1`,
`COUNT+1`, `latest()+1` and read-then-write are forbidden, and a transaction alone would not fix a
`SELECT`-then-`UPDATE` because under `READ COMMITTED` two transactions can both read before either
writes. Identical SQL on PostgreSQL and SQLite 3.35+, so there is one execution path; **concurrency
evidence is taken on PostgreSQL only** — 16 simultaneous OS processes, 400 allocations in one
namespace, every value distinct, contiguous 1–400, the counter landing exactly on 400, and the
other three namespaces untouched.

**The allocator opens no transaction of its own** and commits nothing, so it participates in the
caller's. M4.4 will allocate and insert inside one transaction, matching `CreateProject`. The
consequence, stated rather than hidden: the counter row stays locked from allocation until that
transaction ends, serialising concurrent creates *within one Office-year-domain* for the duration
of a single insert — and the namespace split means Notary and PPAT creates never block each other.

**Gaps are acceptable, and the distinction is precise.** If allocation and insert share a
transaction that rolls back, the counter increment rolls back with it and the number is **not**
lost — proven by test. If an allocation **commits** and is then not used, the number is permanently
skipped. Nothing may treat the sequence as a record count, and sequential appearance carries no
legal weight.

**The year comes from the application clock** (`Date::now()`), never from a request body, browser,
locale, Matter or Project date, or a value parsed back out of an existing reference. **No
Office-timezone semantics** were invented — `offices.timezone` exists but no code reads it, and
doing so here would create a concept the repository does not have. Rollover is proven with a frozen
clock.

**Six digits are a minimum, not a maximum.** The 1 000 000th reference in one namespace formats as
seven digits rather than wrapping to `000000` or truncating — either of which would silently break
uniqueness, the one property an identifier may not lose. `varchar(32)` is sized for it. This is the
M3.2 rule adopted verbatim.

**Uniqueness is `(office_id, matter_number)`, and `domain` is deliberately absent from it.** The
formatted string already begins with `N-` or `P-`, so the two domains cannot collide as strings;
adding `domain` would widen the index without excluding anything and would permit `N-2026-000001`
to exist twice in one Office if the domains differed, which the prefix makes nonsense. Never
global: two Offices may both hold `N-2026-000001`.

**A nullable-aware database CHECK enforces prefix–domain agreement** — a NOTARY Matter may not
carry a `P-` reference and vice versa — and only that. Full format correctness stays in
`MatterReference`, the only thing that ever constructs a reference; turning PostgreSQL into a
second parser would duplicate the rule in a language where it is harder to read and change.

**Two counter CHECKs exist because Laravel's unsigned types do not.** `unsignedSmallInteger` and
`unsignedInteger` are MySQL concepts; PostgreSQL has no unsigned integer type and silently maps
both to signed columns, so `reference_year >= 0` and `last_value >= 0` are the constraints that
actually enforce what the schema claims. This is the M4.1 `default_duration_days` lesson applied
before it could bite.

**`matter_number` is nullable in M4.3**, exactly as `project_number` was at M3.2 and for the same
reason: no creation path allocates yet, so `NOT NULL` would make Matter unwritable for a whole
milestone including by its own factory. M4.4 integrates allocation into `CreateMatter` and may then
tighten by forward migration. **Nothing was backfilled and nothing invented** — the persistent
development database was inspected and holds no `matters` table at all, so no Matter row has ever
existed outside an in-memory test or a disposable verification database. Had rows existed, the
correct action was to stop and report, because inventing a historical reference is the `MAX+1`
guessing this decision forbids.

**Immutable once the row exists, and stricter than Project's guard.** `null → value`,
`value → other value`, and `value → null` are all refused. The Project guard had to permit
`null → reference` while M3.2's column was nullable; Matter can start strict because its create
path does not exist yet to have relied on the looser rule, and M4.4 will stamp inside the creating
transaction rather than numbering a Matter afterwards. The column is withheld from mass assignment,
and the guard fires on `updating` only, so it never blocks the stamp itself.

**`MatterReference` is a formatter, not a parser.** It exposes exactly `prefix`, `format`, and
`matchesFormat`, allocates nothing, and reads no database. Nothing may read the year, sequence, or
domain back out of a formatted reference — that would make displayed text an input to logic. The
prefix map lives here rather than on `MatterDomain`, keeping an authorization type free of
presentation concerns.

---

### D-109 — Matter core management: the domain comes from the route, the Office from the Project, and there is no status control

One forward migration (**26 total**) and **no permission — the count stays at 173**. The migration
adds no column and no table: it tightens `matters.matter_number` from nullable to `NOT NULL`, which
D-108 scheduled for the milestone that gives Matter a creation path. Backend and frontend both.

**Eighteen routes, nine per domain, and the pair is generated from one array** rather than written
twice:

```text
GET    /api/v1/{domain}/matters
POST   /api/v1/{domain}/matters
GET    /api/v1/{domain}/matters/service-type-options
GET    /api/v1/{domain}/matters/{matter}
PATCH  /api/v1/{domain}/matters/{matter}
PATCH  /api/v1/{domain}/matters/{matter}/assignment
GET    /api/v1/{domain}/matters/{matter}/assignment/options
POST   /api/v1/{domain}/matters/{matter}/complete
POST   /api/v1/{domain}/matters/{matter}/cancel
```

`{domain}` is `notary` or `ppat` — a literal segment in each registered route, never a wildcard.
Two domains that shared one `/api/v1/matters` prefix would have to read the domain from somewhere,
and every available somewhere is worse than the URL.

**The domain is a route default, read explicitly, and it decides the permission namespace.** This
is D-101 applied to Matter, and the mechanism matters as much as the rule. Each route carries
`->defaults('domain', 'NOTARY'|'PPAT')`, and `ResolvesMatterDomain` reads it back off
`$request->route()`. It is **not** taken as a controller argument: Laravel fills non-model
parameters positionally, and during implementation that handed `show()` the Matter id where the
domain belonged — a silent mis-binding that a typed argument did nothing to prevent. Reading the
default by name is order-independent, and a route that declares none throws rather than guessing a
domain. Nothing reads the domain from a request body, a Project, or the Matter row: a caller
holding only `ppat.matters.view` must not reach a Notary Matter by addressing a shared endpoint,
and a caller must never be able to move which permission guards a request by changing data.

**A Matter of the other domain answers 404, not 403.** Resolution is domain-constrained before
authorization has anything to say, so a Notary address handed a PPAT id behaves as though the
record is not there. A 403 would confirm the existence of a record in a domain the caller never
named, turning the endpoint into an existence oracle across the Notary/PPAT boundary that
`CLAUDE.md` section 16 draws.

**Seven fields are system-controlled at creation and none of them is a request field.**
`project_id` comes from the validated body; `office_id`, `domain`, `matter_number`, `status`,
`pic_user_id` and the reference allocation are decided by `CreateMatter`. The Form Requests mark
the system-controlled names `prohibited` **and** refuse them on presence, so sending
`office_id` is a 422 rather than a silently ignored field — an ignored field teaches a caller that
it works.

**The Office is inherited from the parent Project, not from the actor.** `CreateMatter` reads
`$project->office_id`. Taking it from the creating user would let somebody with cross-Office reach
create a Matter in Office A underneath a Project in Office B, breaking the composite-foreign-key
invariant D-107 exists to make structurally impossible. The Project is the only correct source
because the Project already *is* the Office context.

**Allocation happens inside the creating transaction**, exactly as D-108 anticipated: allocate,
then insert, one transaction. A rollback takes the counter increment with it.

**Service Type selection is validated as one indistinguishable 422.** Wrong Office, wrong domain,
retired, or nonexistent all produce the same field error. Distinguishing them would let a caller
enumerate another Office's reference data through the error message. The same rule governs
assignment: an inactive user, a user of another Office, and a nonexistent user are one message.

**`service-type-options` is authorized by the Matter capability alone.** `viewAny` on Matter for the
route's domain opens it, and the list is filtered to the actor's own Office, the route's domain, and
active rows. Requiring a separate `master.service_types.view` would mean an account that may create
Matters could not see what to create them as; adding a new permission for a picker would be a
permission for a widget rather than for records. The Office filter is the actor's own because a
Matter is created under a Project the actor can already reach.

**Assignment sends `pic_user_id` as `present` and `nullable`.** Null means unassign, absent means
the caller sent a malformed request. `present` + `nullable` is the only combination that separates
those two, and treating them the same would make "unassign" and "forgot the field" identical.

**There is no status control, and this is a known, accepted gap.** The canonical registry gives
Matter `complete` and `cancel` and **no `change_status`**, unlike Project. So `OPEN`, `COMPLETED`
and `CANCELLED` are the only reachable states in M4; `IN_PROGRESS`, `WAITING`, `ON_HOLD` and
`ARCHIVED` remain in the enum as vocabulary a filter can select on and a badge can render, and
nothing in the product can set them. **No status dropdown exists anywhere in the Matter interface**
— the alternative was to invent a `matters.change_status` capability, which would be inventing an
authorization surface the registry does not define, exactly what section 62 forbids. The interface
says what it can do rather than offering a control that would 403 or, worse, one backed by a
permission nobody decided to grant. Revisit when M4.5 gives Matter a workflow, which is where
intermediate states properly come from.

**`complete` stamps `completed_at`; `cancel` stamps nothing.** This departs from `ChangeProjectStatus`,
which deliberately records no timestamp, and the difference is the point: `complete` is a named
lifecycle act with a moment attached, while `cancel` records only that the Matter stopped. No
cancellation reason, no timestamp, and **no history table** — a lifecycle history is a real design
with real questions (who, when, why, and whether it is the audit log's job) and M4.4 does not get to
answer them in passing.

**Nothing about participation, workflow, deeds, or archiving exists.** No Matter party pivot, no
workflow instance, no stage, no `archive`/`restore` pair. `matters.change_stage` is registered and
**deferred**, listed as such by `PermissionController` so the Permission Matrix shows it as not yet
implemented rather than as a working capability.

**Frontend: eight routes, two navigation entries, 75 message keys per locale.** `/{locale}/notary/matters`
and `/{locale}/ppat/matters`, each with `new`, `[id]`, and `[id]/edit`. Navigation gains **Notary**
and **PPAT** groups carrying Matters and nothing else — Deeds, Minuta, Warkah and registers belong
to M6 and M7 and are absent rather than shown dark, since a group whose every child is unreachable
is a promise the product does not keep. Each entry is gated on its own `*.matters.view` code, never
on a shared one, because the two capabilities are independent. Message keys reach exact parity at
810 per locale.

**The one query-key rule worth stating: keys are domain-first.** `['matters', domain, …]`, so a
Notary list and a PPAT list can never share a cache entry. A domain-last key would let TanStack
Query serve one domain's rows under the other's address.

---

## 2026-08-20 — M4.5 Matter ↔ Party participation

### D-110 — Matter participation invents no cardinality, and the Party-visibility rule is written once

One forward migration (**27 total**) and **four permissions — the count moves 173 → 177**, exactly
as D-105 scheduled at M4.0 and in the milestone that gives them routes, following the M3.4
precedent. Backend and frontend both.

```text
GET    /api/v1/{domain}/matters/{matter}/party-options
GET    /api/v1/{domain}/matters/{matter}/parties
POST   /api/v1/{domain}/matters/{matter}/parties
PATCH  /api/v1/{domain}/matters/{matter}/parties/{matterParty}
DELETE /api/v1/{domain}/matters/{matter}/parties/{matterParty}
```

Five per domain, ten in total, nested under the Matter because that is what owns them. There is
deliberately **no top-level `/matter-parties` collection**: a participation is reachable only by
naming the Matter it belongs to, and a foreign participation answers **404**, not 403.

`{domain}` stays a **literal** segment carrying a route default, and the domain is read back by
name (D-101, D-109). A `{domain}` wildcard would put the permission namespace under the caller's
control.

**No `UNIQUE (matter_id, party_id)`, and this is the decision the milestone turned on.** The M4.5
specification asked for one; D-105 and the `project_parties` migration both refuse the equivalent,
and the refusal wins. Such an index asserts that one Party holds **at most one role** in a Matter —
and an Indonesian notarial or PPAT matter may legitimately need the same person as `SELLER` in
their own right and as `AUTHORIZED_PERSON` for somebody else. Whether that is permitted is a
domain question with no canonical answer here, and *a unique index is a business rule wearing an
index's clothing*. No `UNIQUE (matter_id, party_id, role_code)` either: it would assert the triple
is the identity, and it would additionally be meaningless while `role_code` is nullable. A test
pins the behaviour rather than the index name. **If the office later decides duplicates are wrong,
that is a rule to state and validate, not a constraint to add quietly.**

**The same-Office invariant is structural, and this migration adds no support key.** Two composite
foreign keys — `(matter_id, office_id) -> matters (id, office_id)` and
`(party_id, office_id) -> parties (id, office_id)` — resolve through **one** `office_id` carrier,
so both endpoints must agree with it and therefore with each other. A cross-office participation is
unrepresentable, **including for an actor holding `ALL`**: `ALL` grants reach and administrative
visibility, never permission to redefine domain ownership. Unlike M3.4, which had to add
`projects_id_office_id_unique`, both support keys already existed — `parties` since M2.1 and
`matters` since M4.2, which added `matters_id_office_id_unique` for precisely this table. So the
migration adds none and drops none on the way back down.

**The Office carrier is written from the Matter and is never request input.** `office_id` is
withheld from mass assignment alongside `matter_id` and `party_id`; the Form Requests refuse all
three on *presence*, not emptiness (D-097).

**`role_code` stays nullable, opaque, and `varchar(30)`.** No enum, no `Rule::in`, no `CHECK`, and
**no dropdown in the interface** — the ERD's `SELLER`, `BUYER`, `SELLER_SPOUSE`,
`AUTHORIZED_PERSON`, `WITNESS`, `DIRECTOR`, `COMMISSIONER` and `SHAREHOLDER` are labelled *example*
codes, and constraining the column would turn examples into the catalogue the document says they
are not. 30 characters matches `project_parties`; no canonical length exists, and two
participation tables disagreeing about it would be an arbitrary difference to explain later. **No
cardinality rule of any kind** — not a mandatory role, not a required seller, not an exactly-one
anything.

**The column set is transcribed, not designed.** `notes` and `updated_at` are present because
`03_DATABASE_ERD.md` section 9 lists them for `matter_parties`; `is_primary` is absent because that
section does not list it, even though `project_parties` has one. There is no `updated_by`, so a
correction records *when* it happened and never *who* made it — which is what the canonical field
list asks for, and inventing the missing half would be the first step of a ledger this table
declines to be.

**Current working state, not history.** No `deleted_at`, no `effective_from`, no `effective_until`.
Removal is a hard delete of the relationship row: the Matter is untouched, the Party is untouched,
and neither is archived. `company_people` keeps history because deeds executed in March depend on
who was a director in March (D-083); nothing yet depends on a Matter's participant list as it stood
last week. A soft delete here would create a half-history — rows nobody lists and no mechanism
reads — and the confirmation dialog says plainly that there is nothing to restore from.

**`sequence_no` and `represented_by_party_id` are refused rather than ignored.** Both are deferred
pending domain validation (D-105), and the Form Requests list them as prohibited so sending one is
a 422. Accepting and dropping them would teach a caller that the fields work.

**`ProjectParticipantVisibility` became `ParticipantVisibility`, keyed on an Office id.** Every
question it answers — bulk `can_view_party`, the candidate query, re-resolving a submitted
`party_id` — depends on an Office and a Party subtype; the parent record contributed nothing but
`office_id`. Copying ~130 lines of security-critical code for the Matter domain would have created
two implementations of the `parties.view` / `companies.view` rule, and **two copies of a security
check drift silently** — one domain gains a fix the other does not, and nothing announces it. D-105
keeps `matter_parties` independent of `project_parties` **as data**, which is a statement about
tables and rows; it is not an instruction to re-implement the Party permission rule twice. M3.4's
call sites moved to the shared class in the same change.

**Managing participation is never authority to discover Parties.** `*.matters.parties.manage` over
the Matter is necessary but not sufficient: the candidate query additionally applies `parties.view`
to Individuals and `companies.view` to Companies, **each at its own Data Scope and each
independently**, so an actor holding one and not the other sees only that branch, and one holding
neither gets an empty list rather than the whole Office. A submitted `party_id` is re-resolved
through that same authorized query. Nonexistent, another Office, archived, and a subtype the actor
cannot see produce **one indistinguishable 422**.

**`view` and `manage` are independent in both directions.** `manage` does not imply `view` — the
direction that matters more, since an actor who may edit the list is not thereby authorized to read
it — and `view` does not imply `manage`. `*.matters.update` reaches neither. Four codes rather than
two because the section 5 role matrix gives Notary Staff and PPAT Staff opposite reach across the
two domains. There is no `*.matters.parties.view_all`: reach is Data Scope `ALL` against the parent
Matter (D-090).

**Party visibility is evaluated in bulk**, two queries at most, one per subtype branch — and the
test measures it as a **comparison between two list sizes** rather than against a guessed
threshold, because the property D-105 requires is that the count does not grow with the rows.

**No Party identity anywhere.** The stub is `id`, `display_name`, `party_type`, `is_archived`,
`can_view_party` and nothing else: no NIK, no NPWP, no `tax_id`, and **no masks**, since a mask is
still a statement about a sensitive value. A Party the actor cannot open **still appears** as a
stub with `can_view_party = false` — hiding it would misreport the Matter's composition to somebody
authorized to read it — and an archived Party stays listed, marked archived, and is simply not
offered as a candidate.

**Independent of Project participation, and nothing bridges them.** Nothing reads `project_parties`,
no column points either way, and the parent Project's participants are **not** offered as
candidates. Offering them would be the first step toward two tables that silently mirror each other
and then drift.

**Frontend: a section on the Matter detail page, not a tab.** It follows the Project precedent
exactly and renders only when `can_view_parties` is true. `matters` gains `can_view_parties` and
`can_manage_parties` — two flags, because the two codes are independent. Query keys stay
domain-first: `['matters', domain, 'detail', matterId, 'parties']`. Message keys reach exact parity
at 848 per locale, 38 new in a `matterParties` namespace.

---

## 2026-08-20 — M4.6 Workflow templates and stages

### D-111 — A workflow version is a counter, not a second row, and an approval permission is validated at rest

One forward migration (**28 total**) creating `workflow_templates` and `workflow_stages`, and
**no permission — the count stays at 177**. `master.workflows.view` and `master.workflows.manage`
were already canonical; M4.6 narrows their assignable Data Scopes and registers nothing.
**Backend foundation only**: no route, controller, request, resource, seeder, or frontend, following
M2.1, M3.1, M4.1 and M4.2.

**Both tables ship empty and stay empty.** D-104 permits the mechanism and forbids the content, and
nothing here seeds or infers a Notary or PPAT stage sequence, a default template, an approval point,
a required-before-stage rule, tax or deed gating, or a legal completion condition. A configurable
engine with no content is the correct outcome: the office's real workflow is blocked on domain
validation, not on engineering.

### The version question, which the specification contradicted itself on

The M4.6 brief required `UNIQUE (office_id, code)` **and** stated that a template may have several
versions. Those cannot both hold — two rows sharing a code violate that key.

**One row per code, and `version` is a counter on it.** Editing a template raises it in place; there
is no second row for the older iteration and none is wanted. The ERD is what settles it: it gives
`matter_workflows` **both** `workflow_template_id` *and* `workflow_version`. Under the alternative —
a frozen row per version — the foreign key alone would identify the iteration and `workflow_version`
would be redundant with it. Carrying both only makes sense if the id says *which template* and the
number says *which iteration of it*.

What preserves the old iteration is not an old row but **M4.7's snapshot**: `stage_code` plus both
snapshot names on every stage instance. `CLAUDE.md` section 18 requires that editing a template
never retroactively change a Matter already running, and a snapshot is what guarantees that — a
surviving row would not, since nothing stops an administrator editing it too.

`office_id` and `code` are immutable on the model, following `ServiceType`: they are identity, and
other configuration refers to a template by them. **`version` is deliberately outside that set** —
bumping it is the ordinary act of editing.

### `approval_permission` is validated where it is written

The column stores a permission code as data, which is an authorization surface configured by text.
**A value that is not a canonical permission code is refused on save.** Left open, a typo or a
renamed code would sit in the table until M4.7 tried to resolve it and had to decide at runtime what
an unknown string means — and "unknown" is exactly the case where inventing a meaning is most
dangerous. Validating at the point of writing means the question never arises.

Storing a code authorizes nothing by itself. Whatever reads it must still go through a Policy and
`EffectiveAccessResolver` with the actor's Data Scope, like every other decision (D-048). This
column names *which* capability a stage asks for; it never answers whether somebody has it. `null`
remains ordinary — `requires_approval` alone is a meaningful state, since an office may know a step
needs signing off before it knows which capability should gate it.

### Same-Office binding is structural

```text
workflow_templates (service_type_id, office_id) -> service_types (id, office_id)
```

Office A's template cannot bind Office B's service, because both endpoints resolve through this
table's own `office_id` — the construction `company_people` (D-080), `project_parties` (D-098),
`matters` (D-107) and `matter_parties` (D-105) all use. `service_types` has carried the matching
`UNIQUE (id, office_id)` support key since M4.1, which added it in anticipation of exactly this, so
**this migration adds no support key and drops none on rollback**. A composite key with a NULL
component is satisfied, so a generic template — `service_type_id` null — stays valid, which matters
because M4.1 ships the catalogue empty on purpose.

`workflow_templates` gains its own `UNIQUE (id, office_id)` for M4.7, so a Matter cannot run another
Office's template.

### What is not constrained, and why

**`is_default` carries no cardinality rule.** Several templates may be default at once and none has
to be, following `project_parties.is_primary` (D-092) and D-105. No canonical document says
otherwise, and a partial unique index would be a business rule nobody wrote — which additionally
does not exist on the SQLite test connection, so the two engines would disagree about what is
representable. **The consequence is M4.7's to carry: it must choose deterministically and say how**,
rather than assuming the database handed it exactly one.

**`sequence_no` on `workflow_stages` is unique per template, and this is not the invented-rule
trap.** D-105 deferred `matter_parties.sequence_no` because four plausible meanings competed —
display order, signing order, legal priority, order of appearance. Here the meaning is settled and
structural: the order the engine reads stages in. Two stages claiming position 3 leave "what comes
next" undefined for the thing whose whole job is answering it. Worth knowing before a template
editor exists: PostgreSQL checks unique constraints per statement, so swapping two positions needs
one statement, a temporary out-of-range value, or a deferrable constraint.

**`is_start_stage` and `is_completion_stage` are plain booleans under no rule.** That a completion
stage carries legal effect is undecided and must not be inferred (D-104). Matter Status and Workflow
Stage stay separate concepts.

### CASCADE, used once, with its consequence stated

`workflow_stages.workflow_template_id` cascades — the only cascade in this schema besides the
allocator counters. A stage has no existence apart from its template: it is a line inside a
configuration, not a record the office keeps, so orphaning stages would leave rows nothing can reach
or explain.

**The consequence is written down now rather than discovered later: M4.7's
`matter_stage_instances.workflow_stage_id` must be `RESTRICT` or nullable**, or deleting a template
would reach through this cascade and damage the history of Matters that ran it. The snapshot columns
exist precisely so an instance survives its stage definition.

### Three CHECKs, because Laravel's unsigned types are MySQL concepts

`version >= 1`, `target_days IS NULL OR target_days >= 0`, and `sequence_no >= 1`. PostgreSQL has no
unsigned integer type and silently maps `unsignedInteger` to `integer` — the M4.1
`default_duration_days` lesson, whose disposable-database run proved the point by accepting `-1`.
Applied here before it could bite, and proven on PostgreSQL, since SQLite cannot add a CHECK after
the fact.

### Data Scopes narrowed to `OFFICE` and `ALL`

`master.workflows.*` joins `master.services.*` in the narrowed set, and the reasoning is restated
rather than borrowed: a template is Office-owned configuration, so `OWN` would have to mean
`created_by` — a column the table deliberately lacks, since the colleague who typed a process in has
no claim on how the office works — `ASSIGNED` has no assignee to match, and `TEAM` has no Team
entity (D-042). Without this, an administrator could grant `master.workflows.view` at `OWN`, see it
save, and hold a silently powerless grant. The other ten `master.*` families keep the permissive
default, because their domains are still undesigned.

### A recurring maintenance defect fixed rather than repeated

Four consecutive milestones edited the same hardcoded `--step` counts in the migration-reversibility
tests, and M4.6 would have been the fifth. **A literal step count decays**: the moment a later
milestone adds a migration, the test silently rolls back something other than the migration it
names. `rollbackStepsTo()` has existed in `tests/Pest.php` since M1.10 for exactly this and had
simply not been adopted; the four Matter and Master Data probes now derive their counts from the
migration they actually mean.

---

## 2026-08-21 — M4.7 Matter workflow instances and stage transitions

### D-112 — Moving on completes the stage you leave, and nothing else is inferred

One forward migration (**29 total**) creating `matter_workflows`,
`matter_stage_instances` and `matter_stage_history`, and **no permission — the count stays at 177**.
`notary.matters.change_stage` and `ppat.matters.change_stage` have been canonical since the
catalogue was transcribed and carried a deferred badge from M4.4; M4.7 gives them routes and removes
the badge. `MatterPolicy::changeStage` already existed from M4.2 and is unchanged.

Six routes, three per domain: `GET .../stages`, `GET .../stages/options`, `POST .../stages/move`.
**Reading answers to `*.matters.view`** — a stage is part of what a Matter *is*, not a separate
resource with its own audience, unlike participation which the registry gave its own pair of codes
(D-105). Inventing a `*.matters.stages.view` would change the canonical count for a read the
Matter's own visibility already governs.

### The three mechanisms that make snapshotting real

`CLAUDE.md` section 18 requires that editing a template must not retroactively change a Matter
already running. Three things together guarantee it, and **the third is the one that could have been
got wrong**:

1. `matter_workflows.workflow_version` records the iteration instantiated from, which is meaningful
   because M4.6 made `version` a counter on one row rather than a row per version (D-111);
2. every stage instance copies `stage_code`, both names and `sequence_no` at instantiation, and
   nothing ever refreshes them;
3. **`matter_stage_instances.workflow_stage_id` is `RESTRICT`, never `CASCADE`.** M4.6's stages
   cascade from their template, so a `CASCADE` here would chain — deleting a template would delete
   its stages, which would delete the instances of every Matter that ran it, destroying exactly the
   history the other two mechanisms exist to preserve. M4.6 wrote this constraint down as a
   consequence for M4.7 to carry; this is where the chain is cut.

**`stage_name_snapshot_id` is not a foreign key.** The `_id` is the ISO 639-1 code for Bahasa
Indonesia, matching `name_id` / `name_en` throughout the schema, and the column holds a displayable
stage name. Every other `*_id` column in the Matter domain does hold a ULID reference, so the name
genuinely invites a wrong join. It is transcribed from the ERD rather than renamed, and a test
asserts it holds a name rather than a ULID. The wire format drops `_snapshot_`, because the client
has no other source for a stage name and the distinction is one only the backend must keep.

### What a move does, which the specification left open

The brief said a move validates that the target exists and is open, and never said what becomes of
the stage moved away from. Something must: two `ACTIVE` stages would leave "current stage" with no
answer.

**The stage you leave becomes `COMPLETED`**, because moving on from a stage is what finishing it
means operationally. **Stages jumped over stay `PENDING` and are untouched** — marking them
`SKIPPED` would infer a decision from a navigation, and skipping is something somebody chooses.

So `SKIPPED` and `BLOCKED` are **vocabulary nothing sets**, recorded as a gap rather than filled by
inference — the same shape M4.4 left for the unreachable Matter statuses (D-109), and a source scan
asserts no code path writes either. Both still render in the interface, because the backend may one
day return them and a stepper that could not draw them would be lying about what it knows.

**There is still no transition matrix** (D-104). A backward move is ordinary and is offered exactly
like a forward one; the only check is that a destination is somewhere you can go, which says nothing
about which destinations follow which origins. Moving to the stage already active is refused, since
that is not a move.

**Matter Status is never written by a stage move.** The two concepts stay separate (`CLAUDE.md`
section 18).

### How a workflow completes

A stage becomes `COMPLETED` by moving on from it, so the final stage would never complete on its own
and `matter_workflows.completed_at` would be unreachable schema. **Completing the Matter closes its
workflow**: `CompleteMatter` marks the `ACTIVE` stage complete and stamps the run, in the same
transaction. It reuses an act an office already performs and a capability that already exists —
`*.matters.complete` — rather than inventing a third stage endpoint and an authorization argument
for it.

**No history row is written when completing.** History records stage *transitions*, and nothing
moves anywhere; a row whose `from` and `to` were the same stage would put a movement in the record
that never happened.

### Instantiation, and why doing nothing is the ordinary outcome

**A deployment with no configured template instantiates no workflow, and the Matter is created
anyway.** That is not an error path — D-104 forbids seeding workflow content, so on a fresh
deployment it is *every* Matter. Failing Matter creation because nobody has configured a process yet
would make the whole Matter module depend on domain validation that has not happened.

**Called explicitly inside `CreateMatter`'s transaction, not from a model observer.** The repository
registers none, one here would make creating a Matter silently do two things including inside every
factory call in the suite, and a workflow that committed while its Matter rolled back would be an
orphan the `UNIQUE (matter_id)` key then blocks forever.

**M4.6 left no uniqueness on `is_default` (D-111), so this action breaks ties itself and says how**:
the Matter's own Service Type first, then the Office's generic default; within either, `is_default`
first and then the **oldest by ULID**. Oldest rather than newest, because the established default is
the one the office has been using and a newest-wins rule would let a template created this morning
silently capture every new Matter. Only `is_active` templates and only the Matter's own Office.

**`is_start_stage` is deliberately not consulted.** It is a template marker whose meaning no
canonical document settles; honouring it would be inferring workflow semantics. The first stage by
sequence becomes `ACTIVE`, and sequence order is structural and already total.

### A defect this milestone surfaced in M4.4

`MatterController::store` set `service_type_id` **after** `CreateMatter` returned — a second write
outside the transaction that left the Matter briefly unclassified. At M4.4 that was untidy; M4.7
made it a defect, because instantiation reads `service_type_id` to prefer a template configured for
that service, and running before the value was set meant **the preference could never fire in
production** while passing in a directly-constructed test. `service_type_id` is now an explicit
parameter of `CreateMatter`, set before instantiation and inside the transaction.

### History is append-only, and enforced

The model refuses `update` and `delete` outright; the schema carries `changed_at` and no
`updated_at`, no `deleted_at`. D-104 records that whether a transition carries legal state is
undecided and treats the table as append-only from the outset — the safe direction to be wrong in —
and `CLAUDE.md` section 31 says the same of audit records generally.

`from_stage_code` and `to_stage_code` are **codes, not foreign keys**: resolving them through live
stage rows would let a later template edit rewrite what the record says happened. `reason` is free
text and therefore a leak surface — D-105 forbids persisting Party identity there, the interface
warns, and nothing automated can enforce it.

### `matters.current_stage_id` is deliberately not built

The ERD lists it and both M4.2 and M4.3 deferred it by name to M4.7. **The `ACTIVE` stage instance
is the current stage**, so a pointer would be a second source of truth that can disagree with it,
and correcting one without the other would be silent corruption. Recorded as not built, with the
reason, rather than leaving the earlier deferrals dangling.

### Stage assignment and approval are recorded, not performed

`assigned_user_id`, `approved_at` and `approved_by` exist because the ERD names them and M4.6 gave
stages `requires_approval` and `approval_permission`. **M4.7 ships no assignment and no approval
act**, so all three stay null and the Form Request refuses them. Whichever milestone approves must
resolve the stored code through a Policy and `EffectiveAccessResolver` (D-048, D-111).

**A stage assignee gains no Matter reach** (D-100). Matter `ASSIGNED` means `matters.pic_user_id`
and nothing else; a test asserts the scope predicate ignores the stage column.

### Frontend

A workflow **section** on the Matter detail page, not a tab — the repository has no `Tabs`
primitive and M4.5 set the section precedent. A vertical stepper renders all five statuses with an
icon **and** a translated label, so nothing depends on colour (`CLAUDE.md` section 49), followed by
the append-only history. The move dialog offers every open stage rather than a "next" one, because
offering only "next" would be the transition matrix D-104 refuses, invented by an interface. Message
keys reach exact parity at 881 per locale, 33 new in a `matterStages` namespace.

---

## 2026-08-21 — O-032 Frontend test runner

### D-113 — Vitest joins the frontend gate, and what it is allowed to mean

O-032 said adding a runner was "a real decision — which one, whether it joins
`quality.yml`, and the `CLAUDE.md` §52 rule that the documented command list must never be weaker
than CI". This is that decision. **No migration, no permission, no backend change**; six test files,
62 tests, and one relaxed lint rule.

**Vitest with React Testing Library**, because the project already compiles through Vite's ecosystem
and the whole configuration is one file. The `@/*` alias is read from `tsconfig.json` via
`resolve.tsconfigPaths` rather than restated, so the test aliases cannot drift from the ones the
application builds with. *(`vite-tsconfig-paths` was installed for this and removed the same day:
Vite now does it natively and says so at startup.)*

**It joins CI**, as a `Tests` step between typecheck and build, and `CLAUDE.md` §52 and `README.md`
gained `pnpm test` in the same change — the rule §52 exists to enforce, written after it was broken
once. `test` is a **single run**; `test:watch` never exits and would hang any task that used it.

### What these tests are allowed to mean

**Presentation, and nothing more.** The backend is the security boundary (`CLAUDE.md` §28) and
authorizes again on every request. A green frontend suite never means an endpoint is protected; what
it means is that the interface asks the same question the backend will, so a control is offered
exactly when following it would work.

`t()` returns its **message key** rather than translated text. A test asserting the Indonesian
sentence would fail the moment somebody improved the wording, and would quietly pass if a component
rendered the right sentence from the wrong key. Asserting `matters.statuses.OPEN` pins the thing
that is a defect if wrong and leaves translators free. Parity and orphan-key checking stays where it
already is, in the milestone verification scripts.

### The coverage number is partial by design

`coverage.all` is off, so a module no test imports is **absent** from the report rather than counted
as zero. The percentage therefore answers "how thoroughly is what we test, tested" and **is not an
application-coverage figure**; it must never be quoted as one. No threshold is set either, because a
threshold over a partial denominator fails the build for importing a new file rather than for
testing less.

### Two environment gaps, both diagnosed rather than worked around

**jsdom does not perform implicit form submission.** A click on a `type="submit"` button never
reaches the form, so *no form in the application could be submitted from a test* — and the failure
is silent: the click lands, nothing happens, and the assertion reads like a component defect. Three
probes narrowed it: `form.requestSubmit` exists and works, `fireEvent.submit` works, and a bare
`<button type="submit">` in a bare `<form>` fails exactly like the shared `Button` — so it is
nothing to do with Base UI. The setup adds the missing activation behaviour as a bubbling `click`
listener that honours `defaultPrevented`. **An earlier attempt polyfilled `requestSubmit` itself and
was wrong**: jsdom already has it, so the guard never fired and the code was dead.

**`toMatterErrorKey` narrows with `instanceof AxiosError`.** A plain object carrying `isAxiosError`
falls through to the generic server message, so the first error-mapping tests passed the wrong
branch and failed. The fixtures now construct real `AxiosError` instances — which is also the
finding: any future test that shapes an error by hand will silently test nothing.

### One lint rule relaxed, narrowly and in configuration

`@next/next/no-html-link-for-pages` fires on any internal-looking `href`, which is right in a page
and wrong in two places: the setup mocks the locale-aware `Link` **as** a plain anchor — the entire
point of the mock — and a `<Button render={<a/>} />` test is checking prop forwarding, not
navigating. Scoped to `src/**/*.test.{ts,tsx}`, `src/test/**` and `vitest.setup.tsx` in
`eslint.config.mjs`, rather than scattered as inline disables, so the rule keeps protecting every
real page (`CLAUDE.md` §52: no suppression without a documented reason).

### What is covered first

The three things O-032 named by name, because they were the stated cost: `visibleNavigation` —
including the `anyPermissions` branch it said "a four-line test would pin" — `can` / `canWithScope`,
and the M4 sections M4.5 and M4.7 added. Plus `Button`, whose contract every screen depends on:
disabled means unclickable, and `type="button"` does not submit.

---

## 2026-08-23 — M5.0 Document and Task architecture lock

### D-114 — A legal document is reachable only by streaming it from an authorized surface, never by URL

`config/filesystems.php` shipped the `local` disk — the private one, rooted at
`storage_path('app/private')` — with **`'serve' => true`**. That registers two routes straight into
the directory M5 will fill with KTP scans, NPWP records, deeds and Minuta Akta:

```text
GET  /storage/{path}   storage.local
PUT  /storage/{path}   storage.local.upload
```

**It was never open.** `ServeFile` aborts without a valid relative signature when the disk's
visibility is private, which it is. That is not the problem.

**The problem is that a signed URL is a transferable bearer token that bypasses the authorization
chain entirely.** No Policy, no `EffectiveAccessResolver`, no Data Scope, and no distinction between
`documents.download` and `documents.sensitive.download`. Whoever holds the string holds the file —
forwarded in a chat message, pasted into a ticket, sitting in a browser history. `CLAUDE.md` section
21 requires sensitive files to be *"authorization protected"* and *"unavailable through predictable
public URLs"*, and section 54 forbids exposing private document URLs; a URL that authorizes by
possession fails both, however unguessable it is.

**`serve` is now `false`, changed at M5.0 — before any document exists to reach through it.** Both
routes are gone; the application's own 127 routes are untouched.

**No document surface may issue a signed URL, a temporary URL, or any other URL resolving to
storage.** Downloads stream from a controller that has authorized the actor against the Document
record first. This is D-048's rule one domain across: there is one authorization path, and a second
one that happens to work is the problem rather than the convenience it looks like.

**M5.0 is otherwise documentation-only**, following M4.0. This one config line is the deliberate
exception, and it is included because the right moment to close an access path is before anything
valuable is behind it.

### D-115 — M5 builds three document junctions, defers four, and improvises no audit

**No permission is registered. The count stays at 177.** All seventeen `documents.*` and `tasks.*`
codes have been canonical since the catalogue was transcribed and unimplemented ever since; M5
implements them rather than adding to them.

**Three of seven junctions are buildable.** `03_DATABASE_ERD.md` section 14 recommends seven;
`property_documents`, `notary_deed_documents`, `ppat_deed_documents` and
`matter_requirement_documents` reference `properties`, `notary_deeds`, `ppat_deeds` and
`matter_requirements` — **none of which exists**, and a foreign key cannot point at a table that is
not there. M5 builds `party_documents`, `project_documents` and `matter_documents`, and **stubs none
of the rest**: not empty, not without their foreign key, and not replaced by a polymorphic column,
which section 14 explicitly argues against. Every junction key is `RESTRICT`, so removing a Party
never takes a document with it.

**Audit is required, absent, and not improvised.** `CLAUDE.md` section 21 requires sensitive files be
*"audited where appropriate"*; `audit_logs` has never been built, D-033 kept it out of M1 on the
batch-7 ordering, and `audit.view` / `audit.export` are registered and unimplemented. Three rulings:
**no half-measure ships** — an application log is not append-only in the sense section 31 means, is
not queryable by resource, and is the stopgap that becomes permanent; **no sensitive-download
surface lands before audit exists**, because the capability to read a KTP scan and the record of who
read it belong in the same milestone; and when it is built it follows section 31 exactly and **never
logs the document's contents nor the identifier it is about** (D-105's leak-surface rule, with more
force rather than less).

**Workflow gating is deferred, and doubly so.** `matter_requirements.required_before_stage_code`
gates a stage transition on document completeness. D-104 forbids inferring workflow content, the two
domains' gating rules differ and neither is authored, **and the table it references —
`service_document_requirements` — does not exist**. So M5 builds neither table: not empty, not with
the column present-but-unused, not as a nullable placeholder (D-095).

**Two catalogues stay uninvented.** `document_type_code` is opaque and nullable, following
`role_code` (D-105, D-111) — `KTP`, `NPWP` and `AKTA` are examples in prose, not a validated list,
so no enum, no `CHECK`, and no dropdown built from a guess. And **`is_sensitive` is set by whoever
uploads, never inferred from the type**: deriving it would encode which document kinds are
sensitive, a judgement that varies by office.

**Sensitive access is a separate capability in both directions.** `documents.sensitive.view` and
`documents.sensitive.download` are independent codes rather than escalations of the ordinary two,
neither implies the other, and `documents.update` reaches none of them.

**A Task's `ASSIGNED` widens nothing else.** Being assigned a Task confers no Matter or Project
reach — the symmetric statement of D-100, which forbade a stage assignee widening Matter `ASSIGNED`.
`projects.pic_user_id`, `matters.pic_user_id` and `tasks.assigned_to` stay three separate
predicates.

**Two questions are handed forward as owned rather than left loose.** `is_current` uniqueness on
`document_versions` — the obvious partial unique index is the shape D-111 already refused, because
SQLite has no partial indexes and the two engines would disagree about what is representable — and
`tasks` carrying `assigned_by` but **no `created_by`** while Data Scope `OWN` needs an owner. Both
are recorded in the lock's unresolved table as belonging to a named milestone.

---

### D-116 — A Document points at its current version through a composite key, and sensitivity is a second capability rather than a scope

M5.1 builds the Document schema, private storage, the `DOC-` allocator, the three buildable
junctions, and the Policy. **Backend foundation only** — no route, controller, request, resource or
frontend, following M2.1, M3.1, M4.1, M4.2 and M4.6. **No permission is registered; the count stays
at 177** (D-115).

**Five migrations, 29 → 34.** `documents`, `document_versions`, the composite key added by `ALTER`,
`document_reference_counters`, and the three junctions in one migration.

**`is_current` is gone, replaced by `documents.current_version_id`** — the choice M5.0 explicitly
assigned to this milestone. A boolean would need a partial unique index to mean "exactly one";
partial indexes do not exist on the SQLite connection the suite runs on, so the two engines would
disagree about what is representable, which is the shape D-111 already refused once.

**A bare pointer would not have proved the version belongs to the document.** It could have named a
version of some *other* document and nothing would have objected. So `document_versions` carries a
support key `UNIQUE (document_id, id)` — redundant for uniqueness, required for a composite foreign
key — and `documents` declares `(id, current_version_id) -> document_versions (document_id, id)`, the
construction D-080, D-098, D-105, D-107 and D-111 all use, applied to a same-Document invariant
instead of a same-Office one. The key arrives by `ALTER` in its own migration because the two tables
reference each other; SQLite cannot add a foreign key to an existing table, so a model guard holds
the identical rule on the test connection.

**`RESTRICT`, after measuring that `NO ACTION` would behave identically.** `document_versions.
document_id` cascades, so hard-deleting a Document removes versions the same Document row still
points at — which looks as though it forces `NO ACTION`. It does not: the referencing `documents`
row goes in the same statement, so by the time `RESTRICT` looks, nothing points at the version. The
M5.1 PostgreSQL probe ran the delete under both declarations and both succeeded. The initial
migration shipped `NO ACTION` with a docblock asserting a difference; the measurement contradicted
it, and the declaration is now `RESTRICT` like every other key in the schema — the single deliberate
CASCADE remains `document_versions.document_id`. **This is recorded because the claim was written
before it was tested**, which is the D-077 defect class.

**A version is written once, enforced rather than intended.** The model refuses `update` outright, and
the table has **no `created_at` or `updated_at`** — `03_DATABASE_ERD.md` section 13 gives it
`uploaded_at` alone, transcribed rather than tidied: a column recording when a version changed is a
column inviting one.

**`documents.updated_by` is present although the M5.1 plan omitted it**, because the ERD lists it and
`matters` carries the same pair: a metadata correction has an author, and `updated_at` alone records
that something changed without recording who.

**`document_number` is nullable**, following `project_number` (M3.3) and `matter_number` (M4.4)
exactly: no creation path allocates one yet, so `NOT NULL` would make a Document unwritable for a
whole milestone. The allocator ships now; the milestone that builds upload stamps the reference
inside the creating transaction and tightens the column.

**Two namespace dimensions, not three.** `DOC-YYYY-NNNNNN` counts per Office and calendar year.
Matter needed a domain because `N-` and `P-` are distinct sequences that would otherwise compete for
one value (D-108); a Document has no such split. One atomic `INSERT … ON CONFLICT … DO UPDATE …
RETURNING`, no `MAX+1`, and the class opens no transaction of its own so it participates in the
caller's. ERD section 27's configurable numbering engine is again declined.

**Storage issues no URL of any kind** — no signed URL, no temporary URL, no path a client could try.
A URL that authorizes by possession is a second authorization path beside the Policy chain (D-114).
The path is `documents/{office_id}/{YYYY}/{MM}/{ulid}.{ext}`, the stored name is generated and the
uploader's name is never a path component, the extension is reduced to lowercase alphanumerics, and
the SHA-256 is computed from **the bytes actually written** rather than from the upload.

**Three Data Scopes reach a Document: `OWN`, `OFFICE`, `ALL`.** `ASSIGNED` is withheld because a
Document has no assignee and no assignment entity exists for the predicate to match; `TEAM` because
no Team entity exists (D-042). `OWN` **is** granted, where Party (D-080) and Service Type (D-106)
withhold it — those are shared reference records the colleague who typed them in has no claim on,
whereas `created_by` names the person who filed the document, the argument Project made at D-088.

**The Permission Matrix is narrowed to match.** All nine `documents.*` codes are restricted to
`OWN, OFFICE, ALL` in `PermissionScopeRules`. Withholding `ASSIGNED` only in the predicate would have
let an administrator grant `documents.view` at `ASSIGNED`, see it saved, and hold a silently
powerless grant — the dead control D-080 named.

**Filing is always into the actor's own Office, including for `ALL`.** `ALL` is reach over records
that already exist, never authority to decide which Office a new one belongs to — the line D-097,
D-098 and D-107 all drew.

**Sensitivity is not a visibility predicate.** `is_sensitive` appears nowhere in `DocumentVisibility`,
and a test asserts its absence. It is checked in the Policy as a **second condition on top of reach**,
which is what keeps `documents.sensitive.view` and `documents.sensitive.download` independently
grantable in both directions (D-115): the ordinary code does not reach a sensitive document, and the
sensitive code cannot stand in for the ordinary one. Sensitivity gates every write ability too —
correcting, verifying, archiving or deleting a KTP scan all disclose it.

**`download` is written and nothing calls it.** D-115 rules that no sensitive-download surface ships
before an audit store exists. The ability exists so the milestone that builds the surface starts from
a decision rather than an omission.

**Archived is reachable; `deleted_at` is reserved with no lifecycle.** Somebody must be able to read
what the office archived (`CLAUDE.md` section 63), and no `SoftDeletes` trait is used, so "invisible
because deleted" cannot be confused with "invisible because out of scope" — the M4.2 position
(D-102). No transition matrix exists: M5 authorizes *who* may act, never *which* status follows which.

**The junctions moved from M5.3 into M5.1**, and the M5.0 lock is amended in place to say so. Each
carries an `office_id` constraint carrier with a composite key into `documents (id, office_id)` — a
support key the `documents` migration creates — so splitting the tables from the key they depend on
would have run a milestone boundary through one invariant. **M5.3 keeps the surfaces**, which is where
the authorization work is. No `UNIQUE (owner_id, document_id)`: no canonical document says a Document
may be attached to a record only once, and a unique index is a business rule wearing an index's
clothing (D-105, D-110).

---

### D-117 — M5 encodes a status matrix after all, upload creates RECEIVED, and every sensitive download is refused until audit exists

M5.2 builds the Document HTTP surface — nine endpoints — plus the document frontend and the sections
that put documents on the Project and Matter detail pages. **No permission is registered; the count
stays at 177.** One migration, 34 → 35.

**The M5.0 lock's "no transition matrix" ruling is superseded, deliberately.** Section 10.2 said M5
would authorize *who* may verify or archive and never encode *which* status may follow which. Three
rules are now encoded on `DocumentStatus`:

```text
upload   ->  RECEIVED
verify   RECEIVED, UNDER_REVIEW   ->  VERIFIED
archive  VERIFIED, FINAL          ->  ARCHIVED
delete   DRAFT, RECEIVED          ->  (soft deleted)
```

They are **operational, not legal**. Nothing here says what a deed, a Minuta or a Warkah may become —
those are M6 and M7 and stay untouched. What they say is that an office may not verify twice, may not
archive what was never verified, and may not delete what somebody has verified.
`02_MENU_AND_PERMISSIONS.md` section 13 requires `documents.delete` be *"heavily restricted"*, and
"only before verification" is the restriction — expressed as a status rule rather than by inventing a
permission.

**Upload creates `RECEIVED`, not `DRAFT`, and that correction is load-bearing.** M5.1 created `DRAFT`.
Verify requires `RECEIVED` or `UNDER_REVIEW`, and nothing moves a Document out of `DRAFT` — so had
upload kept creating it, the verify endpoint would have answered 422 to every document that exists.
`DRAFT`, `UNDER_REVIEW`, `FINAL` and `VOID` are unreachable in M5.2 and **recorded as such** rather
than quietly implied (the D-109 precedent).

**Verification writes no `verified_at` or `verified_by`.** `03_DATABASE_ERD.md` section 13 gives
`documents` neither column; the pair belongs to `matter_requirements` and `warkah`, which are
different tables with their own milestones. Adding them would extend the canonical field list on this
milestone's own authority. Who verified and when is what the audit store records (D-115), and writing
it in two places would guarantee the two eventually disagree — so the status is the fact and
`updated_by` names the last hand on the record.

**`is_sensitive` is settled once the document is.** Changing it after verification would silently
redefine which capability a download answers to, so it is refused with 422 on `VERIFIED`, `FINAL` and
`ARCHIVED`. **`ARCHIVED` is included although the requirement named only the first two** — not an
extension of the rule but the same rule applied consistently, since `ARCHIVED` is reachable only
*through* those two and leaving it out would let archiving unlock a field verification had locked. A
`PATCH` that resends the *current* value is accepted, because refusing it would make the whole form
unusable on a verified document.

**Every sensitive download is refused, whatever the actor holds.** D-115 rules that no
sensitive-download surface ships before an audit store exists. The gate lives in
`DocumentPolicy::download` — after the capability checks, not instead of them — so when audit lands
the milestone that builds it deletes three lines rather than reconstructing the authorization. Until
then `documents.sensitive.download` is a capability that authorizes nothing, recorded here, in the
Policy, and in `can_download`, which reports the endpoint's real answer so the interface never offers
a button that would 403.

**Sensitive documents an actor cannot reach are excluded from the list, not stubbed.** The M5 lock
records "what a stub for an unreachable sensitive document may carry" as genuinely open; rendering
one would have answered that question by accident. The exclusion is a query condition, so the
pagination total stays honest.

**`document_number` became `NOT NULL` in its own forward migration**, never an edit to M5.1's — the
M3.3 and M4.4 precedent (D-097, D-109) a third time. The persistent development database was
inspected first and holds no `documents` table at all, so nothing was backfilled. The rollback was
verified to leave `UNIQUE (id, office_id)` intact, which matters more here than it did for Matter:
dropping that support key would silently take three composite foreign keys with it.

**`SoftDeletes` was added to `Document`, reversing M5.1.** M5.1 withheld the trait while `deleted_at`
sat unused, so "invisible because deleted" could not be confused with "invisible because out of
scope" (D-102). M5.2 ships `DELETE`, so the lifecycle exists. **The file and every version survive a
soft delete** — a delete that erased bytes would be a hard delete wearing a soft one's name — and
there is **no restore endpoint**, because reading `documents.delete` as *"may also undelete"* would
make one capability do two jobs (D-091).

**An upload's attachment targets are re-resolved through their own domain's visibility.**
`documents.upload` is authority to file a document, never authority to discover which records exist.
For a Matter the permission namespace is read from the Matter's own `domain` column — **the one place
in the repository that happens**, and it is not the D-101 hazard: that rule exists so a *caller*
cannot choose which permission is checked, and here the caller supplies only an id while the
namespace comes from a stored row they cannot influence. The effect is the stricter of the two
checks, not either.

**The document frontend ships here rather than at M5.5**, and the lock's decomposition is amended in
place. Nine endpoints with no way to exercise them is not a milestone anybody can accept. The Matter
and Project detail pages gain **sections, not tabs** — the M4.5 and M4.7 precedent on those same
pages, and the lock's own ruling, since the repository has no `Tabs` primitive and adding one is a
design decision affecting shipped pages. **No new frontend dependency**: drag-and-drop uses native
HTML5 events on a real `<input type="file">`, so the keyboard and assistive paths are the browser's
rather than a library's.

**`multipart/form-data` carries strings, and `is_sensitive` is sent as `"1"` / `"0"`.** `"false"`
would arrive as a non-empty string and pass Laravel's `boolean` rule as **true** — silently marking
every document sensitive. Written down because it is invisible until it is a leak.

**File type is validated with `mimetypes`, not `mimes`** — the file's actual detected content type
rather than its extension, so a renamed executable fails. The M5.2 HTTP smoke caught the difference:
`UploadedFile::fake()` reports a type derived from the filename, so the test suite passed with a text
file named `.pdf` while a real upload of the same file was correctly refused. The smoke fixture is a
real PDF now.

---

### D-118 — Attaching is a correction to a document's filing, three of seven targets exist, and duplicates are a surface rule

M5.3 builds the document relation surfaces the M5 lock scheduled: attach, detach, and read, on their
own endpoints. Three routes, **no migration**, and **no permission — the count stays at 177.**

**Three of seven relation types, and the other four are blocked rather than deferred.**
`03_DATABASE_ERD.md` section 14 recommends seven junction tables. `property_documents`,
`notary_deed_documents`, `ppat_deed_documents` and `matter_requirement_documents` reference
`properties` (batch 8, M7), `notary_deeds` (batch 9, M6), `ppat_deeds` (batch 10, M7) and
`matter_requirements` — **none of which exists.** Verified rather than assumed: 31 `Schema::create`
calls across all 35 migrations, and not one of them is these. A composite foreign key cannot point at
a table that is not there, so those migrations would fail; this is not a scoping preference. D-115
already ruled they are stubbed none — not empty, not without their key, not a polymorphic column.

They are **named in `DocumentRelationType` as blocked** rather than omitted, so adding one later is
adding a case and a migration rather than redesigning the enum. Requests naming them get a field
error on `entity_type`, which is what a caller should get for a type the product does not have.

**Attaching answers to `documents.update`, and no code was added to the catalogue.** Attaching is a
correction to a document's own filing rather than a new act, so `documents.attach` was not
registered — the discipline that keeps the canonical catalogue something milestones implement rather
than extend.

**Attaching asks two questions and both must answer yes.** The Document side answers to
`documents.update`; the record on the other end answers to **its own domain's view capability**,
resolved through that domain's visibility class. `documents.update` is authority over a document's
filing; it is never authority to discover which records exist. An unreachable target, one in another
Office, one that is soft-deleted, and one that does not exist all produce the same 422.

**The Matter namespace is read from the row's own `domain` column** — the second place in the
repository that does so, after `DocumentController::matterReachable()` at M5.2 (D-117). The reasoning
is unchanged and is worth restating because it looks like the D-101 hazard and is not: D-101 exists so
a **caller** cannot choose which permission is checked, and here the caller supplies an id while the
namespace comes from a row they cannot influence. The result is the **stricter** of the two checks.
The alternative — `entity_type: notary_matter | ppat_matter` — would have put the namespace in the
request body, which is precisely what D-101 forbids.

**Duplicates are refused at the surface and permitted by the schema, deliberately.** The junctions
carry no `UNIQUE (owner_id, document_id)`: M5.1 declined to invent a cardinality rule no canonical
document states, because *"a unique index is a business rule wearing an index's clothing"* (D-116,
following D-105 and D-110). D-110 also said what to do if an office decides duplicates are wrong —
*"a rule to state and validate"* — so the attach surface states and validates it, inside the
transaction with `lockForUpdate` so two concurrent attaches cannot both pass. The schema stays open,
so an office that later needs a second attachment is not blocked by a migration. **Detach removes
every matching row**, not the first, because a pair could exist from a direct write.

**Nothing is audited, and that is not an oversight.** The milestone brief asked for a simple log or
an `Activity` model; D-115 forbids exactly that — *"an application log is not append-only in the sense
section 31 means, is not queryable by resource, and is the stopgap that becomes permanent."*
`attached_by` and `attached_at` record who and when on the row itself; the event record waits for the
store built to hold it. A test asserts no such store was improvised.

**No `GET /{entity}/{id}/documents`.** That question is already answered by
`GET /documents?project_id=…`, shipped at M5.2 inside the visibility-scoped query. A second address
for one question is two surfaces that must be kept in step, and the first divergence between them
would be a bug.

**`DELETE` carries a body.** The pair being removed is two identifiers, and neither belongs in the
path — `/documents/{id}/relations/{type}/{entityId}` would put a namespace-selecting value into an
address — nor in a query string, which would put record identifiers into logs and browser history.

**The frontend replaced M5.2's read-only block.** `RelatedRecords` rendered `document.related` from
the detail payload and could show attachments and nothing more; `DocumentRelationList` asks its own
endpoint and carries both acts, gated on `can_update`. The Party surfaces gained document sections
too — **sections, not tabs**, as at M5.2. `individual.id` and `company.id` are the **Party** ULID (M2
exposes one public identifier per aggregate, D-078), which is what `party_documents.party_id`
references.

**Two dialogs hold their state in a child that unmounts.** Resetting search and selection from an
effect keyed on `open` is state written during commit — the `react-hooks/set-state-in-effect` rule,
which this repository treats as an error — and would also show the previous selection for a frame on
reopen. State that should start fresh belongs in a component that starts fresh.

---

### D-119 — A Task is owned by whoever raised it, `OWN` and `ASSIGNED` stay two predicates, and the eight task codes were already canonical

M5.4 builds the Task domain the M5 lock scheduled: schema, management endpoints, and — as M5.2 did for
Document — **the frontend with them**, because a twelve-route surface nobody can exercise is a milestone
nobody can accept. **Three migrations (35 → 38), and no permission: the count stays at 177.**

**The plan asked for six new codes and a total of 183. Both numbers were wrong, and the registry is
what settled it.** `tasks.view`, `view_all`, `create`, `update`, `assign`, `complete`, `reopen` and
`delete` — **eight** codes — have been canonical since the catalogue was transcribed at M1, and
`PermissionRegistry.php` is untouched by this milestone. Registering a permission has never been the
same act as shipping a feature (D-064), and the reverse holds too: shipping one is not licence to
extend a catalogue that already names it.

Two consequences follow that the plan would have got wrong. **`tasks.reopen` is its own capability**,
not part of completing — the plan folded them together, and an office may perfectly reasonably let
more people close work than un-close it. And **`tasks.view_all` is consulted nowhere**, superseded by
Data Scope `ALL` for reach exactly as `projects.view_all` and the two `*.matters.view_all` codes are
(D-090); a second reach mechanism is the thing that must not exist.

Where the catalogue is silent, nothing was invented. **`cancel` and `destroy` share `tasks.delete`**,
because cancelling is what makes deletion available — nothing still live may be removed, so calling
work off is the step that precedes it, and there is no `tasks.cancel` to reach for. **Commenting
answers to `tasks.view`**, not `tasks.update`: a person who may read the task may say something about
it, and requiring the edit capability would mean only those who can change the work may discuss it.
There is no `tasks.comment` and this milestone adds none.

**`created_by` is added, which is the question M5.0 handed this milestone.** `03_DATABASE_ERD.md`
section 15 carries `assigned_by` and no `created_by`, and the lock's section 11.1 recorded that as a
transcription question M5.4 must meet as a decision rather than a surprise. `assigned_by` cannot be the
owner: it records who last handed the work over, so ownership would move between people without anybody
deciding it, and a task nobody has assigned yet would have no owner at all. The column is added and
the extension to the canonical list is recorded here rather than quietly made.

**`OWN` and `ASSIGNED` are separate predicates and neither contains the other.** The plan proposed
defining `OWN` as *"created_by OR assigned_to"* and `ASSIGNED` as *"the same, for consistency"*. That
would have made `OWN` a superset of `ASSIGNED`, leaving `ASSIGNED` unable to express anything `OWN` did
not already — **a ranking between scopes, which is precisely what D-028 forbids.** Kept apart they
answer two questions an administrator may want to grant separately, *"work I raised"* and *"work I was
given"*, and an actor holding both reaches the union. That union is the behaviour the plan actually
wanted, arrived at through the mechanism the model already has instead of by collapsing two predicates
into one. `PermissionScopeRules` gains an explicit `TASK_DOMAIN` entry offering all four assignable
scopes — the same four the permissive default offers, but now offered as a decision rather than as
*"nobody has decided yet"*. `TEAM` is withheld as everywhere (D-042).

**A Task's `ASSIGNED` widens nothing else.** Being given a Task confers no Matter reach and no Project
reach — the symmetric statement of D-100. `projects.pic_user_id`, `matters.pic_user_id` and
`tasks.assigned_to` remain three separate predicates.

**Priority is `ProjectPriority`, and the plan's vocabulary would have failed at the database.** The
ERD gives Task `LOW NORMAL HIGH URGENT`, which is exactly what Project and Matter already share
(D-095); a third identical enum would be three places for one vocabulary to drift. The plan wrote
`MEDIUM`. The PostgreSQL probe inserted it and the `tasks_priority_check` **refused the row** — so the
CHECK earned its place by catching the one thing it was written to catch, rather than being a
constraint nobody ever tested.

**`workflow_stage_instance_id` is omitted, and unlike the blocked document junctions it could have been
written.** `matter_stage_instances` has existed since M4.7. It is left out because **nothing would set
it**: `task_templates` is what connects a stage to the tasks it raises, and D-104 with the lock's
section 11.3 keep that unbuilt — which stage produces which task, for whom, and by when is workflow
content nobody has authored. A nullable pointer no code can fill is the placeholder D-095 refused.

**Office ownership is structural on all four user columns.** `assigned_to`, `assigned_by`,
`created_by` and `completed_by` each carry a composite foreign key through the Task's own `office_id`,
the construction `company_people` (D-080), `project_parties` (D-098), `matters` (D-107) and the document
junctions (D-116) all use. That needed a **`UNIQUE (id, office_id)` support key on `users`**, added by
its own forward migration — the first time a support key has been added to a table this repository did
not create. `projects` and `matters` are constrained the same way, so a Task cannot name a Project in
one Office and a Matter in another.

**`RESTRICT` everywhere and `SET NULL` nowhere, for a reason worth stating.** Nulling a composite key
nulls *both* its columns, `office_id` included, and `office_id` is `NOT NULL` — so the obvious
`nullOnDelete()` on `(project_id, office_id)` is not merely stylistically wrong, it would fail at
runtime. Refusing to delete a Project that still has tasks is also the right answer: work does not
become ownerless because the engagement it belonged to was removed.

**A completed Task carries both facts or neither**, enforced by `tasks_completion_pair_check`. Half a
completion is a row nobody can explain, and the pair is what reopening clears together.

**M5 encodes a task transition matrix, superseding the lock's section 11.3** — by decision, as D-117
did for section 10.2, and with less tension: a Task status is **operational, not legal**. Nothing about
it says what a deed or a Warkah may become. Deletion is the rule the rest exist to support: only
`COMPLETED` and `CANCELLED` may be removed, so nothing in flight disappears without somebody saying
what happened to it. **Completion is reversible and cancellation is not** — reopening work finished too
early is an ordinary correction, while cancelling states the work will not happen, and somebody who
cancelled by mistake raises a new task, which leaves a record of both. `PATCH` may set only the three
live statuses, so `tasks.update` never becomes a silent superset of `tasks.complete` (the D-091
discipline).

**`task_comments` carries no `office_id`, following the ERD.** Its Office is its task's, one join away,
and a second copy is a second thing that can disagree. Comments are **write-once**: no edit endpoint, no
delete, and a model guard that refuses an update outright, because a remark records what somebody said
at the time. A correction is another comment. `task_id` cascades — a comment cannot outlive the task it
is about — while `user_id` restricts, so an author cannot be erased from what they wrote.

**Nothing is audited, and that is not an oversight.** The milestone brief asked for a simple log or an
`Activity` model; D-115 forbids exactly that, and D-118 refused the same request four weeks of work
ago. `created_by`, `assigned_by`, `completed_by` and the comment thread record who and when on the rows
themselves; the event record waits for the store built to hold it. A test asserts no such store was
improvised.

**`is_overdue` is computed by the server and rendered by the browser, never recomputed there.** A client
comparing `due_at` to its own clock disagrees with the backend for anybody whose machine is off, and two
people looking at the same task would see different answers.

**The `can_*` flags fold status eligibility into capability**, so a control the endpoint would answer
422 to is simply absent — `can_reopen` false on live work, `can_delete` false on anything in flight.
They remain presentation only (D-113): every endpoint authorizes again.

**A test-runner limit was raised, not a test skipped.** The suite exhausted memory at roughly 2,360
tests. `php -d memory_limit=1G` had no effect because `artisan test` spawns Pest as a subprocess —
the same shape as O-034, where a shell override never reached the process that mattered. Fixed where the
subprocess reads it, in `backend/phpunit.xml`.

**Verification.** 2,360 backend tests pass with 8 skipped, 84 of them for Task; Pint clean. A
disposable PostgreSQL probe at 38 migrations confirmed all nine foreign keys, refused a cross-office
assignee, creator and project, refused `MEDIUM`, enforced the completion pair, refused deletion of a
Project, Matter and User with live tasks, and kept comments through a soft delete. The persistent
development database was not touched and remains at 22 migrations. Frontend: 100 tests across 11 files.

---

## Open Items

Not decisions — conflicts or gaps that remain unresolved.

### M0 completion classification, assessed 2026-08-09

Each open item was tested against the Definition of Done in
`10_M0_FOUNDATION.md` section 77 — not against a general sense of tidiness. None of the
items below appears in that list, and each was verified not to break something that does.

| Item | Blocks M0? | Evidence |
|---|---|---|
| O-004 | No | Cosmetic milestone-label mismatch. Deferred since M0.1. |
| O-010 | No | `gh` CLI absent. Git over HTTPS works; no DoD item needs it. |
| O-014 | Resolved | Inter implemented in M0.6. |
| O-015 | No | Scaffold `AGENTS.md` / `CLAUDE.md` in `frontend/`. Do not contradict the root constitution. |
| O-016 | Resolved | Backend EditorConfig aligned. |
| O-017 | **No** | Unmatched URLs fall to the built-in Next.js 404. The DoD requires `/id/login`, `/en/login`, a protected dashboard, and language switching — all verified. A designed 404 for URLs that match no route is presentation, and fixing it needs a catch-all route, which is routing work for a later milestone. |
| O-018 | **No** | `setRequestLocale` is deprecated but functional and load-bearing: it is what keeps `/id` and `/en` prerendered. Build, lint, and typecheck are clean, and the clean clone built without warning. Migration is blocked upstream — next-intl 4.13.5 contains no reference to `next/root-params`. Deferring a fix that cannot yet be written is not a defect. |
| O-020 | **No** | No `SUPER_ADMIN` bypass exists, which is the safe state. The DoD asks that a permission architecture exist, and it does — role-derived permissions reach Laravel's Gate, verified by test and at runtime. Designing privileged-account semantics belongs to M1 security review. *(That review has since happened — see D-032. This row records the M0 assessment as made at the time.)* |
| O-021 | **No** | Sidebar collapse is a desktop refinement. The DoD says nothing about it. Responsive navigation works: desktop sidebar plus a drawer below `lg`, sharing one menu definition. |
| O-022 | **No** | Search, quick create, and notifications depend on modules that do not exist. Building them now would mean fabricated UI, which `10_M0_FOUNDATION.md` section 57 explicitly forbids. Their absence is the correct M0 state. |

No open item blocks M0. None was closed for the sake of a clean checklist.

| ID | Item | Status |
|---|---|---|
| O-001 | `01_ARCHITECTURE.md` section 2 did not reflect D-003 | **Resolved 2026-08-08.** Section 2 now carries the canonical 12-entry structure and cross-references `10_M0_FOUNDATION.md` and D-003. See D-010. |
| O-002 | `CLAUDE.md` stated the technology stack without versions | **Resolved 2026-08-08.** Section 3 now states Next.js 16.x, Node >= 20.9, Laravel 13.x, PHP >= 8.3, and adds Database and Infrastructure subsections (PostgreSQL 18.x, Redis 8.x, private file storage). |
| O-003 | `CLAUDE.md` section 58 listed ten `/docs` files | **Resolved 2026-08-08.** Section 58 now lists all 14 entries and restates the 08/09 draft restriction and the `DECISIONS.md` precedence rule. |
| O-004 | Milestone M2 is labelled "Party / Individual / Company" in `00_PROJECT_OVERVIEW.md` and "Client Database" in the source PDF | **Resolved 2026-08-11 by D-078.** The canonical milestone name is **M2 — Party / Individual / Company**. "Client Database" is retained only as a user-facing description: a descriptive subtitle such as "Clients & Parties" may appear in navigation and product documentation. What the resolution actually settles is not a label but a schema question — **"Client" must never become a second persistence entity beside Party.** There is no `clients` table, no `Client` model, and no `client_id` parallel to `party_id`; a Party becomes a client through use. Deferring this was correct while it looked cosmetic, and closing it now is right because M2 is the milestone where the wrong reading would have produced a duplicate table. |
| O-005 | `.editorconfig` used a single 4-space default, conflicting with Prettier and the Next.js scaffold | **Resolved 2026-08-08.** See D-011. Per-ecosystem indentation now explicit. |
| O-006 | `.github/` contains only `.gitkeep`. No CI workflow exists. | **Resolved 2026-08-09.** The deferral condition — executable quality gates on both sides — is now met, so the item was closed on its own recorded terms rather than because M0 was ending. `.github/workflows/quality.yml` runs exactly the commands README documents. The backend job pins **PHP 8.3**, the canonical minimum in D-005, while the workstation runs 8.4; that gap is the point, since it catches 8.4-only syntax before anyone else sees it. No PostgreSQL or Redis service is declared because the Pest suite runs on in-memory SQLite per `backend/phpunit.xml`. No secrets, no deployment. **Operationally verified green 2026-08-09.** The route there is worth keeping, because the workflow proved its value by failing: implemented during M0.10 → first real runs passed the frontend job but failed the backend job at `composer install`, exposing a committed lockfile that could not install on PHP 8.3 → corrected by pinning Composer's resolution baseline to the supported minimum (D-025) → both jobs green on the feature branch → both jobs green on the `main` merge commit `8be0ad0`. Had CI been pinned to the workstation's PHP 8.4, that lockfile defect would have shipped unnoticed. |
| O-007 | The working directory was not a Git repository, leaving the first M0.1 acceptance criterion in `10_M0_FOUNDATION.md` section 67 unmet | **Resolved 2026-08-08.** Repository initialized on `main` with three commits covering tooling, specifications, and `CLAUDE.md`. See D-012. |
| O-009 | No GitHub remote existed; `gh` CLI is not installed | **Resolved 2026-08-08.** Private repository created through the browser; `origin` added and `main` pushed. Local and remote both at `93ff35b`. See D-012. |
| O-014 | The shadcn `nova` preset installs the **Geist** font. `04_UI_DESIGN_SYSTEM.md` recommends **Inter**. (The item originally cited section 6; the typography guidance is in section **4**.) | **Resolved 2026-08-09.** Inter implemented through `next/font`, self-hosted, no runtime external font request. Geist removed from source and build output. No new decision was required — Inter is the only typeface the design system names, and D-017 had already recorded Geist as an incidental preset default. Separately fixed while doing so: `--font-sans: var(--font-sans)` in the scaffold CSS was self-referential, so no custom sans had ever actually applied. |
| O-015 | The Next.js scaffold generated `frontend/AGENTS.md` and `frontend/CLAUDE.md`. The latter is an 11-byte pointer containing only `@AGENTS.md`. | **Reviewed 2026-08-11 in M1.10; remains open, and the original advice was wrong.** These are not scaffold leftovers — `next dev` **regenerates them**, verified by reading `node_modules/next/dist/server/lib/generate-agent-files.js`, which references `AGENTS.md`, `CLAUDE.md`, and the `nextjs-agent-rules` marker the file itself carries. Deleting them therefore produces a recurring dirty tree rather than a tidier repository, so the earlier note to "remove them if a second instruction file is unwanted" is withdrawn. Content re-read for conflicts: it is additive Next.js guidance (read the version's own docs before coding) and contradicts nothing in the root `CLAUDE.md`. Closing this item requires an upstream opt-out, not a deletion. |
| O-010 | `gh` CLI is still not installed. Remote repository administration — visibility, branch protection, collaborators, settings — cannot be inspected or changed from this terminal. | Open. Not a blocker. Git operations over HTTPS work using the stored credential. Install `gh` only if repository administration from the terminal becomes useful. |
| O-008 | Node.js v25.9.0 was in use; the v25 line is EOL and is not an LTS line | **Resolved 2026-08-08.** Migrated to Node 24.19.0 LTS via nvm-windows. Verified in a clean shell: `node v24.19.0`, `npm 11.17.0`, single resolution at `C:\Program Files\nodejs\node.exe`. See D-013. |
| O-011 | Herd's `bin` was not on PATH, so `composer` and `laravel` failed with `'php' is not recognized` | **Resolved 2026-08-08.** Herd reinstalled; `C:\Users\User\.config\herd\bin` now present in the persisted USER PATH. `php`, `composer`, `laravel`, and `herd` all resolve. |
| O-012 | Three Herd PHP extensions failed to load from a missing directory | **Resolved 2026-08-08.** The Herd reinstall fixed it. `php --version` is now warning-free, and `redis`, `mongodb`, and `herd` all appear in `php -m` — they load rather than merely being silenced. |
| O-013 | pnpm not installed | **Resolved 2026-08-08.** `corepack enable pnpm` → pnpm 11.20.0. See D-015. |
| O-021 | Desktop sidebar collapse (240–260px → 72px icon rail, `04_UI_DESIGN_SYSTEM.md` section 3) is not implemented. | Open, deliberately deferred at M0.9 under the "implement only if small and coherent" instruction. Dashboard is currently the only destination, so collapse would add a toggle, a width mode, label hiding, and tooltips or `aria-label`s to preserve accessible names — around a single row. Revisit when the sidebar carries the Notary and PPAT groups from section 11, where a narrow rail actually earns its complexity. |
| O-022 | Search, quick create, and notifications from `04_UI_DESIGN_SYSTEM.md` section 10 are absent from the header rather than rendered disabled. | Open by design. Each needs a module that does not exist — nothing to search, no record type to create, no event to notify about. A visibly disabled control is dead UI that invites "why is this greyed out?", and an enabled one that does nothing is worse. They are reserved header slots, to be added when the first module gives them something real to do. Recorded so their absence reads as a decision rather than an oversight. |
| O-023 | `offices.code` has **no uniqueness constraint**. No canonical document defines one — "unique" appears nowhere in the specification — so M1.1 implemented the column plain rather than inventing a rule. A composite `organization_id + code` uniqueness is the likely intent, since a code is only meaningful as a short handle within its Organization. | **Resolved 2026-08-11 in M1.10.** `UNIQUE (organization_id, code)` added by forward migration `2026_08_11_101500_add_office_code_uniqueness`, implementing D-037 rather than deciding anything new. Composite, not global: two Organizations may each run a `PUSAT`. D-037 had scheduled it to land beside a matching Form Request, but **that condition could not be met inside M1** — M1 ships no Office write endpoint, so there was no validation layer to disagree with, and deferring again would have carried an already-decided invariant past the milestone that closes M1. Data safety verified before writing: `offices` held 0 rows and the duplicate query returned none. Six regression tests plus a migrate/rollback/re-migrate probe; both semantics also proven against real PostgreSQL (smoke steps 48–49) and on a disposable database migrated from zero. **Carried forward:** when Office management is built, its Form Request must add `Rule::unique('offices','code')->where('organization_id', $id)` so a duplicate is a field error rather than a 500. |
| O-024 | `user_permission_overrides` carries `created_at` but no `updated_at`, following the `03_DATABASE_ERD.md` section 5 field list (D-038). Because the table is unique on `(user_id, permission_id)`, changing an override means updating the existing row — and nothing then records when it changed or who changed it. | Open. Deliberate, not an oversight: the canonical field list is explicit, and inventing a column to fill a gap the ERD does not acknowledge would be the wrong fix. The real answer is the audit log, which D-033 places outside M1 entirely. Revisit when override management lands (M1.6) — either audit covers it by then, or the ERD needs `updated_at` and an `updated_by`, which is a documentation change before it is a migration. |
| O-025 | Spatie's `model_has_permissions` and `model_has_roles` key models by a polymorphic `model_id` with **no foreign key**, so deleting a user through a mass-delete query leaves their pivot rows behind. Observed directly during the M1.3 PostgreSQL smoke test: `model_has_roles` cleaned up only because deleting the *roles* cascaded, while the direct-permission row survived and had to be removed by hand. **Risk reduced at M1.5** — `User` now uses `SoftDeletes` and no deletion endpoint exists (D-050), so the product cannot reach the orphaning state. Still open: the package behaviour is unchanged, and any future purge path must detach package assignments explicitly. | Open, and low urgency. No first-party authorization path reads `model_has_permissions` (D-041), and the registry defines no `users.delete` capability, so nothing in the product deletes a user today. It becomes real if user deletion is ever built: that path must detach package assignments explicitly — Spatie's model events do it for `$user->delete()` but not for `User::query()->where(...)->delete()`. Worth stating before someone writes the mass-delete version. |
| O-030 | Self-service **email change** has no flow. `email` is the authentication identifier and `email_verified_at` exists in the schema, but no document defines how a new address is verified, what happens to the live session while it is pending, or whether the old address is notified. M1.8 made email read-only on the profile rather than invent one (D-067). | **Resolved 2026-08-11 by D-073.** Two-step, with the current address holding until the new one is proven: the request stores `pending_email` plus a SHA-256 of a single-use token and changes nothing else, the link goes to the **new** address, and confirmation requires both that token and a signed-in session. Every condition is rechecked at confirmation, including whether the address is still free, so a race answers 422 rather than 500. On success the address is replaced, `email_verified_at` is stamped, and other sessions are revoked under D-072. The old address is **not** notified — no canonical document asks for it, and inviting somebody to act on a mail about a change they cannot reverse from that mailbox is not obviously an improvement; the pending state is visible to the account owner instead. Requesting again replaces an earlier pending request, and a cancel action clears it. Nineteen tests, plus smoke steps 42–49 against PostgreSQL. Administrator correction through `users.update` remains available and unchanged. |
| O-029 | `user_permission_overrides` has schema, resolver semantics (D-029), and no administrative surface. M1.6 built the Permission Matrix and role assignment but deliberately did **not** expose per-user ALLOW/DENY overrides or their expiry, and no milestone currently owns that work. | Open, and deliberately unclaimed rather than quietly assumed. A per-user exception is a different mechanism from a role grant: it overrides the role result outright, it expires, and it is the one place where one person's access diverges from their colleagues' — which is exactly the kind of thing that needs an audit trail (D-033) and a considered UI, not a checkbox added because the table exists. It also carries O-024's gap: editing an override records neither when nor by whom. Needs an explicitly scoped administration task before any surface is built; until then overrides are settable only by direct database access, which is an honest limitation rather than a hidden one. |
| O-028 | `users.reset_password` is canonical and registered, but no endpoint implements it. No document defines the reset *flow* — how a new secret reaches the person, whether the administrator ever sees it, what notification follows — so M1.5 registered the gap rather than inventing an account-security design inside a user-management milestone (D-051). | **Resolved 2026-08-11 by D-071.** `POST /api/v1/users/{user}/password-reset` authorizes through `UserPolicy::resetPassword` → `EffectiveAccessResolver` → `users.reset_password` with the target user's Data Scope, and sends a link to the account owner's own mailbox. The administrator learns nothing: no token in the response, none in the log, no temporary password, and a submitted `password` field is ignored. The existing password keeps working until the link is used, so the action cannot lock anybody out. Completion at `POST /password-reset` is unauthenticated, rate limited, single use, revokes every session, and creates **none** — so an account with two-factor still meets its second factor (D-072). The permission code is unchanged, so no M1.6 matrix entry or configured role is orphaned. Twenty-four tests, plus smoke steps 51–55 and 64–66 against PostgreSQL. |
| O-026 | `GET /api/v1/me` builds its `permissions` array from Spatie's `getAllPermissions()`. That includes **direct user-permission grants**, which D-041 excludes from first-party authorization, and it carries **no Data Scope**, so it cannot express conditions like "`roles.view` at `ALL`". The browser's permission list and `EffectiveAccessResolver` therefore do not agree. | **Resolved 2026-08-10 by D-062.** `/api/v1/me` now reports effective access from the resolver itself, with exact Data Scopes alongside each permission. Direct package grants, stale codes, grants missing scope metadata, expired overrides, and malformed ALLOW overrides are all excluded, and DENY and ALLOW overrides and multi-role unions are all reflected — verified by 28 backend tests and confirmed over a real session against PostgreSQL. Single-permission and bulk resolution share one decision function (D-061), so the payload cannot drift from the checks that guard endpoints. The frontend `can()`, `canWithScope()`, `PermissionGuard`, and navigation filtering all consume that projection and never a role name (D-063). It was never a vulnerability — the list was presentation-only and every endpoint authorized independently — but the browser and the backend now answer the same question the same way. Superseded note: | Open. Not a vulnerability — the list is presentation-only and every endpoint authorizes independently (CLAUDE.md section 28) — but it is a correctness gap that will mislead menu visibility. M1.4 deliberately does not consume it: the roles page asks the API and renders whatever it answers, including 403. Resolve in M1.7, which owns permission-aware navigation: `/me` should report effective access from the resolver, scopes included, so what the interface shows and what the backend allows are derived from one calculation. |
| O-027 | spatie/laravel-permission registers a `Gate::before` (`PermissionRegistrar::registerPermissions()`) that answers **any ability matching a held permission name**, consulting direct user grants and applying no Data Scope check. So `$user->can('roles.view')` or `middleware('can:roles.view')` returns true for a direct grant that `EffectiveAccessResolver` would refuse — a resolver bypass through the package's own convenience API. | **Resolved 2026-08-09 by D-048.** The first of the two options this item listed was taken: `register_permission_check_method` is now `false`, the package's own documented switch for applications implementing custom permission logic. No vendor file was touched and package storage is unchanged. The unsafe path is structurally gone rather than merely discouraged — the Gate no longer answers permission names at all, so those calls fail closed. `CLAUDE.md` section 24, which had actively recommended the idiomatic form, was corrected, as was `07_SECURITY_RULES.md` section 9. Three enforcement tests were added: zero Gate callbacks registered, a canonical name refused by the Gate even for a user genuinely holding it at `ALL`, and a source scan of `app/` that fails the suite on any reintroduction. The nine existing tests that asserted the old behaviour were rewritten rather than deleted, each carrying a note on why the expectation changed. |
| O-020 | `02_MENU_AND_PERMISSIONS.md` section 4 defines a `SUPER_ADMIN` role, but no bypass exists and none was added at M0.8. Whoever seeds that role in M1 will be tempted to reach for `Gate::before(fn ($user) => $user->hasRole('SUPER_ADMIN') ? true : null)`, which is the package's own documented shortcut. | **Resolved 2026-08-09 by D-032.** Model B chosen after the security review this item asked for: SUPER_ADMIN receives a broad **explicit** permission set and no unconditional bypass. The reasoning the item anticipated held — a `Gate::before` bypass would defeat record-state rules, finalization locks, and sensitive-data permissions — and the role is documented as technical administration that "should not be used as the normal day-to-day legal working account", so it was never meant to carry legal authority. Prohibition is now written into `07_SECURITY_RULES.md` section 9. |
| O-019 | `users.id` is a Laravel `bigint` autoincrement. `CLAUDE.md` section 11 and `06_API_CONVENTIONS.md` section 14 say domain resources should use ULID; `10_M0_FOUNDATION.md` section 45 exempts only third-party package tables, and `users` is our own model. `GET /api/v1/me` therefore returns a numeric id. | **Resolved 2026-08-09,** ahead of M0.8 rather than deferred to M1: Spatie's polymorphic morph keys must match the User key type, so the correction had to land before the package was installed. `users.id` and `sessions.user_id` are now `char(26)` ULIDs, the model uses `HasUlids`, and `CurrentUser.id` is typed `string`. Verified end to end against PostgreSQL with database sessions. See D-023 for why the scaffold migration was edited in place. |
| O-018 | `setRequestLocale` is deprecated in next-intl 4.13.5, which points at [`next/root-params`](https://next-intl.dev/blog/nextjs-root-params). It is currently load-bearing: it is what keeps `/id` and `/en` prerendered. | Open. Migration is blocked, not merely deferred — `next/root-params` exists in Next.js 16.3.0, but next-intl cannot yet source the locale that way. Revisit when next-intl ships root-params support. Until then the deprecated call stays, because removing it would make every locale route server-rendered on demand. **Re-verified at M3.5**, and the wording is corrected rather than restated: this entry previously said the package "contains no reference to it", which is no longer literally true — `RequestLocaleCache` carries the `@deprecated` notice linking the migration blog, in the compiled module and its type declaration. Those two strings are the *only* occurrences; they are a pointer, not an implementation, so the substantive claim is unchanged. Version still **4.13.5**, and `setRequestLocale` is still load-bearing in exactly three files (`[locale]/layout.tsx`, `[locale]/login/page.tsx`, `[locale]/reset-password/page.tsx`). A claim checked by counting matches is worth stating as what it counts. |
| O-017 | A localized not-found state does not render for unmatched URLs. Next.js uses the **root** not-found for those; a nested `[locale]/not-found.tsx` only catches `notFound()` thrown inside its own segment, and the proxy guarantees the locale segment is always valid. | Open. Written during M0.6, verified non-functional, and removed rather than left as dead code. Making it work requires a catch-all route under `[locale]`, which is a routing change beyond M0.6's presentational scope. The built-in Next.js 404 remains, as it did after M0.5. `BaseErrorState` is ready to render it when the catch-all is added. |
| O-033 | Six fields are supported everywhere except the interface. `gender`, `marital_status`, `village`, and `district` on Individual, and `village` and `district` on Company, are accepted and stored by the Form Requests, returned by the API Resources, typed in the frontend, and **translated in both locales** — yet no form collects them and no page displays them. A value written through the API is invisible in the product, and the translated labels make the repository look as though it supports what it does not. | Open, and deliberately not closed by M2.6. Two of the six are the reason: `gender` and `marital_status` carry legal weight in Indonesian notarial practice — spousal consent and capacity questions turn on them — so deciding whether they appear, where, and with what vocabulary is domain specification, not a decision a quality gate may take (CLAUDE.md §62). The other four are ordinary address granularity and could be added mechanically, but splitting the six would leave the Individual address half-complete for no stated reason. Closing this needs one decision covering all six: either they belong in the interface, in which case the forms and detail pages gain them together, or they do not, in which case the labels and the frontend types should go and the API fields should be documented as inbound-only. Recorded at M2.6 rather than guessed at. |
| O-031 | The Party Directory's **Office filter is built from the Offices present in the current page of results**, not from an endpoint. The two options endpoints that exist answer a different question — `individuals/options` and `companies/options` list the Offices an actor may **create** in, which is neither necessary nor sufficient for reading — so offering those would show destinations that return nothing and hide ones that return rows. | Open, and deliberate rather than overlooked. The derivation is honest: it can never offer an Office the caller's capabilities do not already reach, and selecting one only narrows, because the backend applies `office_id` on top of each capability's own scope predicate. The cost is that the choices reflect the page in view, so an Office whose rows fall on a later page is not offered until the caller reaches it. Closing this needs a **view-scoped** Offices source — and the honest version of it is not one list but two, since `parties.view` and `companies.view` are evaluated independently and may reach different Offices (D-028). That is a small API addition with a real design question inside it, which is why M2.5 did not invent one to fill a filter. Revisit when a second surface needs the same list. |
| O-032 | The frontend has **no test runner**. Its quality gate is `format:check`, `lint`, `typecheck`, and `build`, so pure frontend logic — `visibleNavigation`, `can`/`canWithScope`, the duplicate-advisory gate — is verified by typecheck, deterministic source scans, and runtime behaviour through the API, never by an executed unit test. | **Resolved 2026-08-21 by D-113.** Vitest and React Testing Library, added as an explicitly scoped task exactly as this entry asked — not incidentally inside a feature milestone. All three decisions the entry named are recorded: **which one** (Vitest, because the project already compiles through Vite's ecosystem and the alias is read from `tsconfig.json` rather than restated), **whether it joins CI** (yes, a `Tests` step between typecheck and build), and **§52** (`pnpm test` added to `CLAUDE.md` sections 51 and 52 and to `README.md` in the same change, which is the rule §52 exists to enforce). Six files, 62 tests. The three targets this entry named are covered first: `visibleNavigation` including the `anyPermissions` branch it said "a four-line test would pin", `can` / `canWithScope`, and the M4 sections. Two environment gaps were found and diagnosed rather than worked around — jsdom performs no implicit form submission, so *no form could be submitted from a test*, and `toMatterErrorKey` narrows with `instanceof AxiosError`, so hand-shaped error objects silently test the wrong branch. **The gap this closes was presentation, not authorization**, exactly as the entry said: the backend remains the security boundary and a green frontend suite never means an endpoint is protected. The prediction held too — it was worth doing before the navigation tree grew, and by the time it happened the tree had gained the Notary and PPAT groups the entry anticipated. |
| O-034 | **`php artisan serve` does not pass a shell `DB_DATABASE` override to the `php -S` subprocess it spawns.** Every artisan CLI command honours the override — `migrate`, `tinker` and `permissions:sync` all connected to the disposable database and reported it — so a milestone can migrate and seed the right database and then serve the wrong one. Discovered at M3.5, when the in-process probe answered `notary_ppat_office` instead of the disposable database and the smoke was aborted on its first request. | Open, and recorded as method rather than treated as a one-off. This is the precise mechanism behind the class of near-miss the M3.3 rule exists to prevent, and knowing it turns "prove the serving process's database" from a ritual into a check with a known failure mode behind it. The working approach is to launch the framework's own router directly — `php -S <host:port> -t backend/public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`, with the working directory set to `backend/public`, since `server.php` resolves `index.php` from the current directory — and to probe it before the first real request regardless. **The rule does not change**: a shell override is not evidence about the serving process, and the probe stays mandatory whichever launcher is used. Closing this needs either an upstream change or a committed, tested smoke launcher; neither belongs in an audit milestone. |
| O-016 | The Laravel skeleton ships `backend/.editorconfig` with `root = true`, which halts the upward search. The repository `.editorconfig` and D-011 therefore do not apply anywhere inside `backend/`. Both agree that PHP uses 4 spaces, so no PHP file is affected. They diverge for JSON and JavaScript: the root file says 2 spaces, the backend file falls through to its own 4-space default. Affects `backend/composer.json`, `backend/package.json`, and `backend/vite.config.js`. | **Resolved 2026-08-09.** `backend/.editorconfig` deleted; the root file now governs `backend/`. Every rule it carried already existed in the root file, except `[compose.yaml] indent_size = 4`, which targets a Laravel Sail file that does not exist — `backend/` contains no YAML at all. Verified with the reference `editorconfig` resolver, not by inspection. No decision was superseded; D-011 gained a scope note instead. |

---

**Status:** Active register
