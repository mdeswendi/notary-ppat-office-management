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
permission names. It holds **171** entries transcribed from
`02_MENU_AND_PERMISSIONS.md` sections 7–21, grouped by source section, exposed flat
through `all()` — de-duplicated and sorted.

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
| O-004 | Milestone M2 is labelled "Party / Individual / Company" in `00_PROJECT_OVERVIEW.md` and "Client Database" in the source PDF | **Deferred 2026-08-08.** Cosmetic only. Must not block foundation development. Not to be touched during unrelated steps. |
| O-005 | `.editorconfig` used a single 4-space default, conflicting with Prettier and the Next.js scaffold | **Resolved 2026-08-08.** See D-011. Per-ecosystem indentation now explicit. |
| O-006 | `.github/` contains only `.gitkeep`. No CI workflow exists. | **Resolved 2026-08-09.** The deferral condition — executable quality gates on both sides — is now met, so the item was closed on its own recorded terms rather than because M0 was ending. `.github/workflows/quality.yml` runs exactly the commands README documents. The backend job pins **PHP 8.3**, the canonical minimum in D-005, while the workstation runs 8.4; that gap is the point, since it catches 8.4-only syntax before anyone else sees it. No PostgreSQL or Redis service is declared because the Pest suite runs on in-memory SQLite per `backend/phpunit.xml`. No secrets, no deployment. **Operationally verified green 2026-08-09.** The route there is worth keeping, because the workflow proved its value by failing: implemented during M0.10 → first real runs passed the frontend job but failed the backend job at `composer install`, exposing a committed lockfile that could not install on PHP 8.3 → corrected by pinning Composer's resolution baseline to the supported minimum (D-025) → both jobs green on the feature branch → both jobs green on the `main` merge commit `8be0ad0`. Had CI been pinned to the workstation's PHP 8.4, that lockfile defect would have shipped unnoticed. |
| O-007 | The working directory was not a Git repository, leaving the first M0.1 acceptance criterion in `10_M0_FOUNDATION.md` section 67 unmet | **Resolved 2026-08-08.** Repository initialized on `main` with three commits covering tooling, specifications, and `CLAUDE.md`. See D-012. |
| O-009 | No GitHub remote existed; `gh` CLI is not installed | **Resolved 2026-08-08.** Private repository created through the browser; `origin` added and `main` pushed. Local and remote both at `93ff35b`. See D-012. |
| O-014 | The shadcn `nova` preset installs the **Geist** font. `04_UI_DESIGN_SYSTEM.md` recommends **Inter**. (The item originally cited section 6; the typography guidance is in section **4**.) | **Resolved 2026-08-09.** Inter implemented through `next/font`, self-hosted, no runtime external font request. Geist removed from source and build output. No new decision was required — Inter is the only typeface the design system names, and D-017 had already recorded Geist as an incidental preset default. Separately fixed while doing so: `--font-sans: var(--font-sans)` in the scaffold CSS was self-referential, so no custom sans had ever actually applied. |
| O-015 | The Next.js scaffold generated `frontend/AGENTS.md` and `frontend/CLAUDE.md`. The latter is an 11-byte pointer containing only `@AGENTS.md`. | Open. Both were kept as standard scaffold output. They are Next.js coding hints, not project rules, and do not contradict the root `CLAUDE.md`. Remove them if a second instruction file in the repository is unwanted. |
| O-010 | `gh` CLI is still not installed. Remote repository administration — visibility, branch protection, collaborators, settings — cannot be inspected or changed from this terminal. | Open. Not a blocker. Git operations over HTTPS work using the stored credential. Install `gh` only if repository administration from the terminal becomes useful. |
| O-008 | Node.js v25.9.0 was in use; the v25 line is EOL and is not an LTS line | **Resolved 2026-08-08.** Migrated to Node 24.19.0 LTS via nvm-windows. Verified in a clean shell: `node v24.19.0`, `npm 11.17.0`, single resolution at `C:\Program Files\nodejs\node.exe`. See D-013. |
| O-011 | Herd's `bin` was not on PATH, so `composer` and `laravel` failed with `'php' is not recognized` | **Resolved 2026-08-08.** Herd reinstalled; `C:\Users\User\.config\herd\bin` now present in the persisted USER PATH. `php`, `composer`, `laravel`, and `herd` all resolve. |
| O-012 | Three Herd PHP extensions failed to load from a missing directory | **Resolved 2026-08-08.** The Herd reinstall fixed it. `php --version` is now warning-free, and `redis`, `mongodb`, and `herd` all appear in `php -m` — they load rather than merely being silenced. |
| O-013 | pnpm not installed | **Resolved 2026-08-08.** `corepack enable pnpm` → pnpm 11.20.0. See D-015. |
| O-021 | Desktop sidebar collapse (240–260px → 72px icon rail, `04_UI_DESIGN_SYSTEM.md` section 3) is not implemented. | Open, deliberately deferred at M0.9 under the "implement only if small and coherent" instruction. Dashboard is currently the only destination, so collapse would add a toggle, a width mode, label hiding, and tooltips or `aria-label`s to preserve accessible names — around a single row. Revisit when the sidebar carries the Notary and PPAT groups from section 11, where a narrow rail actually earns its complexity. |
| O-022 | Search, quick create, and notifications from `04_UI_DESIGN_SYSTEM.md` section 10 are absent from the header rather than rendered disabled. | Open by design. Each needs a module that does not exist — nothing to search, no record type to create, no event to notify about. A visibly disabled control is dead UI that invites "why is this greyed out?", and an enabled one that does nothing is worse. They are reserved header slots, to be added when the first module gives them something real to do. Recorded so their absence reads as a decision rather than an oversight. |
| O-023 | `offices.code` has **no uniqueness constraint**. No canonical document defines one — "unique" appears nowhere in the specification — so M1.1 implemented the column plain rather than inventing a rule. A composite `organization_id + code` uniqueness is the likely intent, since a code is only meaningful as a short handle within its Organization. | **Direction fixed 2026-08-09 by D-037** — `UNIQUE (organization_id, code)`. Still **open for implementation**: M1.2 added no migration, and the constraint is scheduled to land with the Office management submilestone so the database rule and the Form Request rule arrive together instead of disagreeing in between. Adding it remains cheap while `offices` holds no rows. |
| O-024 | `user_permission_overrides` carries `created_at` but no `updated_at`, following the `03_DATABASE_ERD.md` section 5 field list (D-038). Because the table is unique on `(user_id, permission_id)`, changing an override means updating the existing row — and nothing then records when it changed or who changed it. | Open. Deliberate, not an oversight: the canonical field list is explicit, and inventing a column to fill a gap the ERD does not acknowledge would be the wrong fix. The real answer is the audit log, which D-033 places outside M1 entirely. Revisit when override management lands (M1.6) — either audit covers it by then, or the ERD needs `updated_at` and an `updated_by`, which is a documentation change before it is a migration. |
| O-025 | Spatie's `model_has_permissions` and `model_has_roles` key models by a polymorphic `model_id` with **no foreign key**, so deleting a user through a mass-delete query leaves their pivot rows behind. Observed directly during the M1.3 PostgreSQL smoke test: `model_has_roles` cleaned up only because deleting the *roles* cascaded, while the direct-permission row survived and had to be removed by hand. | Open, and low urgency. No first-party authorization path reads `model_has_permissions` (D-041), and the registry defines no `users.delete` capability, so nothing in the product deletes a user today. It becomes real if user deletion is ever built: that path must detach package assignments explicitly — Spatie's model events do it for `$user->delete()` but not for `User::query()->where(...)->delete()`. Worth stating before someone writes the mass-delete version. |
| O-026 | `GET /api/v1/me` builds its `permissions` array from Spatie's `getAllPermissions()`. That includes **direct user-permission grants**, which D-041 excludes from first-party authorization, and it carries **no Data Scope**, so it cannot express conditions like "`roles.view` at `ALL`". The browser's permission list and `EffectiveAccessResolver` therefore do not agree. | Open. Not a vulnerability — the list is presentation-only and every endpoint authorizes independently (CLAUDE.md section 28) — but it is a correctness gap that will mislead menu visibility. M1.4 deliberately does not consume it: the roles page asks the API and renders whatever it answers, including 403. Resolve in M1.7, which owns permission-aware navigation: `/me` should report effective access from the resolver, scopes included, so what the interface shows and what the backend allows are derived from one calculation. |
| O-027 | spatie/laravel-permission registers a `Gate::before` (`PermissionRegistrar::registerPermissions()`) that answers **any ability matching a held permission name**, consulting direct user grants and applying no Data Scope check. So `$user->can('roles.view')` or `middleware('can:roles.view')` returns true for a direct grant that `EffectiveAccessResolver` would refuse — a resolver bypass through the package's own convenience API. | Open, and currently unexploited: nothing in the application calls `can()` or `can:` on a canonical permission name, and `RolePolicy`'s abilities are named `viewAny`/`view`/`create`/`update`/`delete` precisely so the callback cannot answer them. The hazard is the next person who reaches for the idiomatic form — which `CLAUDE.md` section 24 actively recommends. Options for M1.6/M1.7: set `register_permission_check_method` to false and route every check through the resolver, or keep it and forbid `can()` on canonical names in review. Needs a decision before more endpoints are written, not after. |
| O-020 | `02_MENU_AND_PERMISSIONS.md` section 4 defines a `SUPER_ADMIN` role, but no bypass exists and none was added at M0.8. Whoever seeds that role in M1 will be tempted to reach for `Gate::before(fn ($user) => $user->hasRole('SUPER_ADMIN') ? true : null)`, which is the package's own documented shortcut. | **Resolved 2026-08-09 by D-032.** Model B chosen after the security review this item asked for: SUPER_ADMIN receives a broad **explicit** permission set and no unconditional bypass. The reasoning the item anticipated held — a `Gate::before` bypass would defeat record-state rules, finalization locks, and sensitive-data permissions — and the role is documented as technical administration that "should not be used as the normal day-to-day legal working account", so it was never meant to carry legal authority. Prohibition is now written into `07_SECURITY_RULES.md` section 9. |
| O-019 | `users.id` is a Laravel `bigint` autoincrement. `CLAUDE.md` section 11 and `06_API_CONVENTIONS.md` section 14 say domain resources should use ULID; `10_M0_FOUNDATION.md` section 45 exempts only third-party package tables, and `users` is our own model. `GET /api/v1/me` therefore returns a numeric id. | **Resolved 2026-08-09,** ahead of M0.8 rather than deferred to M1: Spatie's polymorphic morph keys must match the User key type, so the correction had to land before the package was installed. `users.id` and `sessions.user_id` are now `char(26)` ULIDs, the model uses `HasUlids`, and `CurrentUser.id` is typed `string`. Verified end to end against PostgreSQL with database sessions. See D-023 for why the scaffold migration was edited in place. |
| O-018 | `setRequestLocale` is deprecated in next-intl 4.13.5, which points at [`next/root-params`](https://next-intl.dev/blog/nextjs-root-params). It is currently load-bearing: it is what keeps `/id` and `/en` prerendered. | Open. Migration is blocked, not merely deferred — `next/root-params` exists in Next.js 16.3.0, but next-intl 4.13.5 contains no reference to it, so the library cannot yet source the locale that way. Revisit when next-intl ships root-params support. Until then the deprecated call stays, because removing it would make every locale route server-rendered on demand. |
| O-017 | A localized not-found state does not render for unmatched URLs. Next.js uses the **root** not-found for those; a nested `[locale]/not-found.tsx` only catches `notFound()` thrown inside its own segment, and the proxy guarantees the locale segment is always valid. | Open. Written during M0.6, verified non-functional, and removed rather than left as dead code. Making it work requires a catch-all route under `[locale]`, which is a routing change beyond M0.6's presentational scope. The built-in Next.js 404 remains, as it did after M0.5. `BaseErrorState` is ready to render it when the catch-all is added. |
| O-016 | The Laravel skeleton ships `backend/.editorconfig` with `root = true`, which halts the upward search. The repository `.editorconfig` and D-011 therefore do not apply anywhere inside `backend/`. Both agree that PHP uses 4 spaces, so no PHP file is affected. They diverge for JSON and JavaScript: the root file says 2 spaces, the backend file falls through to its own 4-space default. Affects `backend/composer.json`, `backend/package.json`, and `backend/vite.config.js`. | **Resolved 2026-08-09.** `backend/.editorconfig` deleted; the root file now governs `backend/`. Every rule it carried already existed in the root file, except `[compose.yaml] indent_size = 4`, which targets a Laravel Sail file that does not exist — `backend/` contains no YAML at all. Verified with the reference `editorconfig` resolver, not by inspection. No decision was superseded; D-011 gained a scope note instead. |

---

**Status:** Active register
