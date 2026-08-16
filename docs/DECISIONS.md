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
| O-018 | `setRequestLocale` is deprecated in next-intl 4.13.5, which points at [`next/root-params`](https://next-intl.dev/blog/nextjs-root-params). It is currently load-bearing: it is what keeps `/id` and `/en` prerendered. | Open. Migration is blocked, not merely deferred — `next/root-params` exists in Next.js 16.3.0, but next-intl 4.13.5 contains no reference to it, so the library cannot yet source the locale that way. Revisit when next-intl ships root-params support. Until then the deprecated call stays, because removing it would make every locale route server-rendered on demand. |
| O-017 | A localized not-found state does not render for unmatched URLs. Next.js uses the **root** not-found for those; a nested `[locale]/not-found.tsx` only catches `notFound()` thrown inside its own segment, and the proxy guarantees the locale segment is always valid. | Open. Written during M0.6, verified non-functional, and removed rather than left as dead code. Making it work requires a catch-all route under `[locale]`, which is a routing change beyond M0.6's presentational scope. The built-in Next.js 404 remains, as it did after M0.5. `BaseErrorState` is ready to render it when the catch-all is added. |
| O-033 | Six fields are supported everywhere except the interface. `gender`, `marital_status`, `village`, and `district` on Individual, and `village` and `district` on Company, are accepted and stored by the Form Requests, returned by the API Resources, typed in the frontend, and **translated in both locales** — yet no form collects them and no page displays them. A value written through the API is invisible in the product, and the translated labels make the repository look as though it supports what it does not. | Open, and deliberately not closed by M2.6. Two of the six are the reason: `gender` and `marital_status` carry legal weight in Indonesian notarial practice — spousal consent and capacity questions turn on them — so deciding whether they appear, where, and with what vocabulary is domain specification, not a decision a quality gate may take (CLAUDE.md §62). The other four are ordinary address granularity and could be added mechanically, but splitting the six would leave the Individual address half-complete for no stated reason. Closing this needs one decision covering all six: either they belong in the interface, in which case the forms and detail pages gain them together, or they do not, in which case the labels and the frontend types should go and the API fields should be documented as inbound-only. Recorded at M2.6 rather than guessed at. |
| O-031 | The Party Directory's **Office filter is built from the Offices present in the current page of results**, not from an endpoint. The two options endpoints that exist answer a different question — `individuals/options` and `companies/options` list the Offices an actor may **create** in, which is neither necessary nor sufficient for reading — so offering those would show destinations that return nothing and hide ones that return rows. | Open, and deliberate rather than overlooked. The derivation is honest: it can never offer an Office the caller's capabilities do not already reach, and selecting one only narrows, because the backend applies `office_id` on top of each capability's own scope predicate. The cost is that the choices reflect the page in view, so an Office whose rows fall on a later page is not offered until the caller reaches it. Closing this needs a **view-scoped** Offices source — and the honest version of it is not one list but two, since `parties.view` and `companies.view` are evaluated independently and may reach different Offices (D-028). That is a small API addition with a real design question inside it, which is why M2.5 did not invent one to fill a filter. Revisit when a second surface needs the same list. |
| O-032 | The frontend has **no test runner**. Its quality gate is `format:check`, `lint`, `typecheck`, and `build`, so pure frontend logic — `visibleNavigation`, `can`/`canWithScope`, the duplicate-advisory gate — is verified by typecheck, deterministic source scans, and runtime behaviour through the API, never by an executed unit test. | Open. Not new at M2.5, but M2.5 is the first milestone where it costs something specific: `anyPermissions` is a branch whose three cases (`parties.view` only, `companies.view` only, neither) are exactly what a four-line test would pin, and none of them is currently pinned by anything executable. The backend equivalents *are* tested, and the backend is the security boundary, so this is a correctness gap in presentation rather than a hole in authorization. Adding a runner is a real decision — which one, whether it joins `quality.yml`, and the CLAUDE.md §52 rule that the documented command list must never be weaker than CI — and it should not be made incidentally inside a feature milestone. Worth an explicitly scoped task before the navigation tree grows the Notary and PPAT groups. |
| O-016 | The Laravel skeleton ships `backend/.editorconfig` with `root = true`, which halts the upward search. The repository `.editorconfig` and D-011 therefore do not apply anywhere inside `backend/`. Both agree that PHP uses 4 spaces, so no PHP file is affected. They diverge for JSON and JavaScript: the root file says 2 spaces, the backend file falls through to its own 4-space default. Affects `backend/composer.json`, `backend/package.json`, and `backend/vite.config.js`. | **Resolved 2026-08-09.** `backend/.editorconfig` deleted; the root file now governs `backend/`. Every rule it carried already existed in the root file, except `[compose.yaml] indent_size = 4`, which targets a Laravel Sail file that does not exist — `backend/` contains no YAML at all. Verified with the reference `editorconfig` resolver, not by inspection. No decision was superseded; D-011 gained a scope note instead. |

---

**Status:** Active register
