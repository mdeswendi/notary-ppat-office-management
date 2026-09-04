# Project Handoff — M0 through Baseline Packaging

**Position:** branch `main`, at commit `0e87b5f`. **M0 through M8 are all merged to `main` and
feature-complete.** M8 itself merged `--no-ff` at `7d2cc1a`; eleven UI/backend housekeeping commits
landed directly on `main` after that (§3, §7); a six-commit baseline-recovery pass then merged via
PR #1 (`chore/repository-baseline` → `main`, merge commit `0e87b5f`, also `--no-ff`).
**Last accepted merge to `main`:** baseline packaging, PR #1, merge commit `0e87b5f`.
**Written:** 2026-08-24, after M5.2; figures refreshed through M8.3. **Fully re-verified and
rewritten 2026-09-05**, after the M8 merge, the post-M8 UI/backend housekeeping pass, and baseline
packaging — every figure below was recomputed from source and Git, not copied forward.

This is an orientation document for whoever picks the project up next — a person or a new session.
It is **not** a summary of `CHANGELOG.md`, which already records what each milestone did and why. It
answers four questions the changelog does not: *where are we*, *what must not be broken*, *what is
deliberately missing*, and *what comes next*.

Read `CLAUDE.md` first. It is the constitution and it overrides this file wherever they disagree.

---

## 1. What the system is

A bilingual (id / en) web application for an Indonesian **Notary and PPAT office**. Not a CRM: the
Notary and PPAT domains are separate legal practices that share infrastructure, and the code keeps
them separate on purpose.

```text
Browser → Next.js 16 (App Router) → REST /api/v1 → Laravel 13 → PostgreSQL 18
                                                              → Redis 8
                                                              → private file storage
```

Two applications in one repository: `frontend/` and `backend/`. Laravel is the **only** security
boundary. Every frontend permission check is presentation.

---

## 2. Where we are — by the numbers

Every figure below was recomputed from source and Git on 2026-09-05, at commit `0e87b5f`. Method
is stated inline for anything that isn't a plain file count, per the rule that a number copied
forward without re-checking it is a number nobody has actually verified.

| | |
|---|---|
| Milestones complete | **M0 – M8, all feature-complete and merged to `main`** · plus a post-M8 UI/backend housekeeping pass and a baseline-recovery packaging PR, both also merged (§3, §7) |
| Migrations | **58** (`ls backend/database/migrations/*.php \| wc -l`) |
| Canonical permissions | **177** — unchanged since the catalogue was transcribed at M1.2; confirmed via `PermissionRegistry::all()` |
| Models | 43 (`find backend/app -path '*/Models/*.php'`) |
| API routes | **244** under `/api/v1`, **250 total** (`php artisan route:list --json`). The M8.3 changelog entry recorded 251 total; recount today is 250 — corrected here rather than carried forward unverified. |
| Backend tests | **2969 passing, 8 skipped, 8957 assertions**, across **91** `*Test.php` files under `backend/tests/` (Pest, in-memory SQLite) |
| Frontend tests | **249 passing** across **28** files (Vitest + RTL) |
| Frontend pages | 69 (`find frontend/src/app -name page.tsx`) |
| Decisions recorded | **D-001 … D-132** |
| Open items | **27 still open** (§7) — recount of `docs/DECISIONS.md`'s Open Items table (51 entries total, O-001…O-051; 24 resolved, 27 open). The previous count here said 26; O-015's status text ("remains open") was being missed by a plain grep for the word "Open" — corrected. |

**The persistent development database now stands at all 58 migrations, fully caught up** —
verified read-only via `php artisan migrate:status` against the configured `notary_ppat_office`
database: every one of the 58 migrations reports `Ran`, none pending. This is a change from the
last time this document was written, when the same database stood at 42 and was missing M7 and M8
entirely.

