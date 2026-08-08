# Notary & PPAT Office Management System
## Documentation Changelog

Records changes to the specification documents only. No application code exists yet.

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
