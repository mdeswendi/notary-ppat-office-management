# Project Handoff — M0 through M7.4

**Position:** branch `feat/m7-ppat`. M0–M6 and O-037 are **merged to `main`** (`fa381fa`); M7.0 through M7.4 are on this branch.
**Last accepted merge to `main`:** O-037 (`fa381fa`), which brought M5 and M6 with it.
**Written:** 2026-08-24, after M5.2; figures refreshed through M7.4.

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
| Milestones complete | **M0 – M7 feature-complete** — M0 – M6 merged to main · M7.0 – M7.4 on `feat/m7-ppat` |
| Migrations | **50** |
| Canonical permissions | **177** — unchanged since the catalogue was transcribed at M1.2 |
| Models | 36 |
| API routes | **188** under `/api/v1` (195 total) |
| Backend tests | **2820 passing, 8 skipped** across 88 files (Pest) |
| Frontend tests | **187 passing** across 19 files (Vitest + RTL) |
| Frontend pages | 52 |
| Decisions recorded | **D-001 … D-121** |
| Open items | 21 still open (§7) — O-037 and **O-044** closed, O-039…O-046 added 2026-08-25 |

**The persistent development database stands at 42 migrations**, applied in **two** runs: batches 1–11
took it to 22 (through M1), and a single batch 12 applied twenty more at once, bringing it through
M6.3. It does **not** carry M7.1 — `properties` and the seven `ppat_*` tables are absent from it.

*(This section previously said 22 and "deliberately behind". That was true until batch 12 was applied.
The figure is corrected rather than the history rewritten, because which milestone a dev database sits
at is a fact about somebody's machine, not a project invariant.)*

**What has not changed is the working rule**: every schema verification runs on a **disposable**
database created and dropped for the purpose, never against this one. See §6.

### Routes by domain

```text
notary 29      companies 19  ppat 40   projects 15   individuals 14
users 13       tasks 12      security 12  documents 12  properties 9
roles 7        profile 2     parties 1    permissions 1  health 1   me 1
```

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
| **M7.4** | Warkah: eleven routes, four of the six codes, deed section and list | on branch |

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
├── 16_M6_NOTARY_ARCHITECTURE.md   │
├── 17_M7_PPAT_ARCHITECTURE.md     ┘ ← read §5 of these two first
├── DECISIONS.md                   ← D-001…D-121 + the Open Items register
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
- **Do not migrate the persistent development database.** It stands at 42 migrations (see §2 — the
  figure is a fact about somebody's machine, not a target). All schema verification runs on a
  disposable database (`m44_smoke`, `m51_probe`, `m52_probe`, `m71_probe`, `m72_probe`, …), created
  and dropped within the milestone.

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

Twenty-one of forty-six. Each is recorded in `DECISIONS.md` with its full reasoning; this is the
index — minus the five M7 scope items (**O-039** … **O-043**), which are described where they matter,
in §8.

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

**The next milestone is M8 — Dashboard, Billing & Reports.** It is the first that has never had an
architecture lock written, and `01_ARCHITECTURE.md` §28 is where it starts.

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

### Before the next milestone starts

Three items from the M5.2 report were never given an explicit yes or no. They are not blockers — M5
and M6 are merged and green — but each is a judgement somebody should confirm rather than inherit:

1. **`matterReachable()` reads the permission namespace from the Matter's stored `domain` column** —
   one of only two places in the repository that happens (the other is `DocumentRelationController`).
   The argument that it is not the D-101 hazard is documented in the controller; it deserves an
   explicit yes or no.
2. **`is_sensitive` locks on `ARCHIVED`** as well as `VERIFIED` and `FINAL`, which extends the stated
   requirement. Same rule applied consistently, but it is an extension.
3. **`documents.sensitive.download` grants nothing** until audit exists. An administrator granting it
   will see no effect (D-115).

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