*(This section previously said 42 and, before that, 22 — both true at the time they were written.
The figure keeps being corrected rather than the history rewritten, because which milestone a dev
database sits at is a fact about somebody's machine, not a project invariant. It is not this
document's job to explain who ran the migration or when — only to state what is true now.)*

**What has not changed is the working rule**: schema verification for new work still runs on a
**disposable** database created and dropped for the purpose — see §6. The persistent database
being caught up does not license migrating it directly for a milestone in progress; disposable
verification remains the standing rule regardless of what the persistent database happens to hold
at any given moment.

### Routes by domain

Recounted from `php artisan route:list --json`, grouped by the first `/api/v1/…` path segment:

```text
ppat 40          notary 29        reports 23       companies 19     projects 15
individuals 14   users 13         documents 12     security 12      tasks 12
invoices 11      properties 9     quotations 8     roles 7          dashboard 6
disbursements 4  payments 3       profile 2        health 1         audit-logs 1
me 1             permissions 1    parties 1
```

Total: 244, matching the `/api/v1` figure above. The `reports`, `dashboard`, `invoices`,
`quotations`, `disbursements`, `payments`, and `audit-logs` groups did not exist the last time this
table was written — they are M8's Dashboard, Billing, and Reports surfaces landing in the count for
the first time.

`ppat` gained nine at M7.2 — index, store, show, update, options, review, approve, finalize and
number — three more at M7.3 for the Matter/Property junction, and **eleven at M7.4** for Warkah.
There is deliberately no `destroy` on a deed: see O-039 and the M7.2 entry in `CHANGELOG.md`.

**The Warkah routes nest under the deed and are named for their capability family.** The URIs are
`ppat/deeds/{deed}/warkah/…` because a bundle has no existence apart from one deed; the names are
`api.v1.ppat.warkah.*` because a route name is checked against the code that authorizes it, and these
answer to `ppat.warkah.*` rather than `ppat.deeds.*`. There is one top-level address,
`GET /ppat/warkah`, which answers the one question a per-deed page cannot: *which bundles are still
short?*

**`properties` is its own root, not `ppat/properties`**, and the asymmetry is deliberate. The
canonical family is `properties.*` with no `ppat.` prefix, and D-101 says the route decides the
permission namespace — so the endpoint matches the codes while the *page* sits at `/ppat/properties`,
because `CLAUDE.md` §16 lists Property among the PPAT-specific concepts. A page path is not a
permission namespace. There is no `DELETE` here either: `properties.delete` is absent and
`properties.archive` soft-deletes (O-045).

---

## 3. How we got here

Each milestone opens with a **`.0` architecture lock** — a document that records what the domain may
build, what it must not, and which statements are transcribed from canonical sources rather than
decided locally. The lock is written and accepted *before* any code. That habit started at M1 and is
the single most load-bearing process choice in the project.

| Milestone | What it delivered | Merge / head |
|---|---|---|
| **M0** Foundation | Repo, tooling, Docker, bilingual routing, UI foundation, Sanctum auth, app shell, CI | `8be0ad0` |
| **M1** Identity & Access | Organization/Office, canonical permission registry, Data Scope resolver, roles, users, permission matrix, bootstrap, permission-aware navigation, profile, account security | `501401f` |
| **M2** Party | Party schema, Individual, Company, company relationships, directory, duplicate detection, reverse view | `fdda4e4` |
| **M3** Project | Project schema, internal reference allocator, core management, Project ↔ Party participation | `c17684e` |
| **M4** Matter & Workflow | Service Types, Matter schema, allocator, core management, Matter ↔ Party participation, workflow templates, running workflow instances | `f82dc25` |
| **M5.0** | Document / Task architecture lock; turned the private disk's `serve` flag off | `0890fec` |
| **M5.1** | Document schema, private storage, `DOC-` allocator, three junctions, Policy — no routes | `6f495f8` |
| **M5.2** | Nine document endpoints, four pages, Project/Matter document sections | `3dec054` |
| **M5.3** | Document relation surfaces (attach / detach), Party document sections | `077365b` |
| **M5.4** | Task schema, twelve endpoints, five pages, Project/Matter task sections | `6d0c2e9` |
| **M6.0** | Notary architecture lock — and what the empty specification actually costs | `bec5dd5` |
| **M6.1** | `notary_matters` + `notary_deeds` schema, Policy, Data Scope — no routes | `33dfe32` |
| **M6.2** | Nine deed endpoints, three pages, Matter deeds section | `8c638d4` |
| **M6.3** | `notary_minuta` metadata, three nested endpoints, deed-page section | `9bef689` |
| **M6 merge** | M5 and M6 both entered `main` here — M5 had never been merged on its own | `cc56a4f` |
| **O-037** | Notary Deeds on the Project page, as a `project_id` filter | `fa381fa` |
| **M7.0** | PPAT architecture lock — nine open questions, seven of them M7's | `aa0c251` |
| **M7.1** | Property + PPAT schema (eight tables), two Policies, Data Scope — no routes | `0d04c07` |
| **M7.2** | Nine PPAT deed endpoints, three pages, Matter and Project deed sections | `55b9655` |
| **M7.3** | Property surface, chain of title, Matter/Property junction — twelve routes | `1a18e14` |
| **M7.4** | Warkah: eleven routes, four of the six codes, deed section and list | `3010a86` |
| **M7 validation** | Full QA sweep — 84/84 E2E, 60/60 authorization, schema constraints | `2c1e77e` |
| **M7 merge** | `--no-ff` merge of `feat/m7-ppat`; migrations 42 → 50 | `d3bd0b4` |
| **M8.0** | Dashboard / Billing / Reports lock — capabilities without a schema, and batch 7 comes due | `af7d0d6` |
| **M8.1** | Dashboard + audit & activity foundation; D-115 closes | `2d8d331` |
| **M8.2** | Billing: seven tables designed, twenty-six routes, no tax anywhere | `e3ae655` |
| **M8.3** | Reports: five families, twenty-three routes, no migration, no statutory return | `616daba` |
| **M8 merge** | `--no-ff` merge of `feat/m8-dashboard`; **M0–M8 all feature-complete and merged to `main`** | `7d2cc1a` |
| **Post-M8 housekeeping** | Eleven commits directly on `main`: an auth error-mapping fix, six shared-UI consolidations (`ButtonLink`, `PageHeader`, `Card`, `Badge`, `Select`, an empty-vs-failed list distinction), a doc correction, a dashboard/shell defect repair, a filter-row sizing fix, and naming the office in the header from its own record. Detail in the subsection immediately below. | `d36fedf` … `02e260a` |
| **Baseline packaging** | PR #1, `chore/repository-baseline` → `main`: unused Laravel Vite/Blade scaffold removed, a sidebar scroll-position fix, `AGENTS.md` added, the office backup/restore runbook added, the end-user manual added, and a review-driven fix removing an obsolete Composer `dev` script. Six commits, merged with a merge commit (two parents), not squashed or rebased. | `0e87b5f` |

M0's history is unusually granular (M0.1 → M0.10) because the environment itself was being
established. From M1 onward the shape is stable: lock → schema → allocator → management → frontend →
quality gate.

### Post-M8 housekeeping, in detail

Two batches of work landed on `main` after the M8 merge, neither of which is a milestone and neither
of which touches the Notary/PPAT domain model, workflow, or authorization surface. Both are
housekeeping in the sense `CLAUDE.md` §67 describes: small, meaningful, separately reviewable
changes — not "finished app" in one commit.

**Eleven UI/backend commits, `d36fedf` … `02e260a`** (pre-existing work found already on `main`
when the baseline-recovery audit below began — not produced by that audit):

- **Shared-component consolidation** — six commits drawing every instance of a UI pattern through
  one shared component instead of several local variants: `ButtonLink` (a link styled as a button),
  `PageHeader` (adopted across every page), `Card` (unifying three prior spellings of the section
  card), `Badge` (every status chip), `Select` (every dropdown), and a distinction between an empty
  list and a failed request so the two states stop looking identical to the reader.
- **Visible defect repairs** — `36f8ac4` fixed three visible defects in the shell and dashboard;
  `7000e98` sized `Select` like `Input` so filter rows line up.
- **Error-state improvement** — `d36fedf` makes a non-JSON API request answer `401` instead of a
  raw `500`, so an authentication failure reads as authentication failure rather than a server
  crash.
- **Office identity** — `02e260a` names the office in the application header, read from the
  Office's own record rather than left as a placeholder.
- **Documentation correction** — `a0cbabf` fixed eleven places still claiming the D-115 sensitive-
  download gate held, after M8.1 had already closed it (§7).

**Baseline packaging, PR #1, `63525e1` … `9562023`, merged at `0e87b5f`** — a housekeeping and
baseline-recovery pass, audited, packaged into six commits, and merged as its own PR:

- **`chore(backend): remove unused Laravel Vite and Blade scaffold`** — removed the default
  `laravel new` Vite/Blade asset pipeline (welcome view, `vite.config.js`, `backend/package.json`,
  compiled asset entrypoints, the default placeholder Feature test) and the two references to it in
  `composer.json` and `routes/web.php`. It was never wired to anything — the Next.js frontend is
  the only frontend this project serves (`CLAUDE.md` §4).
- **`fix(ui): preserve sidebar scroll position across navigation`** — the sidebar is now `sticky`
  with its own internal scroll container, so navigating between pages no longer resets a reader
  scrolled deep in the menu back to the top.
- **`docs: add Codex repository instructions`** — added `AGENTS.md` as a Codex-facing mirror of
  `CLAUDE.md` (only the title, one repository-structure line, and §59's heading/first instruction
  differ; every rule and example is otherwise identical). `CLAUDE.md` §4's expected root structure
  already anticipated this file.
- **`docs(ops): add office backup and restore runbook`** — `scripts/backup.ps1`,
  `scripts/restore.ps1`, `scripts/README.md`. Implements the restore-testing requirement
  `docs/07_SECURITY_RULES.md` §28 already states in prose: dumps PostgreSQL from the running Docker
  container, mirrors `backend/storage/app/private` additively (never `/MIR`, so an accidental local
  deletion is never propagated into the backup), and copies `APP_KEY` alongside it — without the
  key, the `'encrypted'` `nik`/`npwp` casts on `Individual` and `Company` are permanently
  unreadable even with the database intact. Restore defaults to a throwaway test database; the
  production path requires typing `PULIHKAN`. Reviewed statically; never executed as part of this
  work.
- **`docs: add end-user manual`** — `user_manual.html`, a standalone, self-contained walkthrough of
  the product for office staff. Checked twice for real client data, credentials, NIK, NPWP,
  passwords, tokens, and `APP_KEY` before being committed — none found.
- **`fix(backend): remove obsolete Composer dev script`** — a review-driven follow-up: the
  Composer `"dev"` script still invoked `npx concurrently …` after `backend/package.json` (which
  carried the `concurrently` dependency) had already been deleted by the first commit, so it
  depended on a global/cached `npx` install and was not reproducible on a fresh clone. Removed
  outright rather than rewritten — README already documents running the backend and frontend in
  two separate terminals (§9 below), which is what this script duplicated.

Both batches were verified against the full local quality gate (§9) before merging; the full
result is not repeated here since it is a point-in-time CI-equivalent record rather than a project
fact — see the PR #1 description on GitHub for the exact numbers at that commit.

---

## 4. The invariants — what must not be broken

These are not style preferences. Each one has a decision behind it and a test that fails if it is
violated.

### 4.1 Authorization

**Never authorize on a permission code directly.** `$user->can('documents.view')`,
`Gate::allows(...)`, `hasPermissionTo()`, `getAllPermissions()` and role-name checks are all
forbidden — they carry no Data Scope, ignore `user_permission_overrides`, and count Spatie's direct
grants which the model excludes. Spatie's permission Gate integration is **switched off**, so those
calls fail closed, and a test scans `app/` for them (D-048, O-027).

The one path is:

```text
Controller::authorize → Policy → EffectiveAccessResolver → Data Scope predicate
```

**Data Scopes are predicates, never a ladder** (D-028). `OWN`, `ASSIGNED`, `TEAM`, `OFFICE`, `ALL`.
Multiple grants **union**; no scope outranks another; there is no `maxScope`; an unknown scope fails
closed. `TEAM` is never assignable — no Team entity exists (D-042).

**A scope offered in the Permission Matrix must be a scope the resolver can honour.** Withholding a
predicate in code while the Matrix still offers it produces a grant an administrator can save that
silently does nothing — the "dead control" D-080 named. `PermissionScopeRules` and the visibility
classes are kept in agreement deliberately.

**`SUPER_ADMIN` has no bypass.** It holds a broad explicit permission set (D-032).

**`*.view_all` codes are superseded by Data Scope `ALL`** and are consulted by nothing (D-090).

### 4.2 Office boundaries are structural, not validated

Every cross-record relationship that must stay inside one Office carries an `office_id` **constraint
carrier** with composite foreign keys resolving through it:

```text
party_documents (party_id,    office_id) → parties   (id, office_id)
party_documents (document_id, office_id) → documents (id, office_id)
```

A cross-office row is *unrepresentable*, not merely refused — **including for an actor holding
`ALL`**, because `ALL` is reach over records that already exist and never authority to decide which
Office a new one belongs to. Used by `company_people`, `project_parties`, `matters`, `matter_parties`,
`workflow_templates`, and all three document junctions.

### 4.3 Legal documents

- **Private storage only.** `storage_path` may never contain `public/` or `uploads/` — enforced in the
  service, by a PostgreSQL `CHECK`, and by a model guard so it holds on SQLite too.
- **No URL, ever.** No signed URL, no temporary URL. A URL that authorizes by possession is a second
  authorization path beside the Policy chain. M5.0 removed the two `storage/{path}` routes that
  existed (D-114). Files stream from a surface that authorized the actor against the record first.
- **A version is written once.** `DocumentVersion` refuses `update` outright; a correction is a new
  version, and the previous bytes stay exactly where they were.
- **Sensitivity is a separate capability, not a scope.** `documents.sensitive.view` and
  `documents.sensitive.download` neither imply nor are implied by the ordinary codes (D-115).

### 4.4 Numbering

Internal references (`PRJ-`, `N-`, `P-`, `DOC-`) are **ordinary office identification and never legal
numbers** — not deed numbers, not repertorium entries. Each domain has its own allocator over its own
namespace, and every one uses a single atomic statement:

```sql
INSERT … ON CONFLICT … DO UPDATE SET last_value = last_value + 1 RETURNING last_value
```

`MAX+1`, `COUNT+1` and read-then-write are forbidden. Allocators open no transaction of their own, so
they join the caller's — a rolled-back create does not spend a number.

The reference classes are **formatters, not parsers**. Nothing reads a year or sequence back out of a
formatted string (D-108).

### 4.5 Invent no legal rules

`CLAUDE.md` §62. When the specification does not define a legal rule, the project **stops, records the
gap, and asks** — it does not guess. This is why:

- `08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are marked `DRAFT — DOMAIN VALIDATION REQUIRED`
  and may not be implemented from.
- M4 built a workflow **mechanism** and seeded **no workflow content** (D-104).
- `document_type_code` and `role_code` are opaque strings with no enum and no `CHECK` — `KTP`, `NPWP`
  and `AKTA` are examples in prose, not a validated catalogue.
- Requirement templates and stage gating are deferred to M6/M7 with the legal content that would
  justify them.

### 4.6 Vocabulary that exists but cannot be reached

Where the product cannot set a canonical status, that is **recorded rather than implied** (the D-109
precedent). Currently unreachable:

```text
Matter    IN_PROGRESS  WAITING  ON_HOLD  ARCHIVED   (no change_status capability exists)
Document  DRAFT  UNDER_REVIEW  FINAL  VOID
```

Filters and badges still render them, because reading is not writing. What the interface never offers
is a control claiming to *set* one.

---

## 5. Documentation map

```text
docs/
├── 00_PROJECT_OVERVIEW.md
├── 01_ARCHITECTURE.md
├── 02_MENU_AND_PERMISSIONS.md     ← the canonical 177-permission catalogue
├── 03_DATABASE_ERD.md             ← canonical field lists; transcribe, do not tidy
├── 04_UI_DESIGN_SYSTEM.md
├── 05_I18N_LEGAL_TERMINOLOGY.md
├── 06_API_CONVENTIONS.md
├── 07_SECURITY_RULES.md
├── 08_NOTARY_WORKFLOW.md          ← DRAFT — DOMAIN VALIDATION REQUIRED
├── 09_PPAT_WORKFLOW.md            ← DRAFT — DOMAIN VALIDATION REQUIRED
├── 10_M0_FOUNDATION.md
├── 11_LEGAL_REFERENCES.md         ← statutory register only; confers no operational rules
├── 12_M2_PARTY_ARCHITECTURE.md    ┐
├── 13_M3_PROJECT_ARCHITECTURE.md  │ milestone architecture locks — read the one
├── 14_M4_MATTER_ARCHITECTURE.md   │ for the domain you are changing
├── 15_M5_DOCUMENT_TASK_ARCHITECTURE.md │
├── 16_M6_NOTARY_ARCHITECTURE.md   │
├── 17_M7_PPAT_ARCHITECTURE.md     │ ← read §5 of these two first
├── 18_M8_DASHBOARD_BILLING_REPORTS_ARCHITECTURE.md ┘ ← §5 inverts M6/M7: capabilities, no schema
├── DECISIONS.md                   ← D-001…D-132 + the Open Items register (51 items, 27 open)
├── CHANGELOG.md                   ← what each milestone did
└── HANDOFF.md                     ← this file
```

**`DECISIONS.md` wins.** Where older material conflicts with it, the newer explicit decision governs
unless later superseded. Where source code and documentation conflict: identify it, do not silently
pick one, **report it** (`CLAUDE.md` §58).

**Locks are amended in place, never rewritten.** M5.0's status line reads
`LOCKED — M5.0, amended at M5.1 and M5.2`, and §10.2 keeps its original "no transition matrix" ruling
verbatim with the supersession noted beneath it. A lock that quietly changed its mind would be worse
than no lock.

---

## 6. Working rules that are not in `CLAUDE.md`

These are standing constraints set during the project. They are not optional.

### Database safety

- **Never** run `migrate:fresh`, `db:wipe`, or `docker compose down -v` against persistent data.
- **Do not migrate the persistent development database directly for milestone work.** It stands at
  all 58 migrations as of 2026-09-05 (see §2 — the figure is a fact about somebody's machine, not a
  target, and it will drift again). All schema verification for new work still runs on a
  disposable database (`m44_smoke`, `m51_probe`, `m52_probe`, `m71_probe`, `m72_probe`, …), created
  and dropped within the milestone — regardless of what the persistent database happens to hold.

### HTTP smoke testing

- Before the first functional request, the **actual serving process** must prove its own database with
  `SELECT current_database()`. A shell `DB_DATABASE` override is *not* evidence: **O-034** records that
  `php artisan serve` drops the override for its own `php -S` child, so a milestone can migrate the
  right database and serve the wrong one. Launch `php -S <host:port> -t backend/public <router>` with
  the working directory set to `backend/public`, and probe regardless of launcher.
- Real Sanctum cookie session, CSRF cookie, `X-XSRF-TOKEN` header, `Origin` and `Referer` headers.
  **No Bearer authentication anywhere** — omitting `Origin`/`Referer` makes Sanctum classify the
  request as a token client and answer 401.

### Git

No force-push. No rebase of accepted history. No amend or squash of accepted milestone commits. No
rewriting `main`. Merges use `--no-ff`.

### Reporting

- **Never claim GitHub Actions passed.** State local results; CI is the user's to observe.
- End a milestone report with exactly one classification line.
- Do not opportunistically fix open items — they are scoped work.

### Data handling

Never copy NIK, NPWP, tax IDs or any Party sensitive identity into Project, Matter, participation,
workflow or document tables, browser storage, URLs, query keys, or logs.

---

## 7. Open items still open

**Twenty-seven of fifty-one** (recounted 2026-09-05 directly from `docs/DECISIONS.md`'s Open Items
table: 51 entries total, O-001…O-051, 24 resolved and 27 open). Each is recorded there with its
full reasoning; this is the index — minus the five M7 scope items (**O-039** … **O-043**), which are
described where they matter, in §8. (This previously said "twenty-five of fifty" — a straight
recount, not a change in what's open; the fifty-first item, O-051, is added to the table below.)

| ID | One line | Why it is still open |
|---|---|---|
| O-010 | `gh` CLI not installed | Not a blocker; HTTPS Git works |
| O-015 | `frontend/AGENTS.md` + `CLAUDE.md` are regenerated by `next dev` | Needs an upstream opt-out, not a deletion |
| O-017 | No localized 404 for unmatched URLs | Needs a catch-all route — routing work |
| O-018 | `setRequestLocale` deprecated but load-bearing | Blocked upstream: next-intl cannot yet source locale from `next/root-params` |
| O-021 | No desktop sidebar collapse | Deferred; revisit when the rail earns its complexity |
| O-022 | Header search / quick create / notifications absent | Each needs a module that does not exist |
| O-024 | `user_permission_overrides` has no `updated_at` | The real answer is the audit log |
| O-025 | Spatie pivots have no FK; a mass-delete would orphan rows | No deletion path exists today |
| O-029 | Per-user permission overrides have no admin surface | Needs audit + a considered UI first |
| O-031 | Party Directory's Office filter is derived from the page | Needs a view-scoped Offices endpoint |
| O-033 | Six Party fields are stored, typed and translated but never rendered | Two carry legal weight — domain specification, not a quality-gate call |
| O-034 | `artisan serve` drops `DB_DATABASE` for its subprocess | Needs upstream change or a committed smoke launcher |
| O-035 | Five of the seven Notary domain questions are rules a deed surface would encode | M6 stores the vocabulary and reaches none of it (D-120) |
| O-036 | Notary Protocol has a menu entry, an ERD table, and no permission codes | Batch 11, and the catalogue would have to gain four codes |
| O-038 | No list surface accepts a sort parameter | Cross-cutting product decision, not an M6 defect |
| O-045 | Archiving a Property cannot be undone through the product | No `properties.restore` in the catalogue; archiving destroys nothing and the record stays readable |
| O-046 | A Property has no documents, because `property_documents` does not exist | Blocked since M5.1; unblocking is a migration plus an enum case, which M7.3 was scoped without |
| O-047 | `activities` is a canonical table with no canonical capability | Resolved for M8 as infrastructure read through its subject (D-123); reopens if anyone wants a user-authored timeline |
| O-048 | **Calendar is fully canonical and owned by no milestone** | Blocked on nothing but assignment — table, six event types, five codes, a menu entry, and no milestone from M0 to M8 names it |
| O-049 | Billing has seventeen capabilities and no ERD table at all | M8.2 designs the schema under D-124 — the first this project has designed rather than transcribed; the ERD should adopt it |
| O-050 | A verified payment has no correction path | No `payments.update`, no delete, no reversal verb; the verify gate is the only control |
| O-051 | No billing document — quotation, invoice, or disbursement — can be deleted | No catalogue delete code exists for any of the three; the `deleted_at` columns M8.2 built are stored and reached by nothing (same catalogue-extension question O-036/O-040/O-045/O-047/O-050 all wait on) |

**The largest structural gap is closed. `audit_logs` exists as of M8.1** (D-123), transcribed from
ERD §25, append-only structurally, and queryable by resource. D-115 is **resolved**: the
sensitive-download gate in `DocumentPolicy::download()` came out, `documents.sensitive.download`
authorizes something for the first time since M1.2, and every sensitive download writes a
`SENSITIVE_ACCESS` row. `audit.view` has a surface at `GET /api/v1/audit-logs`; `audit.export` does
not, and belongs with `reports.export` at M8.3.

**Three cautions for whoever works on this next.**

- **Neither table is backfilled** (D-123). The activity feed starts empty and fills forward, so an
  office that upgrades sees nothing there until the next thing happens. That is expected behaviour,
  and seeding it would put fabricated timestamps into a factual record.
- **`audit_logs.actor_user_id` is a plain foreign key, not the composite one every other office-owned
  table uses** (D-127). Do not "fix" it: `office_id` is the *subject's* Office, the actor may hold
  Data Scope `ALL` and be from elsewhere, and a composite key would make cross-office access — the
  event an auditor most needs — impossible to record.
- **Billing schema now exists and was designed, not transcribed** (D-124, O-049). Seven tables, and
  the ERD still defines none of them — O-049 asks it to adopt the shipped shape so it becomes canon
  rather than precedent. Read `18_M8_…ARCHITECTURE.md` §9 before changing any of it.
- **There is no `tax` column anywhere in billing, and none may be added** (D-129). An office showing
  PPN adds a line it names and prices itself; nothing computes a rate. That is the line keeping O-040
  intact.
- **Settlement is computed, never stored.** No `paid_amount`, no `OVERDUE` status — the M5.4 `isOverdue()` precedent.

---

## 8. What M6, M7 and M8 built, rulings not to undo, and what comes next

**M0 through M8 are all complete, feature-complete, and merged to `main`** (§2, §3). Nothing in
this section describes future milestone work — there is no M9 in the plan (`CLAUDE.md` §2,
`01_ARCHITECTURE.md` §28 both end at M8) and none is invented here. What follows is kept for two
reasons: the specific "do not undo this" rulings below remain load-bearing even though the
milestones that produced them are finished, and the final subsection states honestly what kind of
work comes after a milestone plan that has run its full course.

### M5, closed out

M5 itself was already complete before M6 started; this is retained only because two of its own
open questions were answered later, by name, and a reader tracing either forward should land here.

```text
M5.5  (absorbed into M5.4 — the identifier stays retired)
M5.6  M5 quality gate
```

~~Audit is deliberately unnumbered — whether it becomes M5.2a or a prerequisite milestone is a scoped
decision for whoever takes it.~~ **Answered at M8.1**, which built it as part of the Dashboard and
audit foundation rather than as a retrofit into M5 (D-123). It was the one thing M5 named and did not
build, and `documents.sensitive.download` now authorizes a real download.

**The question M5.0 left for M5.4 is answered** (D-119): `created_by` was added, `OWN` is the creator,
`ASSIGNED` is the assignee, and the two are separate predicates that union when both are held.

### M6 and M7, in retrospect

```text
M6  Notary module   — Notarial Deeds, Minuta Akta         completed
M7  PPAT module     — PPAT Deeds, Property, Warkah        completed (taxes/registers/reports out of scope — see below)
M8  Dashboard, Billing & Reports                           completed
```

Both M6 and M7 were **blocked on domain validation** at the engineering level for everything beyond
the deed record itself — not on engineering capacity. The two workflow documents are still drafts
today (`08_NOTARY_WORKFLOW.md`, `09_PPAT_WORKFLOW.md` remain `DRAFT — DOMAIN VALIDATION REQUIRED`),
and four of the seven recommended document junctions
(`property_documents`, `notary_deed_documents`, `ppat_deed_documents`, `matter_requirement_documents`)
reference tables those milestones create. Both milestones were completed within that constraint,
not around it — the domain gaps below are still open (§7), not resolved by M6/M7 shipping.

**M6.0 (D-120) established what that blockage actually costs, and it is less than it sounds.** Five
of the seven questions in `08_NOTARY_WORKFLOW.md` §6 are rules a deed surface would ordinarily
encode; M6 stores the vocabulary the ERD names for each and **reaches none of it** — the D-109
pattern. What remains buildable is the deed record itself, its lifecycle ladder (which `CLAUDE.md`
§29 states outright, so it is not inferred from the draft), its document pointers, its Minuta
metadata, and the whole authorization surface.

```text
M6.1  notary_matters + notary_deeds schema + Policy   (no routes)   <- done
M6.2  Deed management surface + deed frontend                       <- done
M6.3  notary_minuta — metadata only                                 <- done
```

**M6 is complete as scoped.** What the domain gap costs, concretely: three canonical status values
(`VOID`, `SUPERSEDED`, `release_status`) and three canonical columns (`locked_at`, `archived_at`,
`archived_by`) are stored and reached by nothing, and four canonical capabilities
(`notary.minuta.archive`, `notary.minuta.release`, and both register and protocol families) stay
registered and unimplemented. Every one is waiting on `08_NOTARY_WORKFLOW.md` §5, not on engineering
(O-035).

**Registers and protocol are outside M6, not deferred within it.** `03_DATABASE_ERD.md` §32 puts them
in batch 11, later even than PPAT deeds, and the canonical protocol table is `protocol_records` — one
table with a domain discriminator and no deed junction, not the Notary-specific pair a reader might
expect (O-036).

M6.1 also removes the obstacle D-118 recorded for `notary_deed_documents`: it was blocked because
`notary_deeds` did not exist.

### M7 — PPAT, locked at M7.0 (D-121)

```text
M7.0  PPAT architecture lock                      <- done
M7.1  Property + PPAT schema + Policy   (eight tables, no routes)   <- done
M7.2  PPAT Deed surface + deed frontend (nine routes, no DELETE)    <- done
M7.3  Property + ownership history      (twelve routes, no DELETE)  <- done
M7.4  Warkah + completeness + frontend  (eleven routes, four of six codes)  <- done
```

**M7 is feature-complete.** All four PPAT navigation entries are in place and **O-044 is closed** —
each appeared in the milestone that landed its routes, and no placeholder was ever shipped despite
three briefs asking for one.

**What M7 deliberately did not build stays unbuilt**: taxes (no capability at all, O-040), registers
and protocol (batch 11, O-042), reports (M8, O-043), and the two Warkah acts whose trigger is open
question eight (O-041). None of those is a gap to fill on the way past — each needs a domain source
or a catalogue decision first.

**M8 — Dashboard, Billing & Reports — followed and is also complete.** See below.

**M7.4 note:** a Warkah reaches a deed through `ppat_warkah.ppat_deed_id` and answers to its own six
`ppat.warkah.*` codes. `PpatDeedResource` deliberately carries **no** Warkah key — a deed capability
must not become a way to read which supporting legal documents an office does or does not hold.

**M7 is M6's problem one degree worse.** `09_PPAT_WORKFLOW.md` §6 carries **nine** open questions to
Notary's seven, and **seven of the nine** bear on M7 (O-039). Read `17_M7_PPAT_ARCHITECTURE.md` §5
before touching anything.

**The finding that shaped the scope: `ppat.taxes.*` does not exist.** Not one code of it — verified
against the live registry, while every other PPAT family has been catalogued since M1.2. So
`ppat_tax_records` is a canonical table nothing may authorize, and the tax surface is **outside M7**
on four independent grounds (O-040). `notary.protocol.*` is absent the same way (O-036). **A canonical
table is not a canonical capability**, and that distinction now governs two milestones.

**Registers and protocol stay outside M7**, exactly as they stayed outside M6 — batch 11 against M7's
batches 8 and 10, so neither domain reaches a batch further than the other (O-042).

**Two rulings worth knowing before writing schema:**

- **`ppat_deeds` has no status vocabulary in the ERD.** M7 adopts Notary's six on `CLAUDE.md` §29's
  authority, but that is a **decision, not a transcription** — reconcile rather than assume if a
  canonical PPAT list turns up.
- **`property_owners.is_current` is kept and D-116 does not apply.** A Property legitimately has
  several current owners at once; it is a *"this row applies now"* flag on many rows, not the
  *"this is the one"* pointer D-116 removed from `document_versions`. Do not "fix" it.

**M7.1 owed one explicit decision** — whether `property_number` is allocated or office-supplied.
**M7.3 settled it: office-supplied**, unique per Office, no format validated, immutable once
assigned. The ERD gives no format, `CLAUDE.md` §38 shows `PROP-000001` without a year (alone among
the internal references), and an allocator would need a counter table. Lock §15.

**M7.2's own finding: `ppat.deeds.delete` is not in the registry**, and neither is `.void` or
`.lock`. The brief conditioned a `destroy` endpoint on that code existing, so its own condition ruled
the endpoint out; `DELETE` answers 405 and both extra verbs answer 404. Do not add any of the three
on the strength of the table having rows — a deed recorded in error is a **correction mechanism**,
open question nine (O-039).

**M7.3's findings, three of them, and each is a thing not to undo:**

- **`properties.delete` is absent and `properties.archive` is what the catalogue defines**, so
  archiving *is* the soft delete — the ERD gave `properties` a `deleted_at` and gave the catalogue no
  delete code, and read together they are one mechanism. `status` stays unwritten: it has no ERD
  vocabulary at all. There is no un-archive, because there is no `properties.restore` (O-045).
- **Several owners may be current at once.** The M7 lock §7.2 says so by name, and the M7.3 brief
  asked for the opposite. `is_current` is a flag on many rows; adding a co-owner does not close the
  existing holders, and `supersedes_current` is how a caller says this is a transfer instead. **No
  sum across shares is validated** — that is a rule about Indonesian co-ownership nobody here may
  write.
- **A link in a chain of title is never deleted.** `property_owners` has no `deleted_at`; ending an
  ownership is stamping `effective_until`. Do not add a delete route on the strength of the brief
  asking for one.

**M7.4's findings, and each is a thing not to undo:**

- **`ppat_warkah_items.status` has no vocabulary, and none may be invented.** The ERD names the column
  and gives it no values. The M7.4 brief proposed six; an item-status vocabulary *is* the verification
  rule, which is open question three. The column is `prohibited` on both item Form Requests and always
  null in the payload. **Completeness therefore counts documents, not statuses** — lines with a file
  attached, over lines the office created (O-041).
- **`ppat.warkah.finalize` and `.archive` have no route and no Policy ability.** Both codes are
  canonical; the trigger is open question eight, which `09_PPAT_WORKFLOW.md` §2 names as exactly the
  kind of rule not to reconstruct from memory. `FINALIZED` and `ARCHIVED` stay stored vocabulary
  nothing reaches, and `PATCH .../status` answers 422 for either.
- **Status is settable and not gated.** No transition matrix (the M7 lock §8.2, and D-102's shape).
  The capability is the gate: `INCOMPLETE`/`UNDER_REVIEW` under `update`, `COMPLETE` under `verify`.
  Verification checks neither completeness nor item statuses nor the current status — each would be an
  invented rule.
- **Reading never starts a bundle.** `GET .../warkah` 404s until the office composes one; the brief
  asked the read endpoint to create it. There is no `ppat.warkah.create`, so the first line or the
  first status materialises the row under `ppat.warkah.update`.

### M8 — Dashboard, Billing & Reports, locked at M8.0 (D-122)

```text
M8.0  Architecture lock                                   <- done
M8.1  Dashboard + audit & activity foundation             <- done, closes D-115
M8.2  Billing — quotations, invoices, payments, disbursements  <- done
M8.3  Reports — five families, read-only, scoped              <- done
M8.4  M8 quality gate                                      <- see note below
```

**On M8.4 specifically:** `docs/CHANGELOG.md`'s last milestone entry is M8.3 (2026-08-26); no
entry named "M8.4" exists there, and no separate commit in Git history is labelled M8.4. What is
verifiable is the higher-level fact: M8 merged to `main` `--no-ff` at `7d2cc1a`, and the full local
quality gate (frontend and backend, §9) has since passed repeatedly against `main` at later commits
(including at `0e87b5f`, the current HEAD). Whether a discrete "M8.4" quality-gate step happened as
its own recorded unit or was absorbed into the merge and later verification passes is genuinely not
answerable from what's in this repository — flagged here as a documentation gap (`CLAUDE.md` §58:
identify a source conflict, don't silently pick a side) rather than resolved one way or the other.

**M8.1 shipped** two migrations (50 → 52), two models, two enums, three services, seven routes and
five dashboard widgets, registering **no permission**. What it did differently from its brief, and
why, is in the changelog entry — the short version is that role-name filtering (D-048), invented
staleness thresholds, and a role-branched layout were all replaced with capability-gated composition,
and the activity feed reads `activities` rather than `audit_logs` because the latter would have made
the Dashboard a way to read audit content without `audit.view` (D-128).

The order is **forced, not chosen**: `reports.audit.view` cannot be built before `audit_logs` exists
and `reports.financial.view` cannot be built before billing does, so Reports come last.

**M8 is the mirror image of M6 and M7, not a repeat of them.** Those two had canonical tables and
missing capabilities. Billing has **seventeen canonical capabilities and no canonical table at all** —
`03_DATABASE_ERD.md` defines no `quotations`, `invoices`, `invoice_items`, `payments` or
`disbursements` section, and §27 names no `INVOICE` or `QUOTATION` sequence code. So **M8.2 designs
schema, which no milestone here has previously done** (D-124, O-049); M1 through M7 transcribed field
lists and declined to build tables the ERD was silent about. Read `18_M8_...ARCHITECTURE.md` §5 and
§9 before writing any of it.

**Sharing batch 11 is not sharing a disposition.** Billing sits in batch 11 with registers, protocol
and taxes — the three M6 and M7 declined. Those were declined because their **domain rules are
unauthored**; billing has no such gap, because an office invoicing its own client is commerce, not
Indonesian notarial procedure. **The tax boundary is what keeps that honest**: no `tax_amount`, no
rate, no BPHTB/PPh/PNBP field, no computation. An office showing a tax types it as a line item it
names itself. Disbursements are records, not tax, and are not a back door to `ppat_tax_records`
(O-040, still open).

**Four rulings not to undo:**

- **The Dashboard invents no authority.** No `dashboard.*` code exists and none is needed; each panel
  is gated by the capability of what it summarises. **Every count obeys Data Scope** — a count is a
  disclosure, and on a small Office a count plus a filter reconstructs the list.
- **Billing lifecycles are read off the catalogue's verbs, not invented.** `DRAFT → APPROVED` for a
  quotation, `DRAFT → ISSUED → CANCELLED` for an invoice, `PENDING → VERIFIED` for a payment, and
  **no status column at all** for a disbursement, which has no lifecycle verb. There is no
  `quotations.reject`, so there is no `REJECTED`.
- **`billing.amount.view` is a second gate**, masking every monetary figure including aggregates —
  server-side, so a masked amount is *absent from the payload*, as with NIK and NPWP (D-125).
- **Neither audit nor activity is backfilled** (D-123). The feed starts empty and fills forward.

**M8.3 rulings not to undo:**

- **Opening a report family is not reading its rows** (D-131). `ReportPolicy` guards a marker class
  and answers only "may this actor open this family"; every row is narrowed by its source domain's
  own capability. A holder of `reports.operational.view` alone sees correctly empty pages.
- **`reports.export` re-uses the built query**, so an export cannot reach further than the page did.
  Financial exports omit masked columns from the **header**, not just the cells.
- **Nothing may resemble a statutory return** (D-132). CSV only, no PDF, no letterhead, no sequence
  of its own. `ppat.reports.*` is reached by nothing — building any endpoint for it would answer
  O-043 by implementation, and O-035, O-036 and O-042 stay open too.
- **Revenue is verified payments**, not issued-invoice totals, and returns `null` rather than row
  counts without `billing.amount.view`.
- **The property report has no status filter**, because nothing writes `properties.status` (M7.3).

**Two gaps ship stated rather than closed with an invented verb**: a verified payment has no
correction path (O-050), and `activities` has no capability — resolved for M8 as infrastructure read
through its subject, reopening if anyone wants a user-authored timeline (O-047).

**The trap in M8.3: `reports.ppat.view` and `ppat.reports.view` are different codes.** The first is
M8's — a cross-cutting read of PPAT activity. The second belongs to a five-code
`ppat.reports.generate → review → approve → export` workflow, which is the PPAT **monthly reporting
obligation**, unspecified as to deadline, recipient and format (O-043). **M8.3 builds no endpoint for
any of those five.** Nothing M8.3 produces may resemble a statutory return — a report that looks like
one invites being filed as one.

**Calendar is the cheapest open item in the ledger.** Canonical table, six event types, five
registered codes, a menu destination, batch 7 — and **no milestone from M0 to M8 names it**. Unlike
everything else outstanding it is blocked on nothing but assignment (O-048).

**M8 is the last milestone in the plan.** `CLAUDE.md` §2 and `01_ARCHITECTURE.md` §28 both end there,
so nothing M8 declines has a later milestone to fall into. At M6 and M7 an open item was a deferral;
**at M8 it is a statement about what the delivered product does not do.**

### Standing judgement calls, never given an explicit yes or no

Three items from the M5.2 report were never given an explicit yes or no. They are not blockers — M5
through M8 are all merged and green — but each is a judgement somebody should confirm rather than
inherit:

1. **`matterReachable()` reads the permission namespace from the Matter's stored `domain` column** —
   one of only two places in the repository that happens (the other is `DocumentRelationController`).
   The argument that it is not the D-101 hazard is documented in the controller; it deserves an
   explicit yes or no.
2. **`is_sensitive` locks on `ARCHIVED`** as well as `VERIFIED` and `FINAL`, which extends the stated
   requirement. Same rule applied consistently, but it is an extension.
3. ~~**`documents.sensitive.download` grants nothing** until audit exists.~~ **Closed at M8.1.** The
   audit store exists, the gate came out, and granting the code now has the effect an administrator
   would expect — with every such download recorded (D-115, D-123).

### What comes next

The M0–M8 milestone plan has run its full course. There is no M9, and none is proposed here —
`CLAUDE.md` §2 names eight milestones and stops, and inventing a ninth to keep this section's shape
familiar would be exactly the kind of unrequested scope `CLAUDE.md` §60 forbids. What comes next is
a **different kind of work**, not a continuation of the milestone sequence:

- **An authenticated product-flow audit.** Every milestone brief above was verified with disposable
  databases, targeted smoke tests, and Pest/Vitest suites — nobody has yet walked the product
  end-to-end as a signed-in user across a full business day's worth of actions. That is a distinct
  kind of check the milestone process was never structured to produce.
- **A safe demo-data strategy.** Nothing in this repository seeds realistic data (§6, §4.6 —
  neither `activities` nor `audit_logs` is backfilled, and no milestone has shipped a demo seeder).
  Showing the product to anyone who isn't reading raw API responses needs data that is realistic
  without being real client information — NIK, NPWP, and real names never belong in a seeder or a
  demo environment (`CLAUDE.md` §21–22).
- **UI/UX packaging for Dashboard, Document/Deed Management, and Deed Detail.** These surfaces were
  built to the letter of their milestone briefs (M8.1, M5.2/M6.2/M7.2, M6.2/M7.2 respectively) but
  packaging a screen for a brief and packaging it for an office worker to actually use it well are
  different bars. Closing that gap is presentation work, not a schema or capability change.
- **Open items close only through explicit, individually-scoped tasks** — never as a side effect of
  something else. §7 lists 27 of them; none is closed here, and none should be closed anywhere
  merely because a broader task happened to touch nearby code. This is the same discipline
  `CLAUDE.md` §67 asks of commits, applied to the open-item ledger.
- **Domain validation is still required wherever a legal rule is still open.** M6 and M7 shipped
  everything buildable *without* guessing at Indonesian notarial or PPAT procedure — deed numbering,
  Repertorium format, Minuta archiving triggers, Warkah composition per deed type, tax gating, and
  the PPAT monthly reporting obligation are all still unanswered (§7; `08_NOTARY_WORKFLOW.md` §6,
  `09_PPAT_WORKFLOW.md` §6). Productization work does not change who is allowed to answer those —
  `CLAUDE.md` §62 still applies: stop, document the gap, ask, never guess.

---

## 9. Running it

```bash
docker compose up -d                    # PostgreSQL 18, Redis 8

cd backend
composer install
php artisan migrate                     # NOT against the persistent database — see §6
php artisan permissions:sync            # 177
php artisan app:bootstrap               # interactive: org, office, roles, first administrator
php artisan serve

cd ../frontend
pnpm install
pnpm dev
```

### Quality gates — both must pass, and the lists must never be weaker than `.github/workflows/quality.yml`

```bash
# frontend
pnpm format:check && pnpm lint && pnpm typecheck && pnpm test && pnpm build

# backend
./vendor/bin/pint --test && php artisan test
```

Use `pnpm test`, never `test:watch` — the watch mode never exits, so a task using it appears to hang
rather than to pass. CI runs `test:ci`, the same single run plus coverage.

---

## 10. If you read nothing else

1. **Read the architecture lock for the domain you are touching** before changing it.
2. **Authorize through a Policy and the resolver.** Never a permission code, never a role name.
3. **Do not invent legal rules.** Stop, document the gap, ask.
4. **Do not touch the persistent development database.** Verify on a disposable one.
5. **Implement only the current milestone.** Registering a permission is not shipping a feature.
6. **A canonical table is not a canonical capability — and the reverse is equally true.** M6 and M7
   found tables with no codes (O-036, O-040); M8 found seventeen billing codes with no table at all
   (O-049). Check both sides before you build, and say which one you are supplying.
