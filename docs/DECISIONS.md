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
| O-010 | `gh` CLI is still not installed. Remote repository administration — visibility, branch protection, collaborators, settings — cannot be inspected or changed from this terminal. | Open. Not a blocker. Git operations over HTTPS work using the stored credential. Install `gh` only if repository administration from the terminal becomes useful. |
| O-008 | Node.js v25.9.0 was in use; the v25 line is EOL and is not an LTS line | **Resolved 2026-08-08.** Migrated to Node 24.19.0 LTS via nvm-windows. Verified in a clean shell: `node v24.19.0`, `npm 11.17.0`, single resolution at `C:\Program Files\nodejs\node.exe`. See D-013. |
| O-011 | Herd's `bin` was not on PATH, so `composer` and `laravel` failed with `'php' is not recognized` | **Resolved 2026-08-08.** Herd reinstalled; `C:\Users\User\.config\herd\bin` now present in the persisted USER PATH. `php`, `composer`, `laravel`, and `herd` all resolve. |
| O-012 | Three Herd PHP extensions failed to load from a missing directory | **Resolved 2026-08-08.** The Herd reinstall fixed it. `php --version` is now warning-free, and `redis`, `mongodb`, and `herd` all appear in `php -m` — they load rather than merely being silenced. |
| O-013 | pnpm not installed | **Resolved 2026-08-08.** `corepack enable pnpm` → pnpm 11.20.0. See D-015. |

---

**Status:** Active register
