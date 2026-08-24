# Project Handoff — M0 through M6.3

**Position:** branch `feat/m6-notary`, ahead of `main` (`f82dc25`) by the M5 and M6 work below.
**Last accepted merge to `main`:** M4 — Matter & Workflow Engine.
**Written:** 2026-08-24, after M5.2; figures refreshed through M6.3.

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

| | |
|---|---|
| Milestones complete | M0, M1, M2, M3, M4 (merged) · M5.0–M5.4, M6.0–M6.3 (on branches) |
| Migrations | **42** |
| Canonical permissions | **177** — unchanged since the catalogue was transcribed at M1.2 |
| Models | 29 |
| API routes | **156** under `/api/v1` (163 total) |
| Backend tests | **2545 passing, 8 skipped** across 84 files (Pest) |
| Frontend tests | **121 passing** across 13 files (Vitest + RTL) |
| Frontend pages | 44 |
| Decisions recorded | **D-001 … D-120** |
| Open items | 16 still open (§7) |

**The persistent development database stands at 22 migrations and is deliberately behind.** It has
never been migrated past M1. Every schema verification since has run on a disposable database. See §6.

### Routes by domain

```text
notary 29      companies 19  ppat 17   projects 15   individuals 14
users 13       tasks 12      security 12  documents 12  roles 7
profile 2      parties 1     permissions 1  health 1   me 1
```

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

M0's history is unusually granular (M0.1 → M0.10) because the environment itself was being
established. From M1 onward the shape is stable: lock → schema → allocator → management → frontend →
quality gate.

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
├── 16_M6_NOTARY_ARCHITECTURE.md   ┘ ← and read §5 of this one first
├── DECISIONS.md                   ← D-001…D-120 + the Open Items register
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
- **Do not migrate the persistent development database.** It stays at 22 migrations. All schema
  verification runs on a disposable database (`m44_smoke`, `m51_probe`, `m52_probe`, …), created and
  dropped within the milestone.

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

Twelve of thirty-four. Each is recorded in `DECISIONS.md` with its full reasoning; this is the index.

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
| O-037 | The Project detail page shows no Notary Deeds | Grandchildren, and the list filters by matter not project |
| O-038 | No list surface accepts a sort parameter | Cross-cutting product decision, not an M6 defect |

**The largest structural gap is not on this list: `audit_logs` does not exist.** D-033 kept it out of
M1 on the ERD's batch ordering; `audit.view` and `audit.export` are registered and unimplemented.
D-115 rules that **no sensitive-download surface ships before it exists**, which is why
`documents.sensitive.download` currently authorizes nothing. Whoever builds audit removes that gate.

---

## 8. What comes next

### Remaining in M5

```text
M5.5  (absorbed into M5.4 — the identifier stays retired)
M5.6  M5 quality gate
```

Audit is deliberately unnumbered — whether it becomes M5.2a or a prerequisite milestone is a scoped
decision for whoever takes it. It is the one thing M5 named and did not build, and D-115 keeps
`documents.sensitive.download` authorizing nothing until it exists.

**The question M5.0 left for M5.4 is answered** (D-119): `created_by` was added, `OWN` is the creator,
`ASSIGNED` is the assignee, and the two are separate predicates that union when both are held.

### After M5

```text
M6  Notary module   — Notarial Deeds, Minuta Akta   ← M6.0 lock accepted; see below
M7  PPAT module     — PPAT Deeds, Property, Warkah, taxes, registers, reports
M8  Dashboard, Billing & Reports
```

Both M6 and M7 are **blocked on domain validation**, not on engineering. The two workflow documents
are drafts, and four of the seven recommended document junctions
(`property_documents`, `notary_deed_documents`, `ppat_deed_documents`, `matter_requirement_documents`)
reference tables those milestones create.

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

### Before the next milestone starts

M5.2 is **pending acceptance**. The branch has not been merged. Three items from the M5.2 report are
worth a decision:

1. **`matterReachable()` reads the permission namespace from the Matter's stored `domain` column** —
   the only place in the repository that happens. The argument that it is not the D-101 hazard is
   documented in the controller; it deserves an explicit yes or no.
2. **`is_sensitive` locks on `ARCHIVED`** as well as `VERIFIED` and `FINAL`, which extends the stated
   requirement. Same rule applied consistently, but it is an extension.
3. **`documents.sensitive.download` grants nothing** until audit exists. An administrator granting it
   will see no effect.

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
