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

## Open Items

Not decisions — conflicts or gaps that remain unresolved.

| ID | Item | Status |
|---|---|---|
| O-001 | `01_ARCHITECTURE.md` section 2 did not reflect D-003 | **Resolved 2026-08-08.** Section 2 now carries the canonical 12-entry structure and cross-references `10_M0_FOUNDATION.md` and D-003. See D-010. |
| O-002 | `CLAUDE.md` stated the technology stack without versions | **Resolved 2026-08-08.** Section 3 now states Next.js 16.x, Node >= 20.9, Laravel 13.x, PHP >= 8.3, and adds Database and Infrastructure subsections (PostgreSQL 18.x, Redis 8.x, private file storage). |
| O-003 | `CLAUDE.md` section 58 listed ten `/docs` files | **Resolved 2026-08-08.** Section 58 now lists all 14 entries and restates the 08/09 draft restriction and the `DECISIONS.md` precedence rule. |
| O-004 | Milestone M2 is labelled "Party / Individual / Company" in `00_PROJECT_OVERVIEW.md` and "Client Database" in the source PDF | **Deferred 2026-08-08.** Cosmetic only. Must not block foundation development. Not to be touched during unrelated steps. |
| O-005 | `.editorconfig` used a single 4-space default, conflicting with Prettier and the Next.js scaffold | **Resolved 2026-08-08.** See D-011. Per-ecosystem indentation now explicit. |
| O-006 | `.github/` contains only `.gitkeep`. No CI workflow exists. | **Deferred 2026-08-08.** Deferred until the repository has executable quality gates for both frontend and backend — that is, until `pnpm lint/typecheck/build` and `pint --test` / `php artisan test` can actually run. Explicitly **not** a blocker for M0. |
| O-007 | The working directory was not a Git repository, leaving the first M0.1 acceptance criterion in `10_M0_FOUNDATION.md` section 67 unmet | **Resolved 2026-08-08.** Repository initialized on `main` with three commits covering tooling, specifications, and `CLAUDE.md`. See D-012. |
| O-009 | No GitHub remote existed; `gh` CLI is not installed | **Resolved 2026-08-08.** Private repository created through the browser; `origin` added and `main` pushed. Local and remote both at `93ff35b`. See D-012. |
| O-014 | The shadcn `nova` preset installs the **Geist** font. `04_UI_DESIGN_SYSTEM.md` recommends **Inter**. (The item originally cited section 6; the typography guidance is in section **4**.) | **Resolved 2026-08-09.** Inter implemented through `next/font`, self-hosted, no runtime external font request. Geist removed from source and build output. No new decision was required — Inter is the only typeface the design system names, and D-017 had already recorded Geist as an incidental preset default. Separately fixed while doing so: `--font-sans: var(--font-sans)` in the scaffold CSS was self-referential, so no custom sans had ever actually applied. |
| O-015 | The Next.js scaffold generated `frontend/AGENTS.md` and `frontend/CLAUDE.md`. The latter is an 11-byte pointer containing only `@AGENTS.md`. | Open. Both were kept as standard scaffold output. They are Next.js coding hints, not project rules, and do not contradict the root `CLAUDE.md`. Remove them if a second instruction file in the repository is unwanted. |
| O-010 | `gh` CLI is still not installed. Remote repository administration — visibility, branch protection, collaborators, settings — cannot be inspected or changed from this terminal. | Open. Not a blocker. Git operations over HTTPS work using the stored credential. Install `gh` only if repository administration from the terminal becomes useful. |
| O-008 | Node.js v25.9.0 was in use; the v25 line is EOL and is not an LTS line | **Resolved 2026-08-08.** Migrated to Node 24.19.0 LTS via nvm-windows. Verified in a clean shell: `node v24.19.0`, `npm 11.17.0`, single resolution at `C:\Program Files\nodejs\node.exe`. See D-013. |
| O-011 | Herd's `bin` was not on PATH, so `composer` and `laravel` failed with `'php' is not recognized` | **Resolved 2026-08-08.** Herd reinstalled; `C:\Users\User\.config\herd\bin` now present in the persisted USER PATH. `php`, `composer`, `laravel`, and `herd` all resolve. |
| O-012 | Three Herd PHP extensions failed to load from a missing directory | **Resolved 2026-08-08.** The Herd reinstall fixed it. `php --version` is now warning-free, and `redis`, `mongodb`, and `herd` all appear in `php -m` — they load rather than merely being silenced. |
| O-013 | pnpm not installed | **Resolved 2026-08-08.** `corepack enable pnpm` → pnpm 11.20.0. See D-015. |
| O-020 | `02_MENU_AND_PERMISSIONS.md` section 4 defines a `SUPER_ADMIN` role, but no bypass exists and none was added at M0.8. Whoever seeds that role in M1 will be tempted to reach for `Gate::before(fn ($user) => $user->hasRole('SUPER_ADMIN') ? true : null)`, which is the package's own documented shortcut. | Open by design, recorded so it is a deliberate choice rather than a reflex. A `Gate::before` bypass makes every `can()` in the system return true for those users, silently defeating record-state rules, finalization locks, and sensitive-data permissions — exactly the controls `07_SECURITY_RULES.md` sections 20 and 23 exist to enforce. If it is introduced it needs explicit security review and its own decision entry, not a one-line addition to a service provider. |
| O-019 | `users.id` is a Laravel `bigint` autoincrement. `CLAUDE.md` section 11 and `06_API_CONVENTIONS.md` section 14 say domain resources should use ULID; `10_M0_FOUNDATION.md` section 45 exempts only third-party package tables, and `users` is our own model. `GET /api/v1/me` therefore returns a numeric id. | **Resolved 2026-08-09,** ahead of M0.8 rather than deferred to M1: Spatie's polymorphic morph keys must match the User key type, so the correction had to land before the package was installed. `users.id` and `sessions.user_id` are now `char(26)` ULIDs, the model uses `HasUlids`, and `CurrentUser.id` is typed `string`. Verified end to end against PostgreSQL with database sessions. See D-023 for why the scaffold migration was edited in place. |
| O-018 | `setRequestLocale` is deprecated in next-intl 4.13.5, which points at [`next/root-params`](https://next-intl.dev/blog/nextjs-root-params). It is currently load-bearing: it is what keeps `/id` and `/en` prerendered. | Open. Migration is blocked, not merely deferred — `next/root-params` exists in Next.js 16.3.0, but next-intl 4.13.5 contains no reference to it, so the library cannot yet source the locale that way. Revisit when next-intl ships root-params support. Until then the deprecated call stays, because removing it would make every locale route server-rendered on demand. |
| O-017 | A localized not-found state does not render for unmatched URLs. Next.js uses the **root** not-found for those; a nested `[locale]/not-found.tsx` only catches `notFound()` thrown inside its own segment, and the proxy guarantees the locale segment is always valid. | Open. Written during M0.6, verified non-functional, and removed rather than left as dead code. Making it work requires a catch-all route under `[locale]`, which is a routing change beyond M0.6's presentational scope. The built-in Next.js 404 remains, as it did after M0.5. `BaseErrorState` is ready to render it when the catch-all is added. |
| O-016 | The Laravel skeleton ships `backend/.editorconfig` with `root = true`, which halts the upward search. The repository `.editorconfig` and D-011 therefore do not apply anywhere inside `backend/`. Both agree that PHP uses 4 spaces, so no PHP file is affected. They diverge for JSON and JavaScript: the root file says 2 spaces, the backend file falls through to its own 4-space default. Affects `backend/composer.json`, `backend/package.json`, and `backend/vite.config.js`. | **Resolved 2026-08-09.** `backend/.editorconfig` deleted; the root file now governs `backend/`. Every rule it carried already existed in the root file, except `[compose.yaml] indent_size = 4`, which targets a Laravel Sail file that does not exist — `backend/` contains no YAML at all. Verified with the reference `editorconfig` resolver, not by inspection. No decision was superseded; D-011 gained a scope note instead. |

---

**Status:** Active register
