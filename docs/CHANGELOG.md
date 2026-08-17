# Notary & PPAT Office Management System
## Documentation Changelog

Records specification changes and milestone results.

---

## 2026-08-17 — M3.4 Project ↔ Party participation

Branch `feat/m3-project-matter`. **One forward migration** (22 total). **Two permissions**
(171 → **173**) — the first codes added since the catalogue was transcribed. Backend
**1591 tests / 5329 assertions** — 115 new. Five routes, one Project-detail section, i18n at
**731/731** exact parity. One new decision, **D-098**.

### What shipped

`project_parties`, and the surface to maintain it:

```text
GET    /api/v1/projects/{project}/parties                  projects.parties.view
POST   /api/v1/projects/{project}/parties                  projects.parties.manage
PATCH  /api/v1/projects/{project}/parties/{projectParty}   projects.parties.manage
DELETE /api/v1/projects/{project}/parties/{projectParty}   projects.parties.manage
GET    /api/v1/projects/{project}/party-options            projects.parties.manage
```

Nested under the Project, with **no top-level collection**: a relationship row must not be
reachable without naming the parent, because the parent is where the authorization lives. A row
belonging to a different Project answers 404, not 403.

### Two capabilities, and neither implies the other

Authorization is judged against the **parent Project** by the four D-088 predicates. Following
the M2.4 precedent, a relationship surface gets its own capability rather than borrowing the
parent's lifecycle permission — **`projects.update` reaches neither**, or the dedicated codes
would be decoration. No `projects.parties.view_all`: reach is Data Scope `ALL`, and a second
reach mechanism is what D-090 refuses.

That `manage` does not imply `view` is the half that feels wrong and is deliberate. The registry
defines two codes; an administrator who wants both grants both. A silently implied capability is
one nobody configured and nobody can revoke.

### The same-Office invariant is structural

```text
project_parties.office_id  ->  projects (id, office_id)
                           ->  parties  (id, office_id)
```

Two composite foreign keys through one carrier column, so both endpoints must agree with it and
therefore with each other. A cross-office participation is **unrepresentable**, not merely
refused — the M2.4 pattern (D-080), because the same sentence holds: `ALL` grants visibility and
administrative reach, never permission to redefine domain ownership. `projects` gained the
`UNIQUE (id, office_id)` support key a composite foreign key requires; `parties` has carried its
equivalent since M2.1.

An `ALL`-scoped actor reaches a Project in another Office and links a Party from **that Office**.
It never bridges two — proven in the smoke, not just asserted.

### Managing participation is not authority to discover Parties

This is the boundary the milestone exists to get right. Linking requires **both**
`projects.parties.manage` over the Project **and** ordinary Party visibility for the candidate —
`parties.view` for an Individual, `companies.view` for a Company, **evaluated independently**,
because an actor may hold one branch and not the other.

A submitted `party_id` is **re-resolved through the authorized candidate query** rather than
trusted, so a real and correct id obtained elsewhere cannot become a participation. Every
ineligibility — absent, archived, another Office, an unreachable subtype — answers one
indistinguishable 422.

**A linked Party is never withdrawn from the list.** A reader who cannot open the Party still
sees the row as a minimal stub with `can_view_party: false`; hiding it would misreport the
Project's composition to somebody entitled to read it. No NIK, NPWP, `tax_id`, contact, address —
and no masks, since a mask is still a statement about a sensitive value.

### Current state, not history

The sharpest departure from `company_people`, and deliberate:

```text
company_people    effective_from  effective_until   history, because deeds depend on it
project_parties   created_at  created_by            what is true now
```

The ERD gives participation no period columns, no `updated_at`, and no `deleted_at`, and none was
added. Unlinking **hard-deletes the relationship row** and leaves both records untouched. A soft
delete would have created a half-history: rows nobody lists, no mechanism to read them, and a
schema claiming preservation that no surface honours. If participation history is later required
it needs its own decision and its own columns.

### What was deliberately not invented

`role_code` is nullable and opaque — no enum, no `Rule::in`, no `CHECK`. The ERD's six codes are
labelled examples, so constraining the column would turn them into the catalogue the document
says they are not. `is_primary` is a designation with **no cardinality**: several may be primary
at once and none has to be. No `UNIQUE (project_id, party_id)`, so one Party may appear twice
under two classifications. **A Project with no participants is complete, not a draft** — M3.3's
create surface was not changed.

Each of these is a rule nobody has stated. Inventing any of them through an index or a validator
would be a business rule wearing an implementation's clothing.

### One defect found and fixed

A participation created without `is_primary` serialized as `null` in its own 201 response while
the database stored `false` — the creating client and a later reader would have been told
different things about the same row. Fixed by mirroring the column default on the model.

### Verification

**Disposable PostgreSQL 18.4**, uniquely named: migrated from zero through all 22, both composite
foreign keys and the support key confirmed, M3.4 rolled back, **M3.3 state confirmed restored**
(21 migrations, no `project_parties`, support key gone), reapplied, dropped, and **absence
proven**.

**HTTP smoke over real PostgreSQL and Sanctum cookie sessions: 57 checks, 0 failures.**

The M3.3 rule was applied and it earned its keep. Before the first smoke request, the **serving
process itself** reported its connected database — via `SELECT current_database()`, not config,
through a proof endpoint running in that same process — and the smoke was written to abort if it
disagreed. It did abort on the first run, on a BOM in the expected-name file rather than a wrong
target, which is the guard behaving correctly.

Twelve M2/M3 guard tests were **narrowed rather than deleted**. Six asserted the global
permission total of 171 in six separate places; the total is now pinned once, in
`PermissionRegistryTest`, and each milestone test asserts its own domain group instead — so a
later legitimate addition no longer has to be applied in six files. The rest asserted that
participation did not exist yet, which M3.4 intentionally makes false.

The persistent development database differs from its M3.3-verified baseline in exactly three
ways, all intended: migrations 21 → 22, permissions 171 → 173, and the new empty
`project_parties` table. Every other count is unchanged, `sessions` included — the smoke left no
residue.

---

## 2026-08-16 — M3.3 Project core management

Branch `feat/m3-project-matter`. **One forward migration** (21 total). **No permission** (171):
every capability was already canonical. Backend **1476 tests / 4985 assertions** — 89 new. Ten
routes, five frontend pages, i18n at **691/691** exact parity. One new decision, **D-097**.

### What shipped

The Project product surface end to end: create, list with search and filters, detail, ordinary
update, PIC assignment, status change, archive, restore, and a separate archived surface.

```text
GET    /api/v1/projects                     projects.view
POST   /api/v1/projects                     projects.create        — own Office always
GET    /api/v1/projects/archived            projects.restore
GET    /api/v1/projects/{id}                projects.view
PATCH  /api/v1/projects/{id}                projects.update        — ordinary attributes only
PATCH  /api/v1/projects/{id}/assignment     projects.assign        — pic_user_id only
GET    /api/v1/projects/{id}/assignment/options
PATCH  /api/v1/projects/{id}/status         projects.change_status — status only
DELETE /api/v1/projects/{id}                projects.archive       — soft delete
POST   /api/v1/projects/{id}/restore        projects.restore
```

The literal `archived` path is registered **before** the `{id}` binding, with a test pinning
that ordering — reversed, the archived surface would be read as a Project id and answer 404.

### `project_number` is now `NOT NULL`

M3.3 owns the only path that creates a Project and that path always allocates, so the column can
carry the guarantee the domain always intended. The precondition was checked rather than
assumed: the persistent development database held **zero Projects and zero null references**.
**No backfill was invented** — a deployment holding null references must resolve them
deliberately.

### What the system decides, and what it refuses

`office_id`, `project_number`, `status`, and `pic_user_id` are set by the system and appear in
no request shape. A new Project starts `OPEN` with no PIC.

**`ALL` does not authorize creating in another Office.** `ALL` is cross-office *reach over
existing Projects*, not a filing destination, so `office_id` in a create body is a 422 for
everybody. **`ASSIGNED` alone cannot authorize creation at all** — the predicate is
`pic_user_id == actor.id` and a Project being created has no PIC, so it is false for the very
record being asked for. That is not an exception to the union rule: an actor holding `ASSIGNED`
and `OFFICE` creates normally, because `OFFICE` matches.

A PIC must be an **active user of the Project's own Office**, enforced in the Form Request and
again in the Action. Not tidiness: `ASSIGNED` grants reach when `pic_user_id == actor.id`, so a
cross-office assignment would hand somebody reach over a Project their own scope never included
— a privilege grant performed through a work allocation, with no role changing.

**No transition matrix.** No canonical document defines which status may follow which, so the
backend authorizes *who* may change status rather than *which* change is legal, and the
interface offers every canonical code rather than inventing a rule by hiding options.

### A defect the smoke found, and the fix

`PATCH /api/v1/projects/{id}` with `{"pic_user_id": null}` answered **200** instead of refusing.
Laravel's `prohibited` rule means "missing **or empty**", so an explicitly null system-controlled
field satisfied it on both create and update.

Nothing was ever written — the Actions fill an explicit allow-list and the model keeps these
columns out of `$fillable`, so there was **no write path and no escalation** — but the response
told the caller an instruction had been accepted when it had been discarded. `{"pic_user_id":
null}` is an unassign instruction, and unassigning belongs to `projects.assign` (D-091). A
silent no-op is not a refusal.

Both Form Requests now refuse on **presence**, not emptiness, via an `after` hook. Fifteen tests
pin it, including one asserting the PIC is still there after the refusal.

### Verification

**Disposable PostgreSQL 18.4**, uniquely named, target proven before every command: migrated
from zero through all 21 migrations, `project_number` confirmed `NOT NULL`, M3.3 rolled back,
M3.2 structure confirmed restored (nullable, unique index and counter table intact), reapplied,
database dropped.

**HTTP smoke over real PostgreSQL and real Sanctum cookie sessions** — standalone cURL, no test
harness, so middleware, CSRF, session cookie, Policy chain, and Data Scope all ran as a browser
drives them. **77 checks, 0 failures**, covering the full lifecycle plus ASSIGNED reach,
cross-office refusal, capability flags, and D-093's distinction between business `ARCHIVED` and
an archived record.

Worth recording because it nearly went unnoticed: the first smoke attempt ran against the
**wrong database**. `artisan serve` strips non-allow-listed variables from the child process, so
`DB_DATABASE` never reached the server and it answered from the persistent development database
while every local check reported the disposable one. Caught by counting session rows on both
sides. The eight anonymous session rows it wrote were removed and the persistent database
verified **identical to its captured baseline across all 24 tables**. Subsequent runs drove the
built-in server directly and proved the target from inside the served process before any traffic.

### Documentation

`06_API_CONVENTIONS.md` sections 21 and 22 sketched `POST /{id}/archive`,
`POST /{id}/assign`, and `POST /{id}/change-status`. The shipped surface uses `DELETE /{id}` and
`PATCH /{id}/assignment` and `/status` — the same authorization decisions spelled differently.
Both sections were reconciled to the shipped surface and the discrepancy is reported rather than
silently resolved.

---

## 2026-08-16 — M3.2 Project internal reference foundation

Branch `feat/m3-project-matter`. **One forward migration** (20 total). **No permission** (171):
allocation is an internal service concern, not a user-facing ability. Backend **1387 tests /
4721 assertions** — 26 new. **No route, no controller, no frontend.** One new decision,
**D-096**.

### What exists now

`PRJ-2026-000001` — ordinary office identification, and D-094's warning is worth repeating
because a column called `project_number` in a legal-office system is exactly what a future
reader mistakes for a legal sequence. It is **not** a deed, repertorium, Matter, Warkah, land,
or government number, and `PRJ` carries no Notary or PPAT meaning.

`projects.project_number` is `varchar(32)`, nullable, unique **per Office**.
`project_reference_counters` is keyed `(office_id, reference_year)` — a natural composite
primary key, since allocator infrastructure has nothing for a surrogate id to identify that the
namespace does not already.

### The allocator

One statement. `INSERT … ON CONFLICT (office_id, reference_year) DO UPDATE SET last_value =
last_value + 1 RETURNING last_value`. The increment happens inside the database against a row
the engine locks for the duration of the upsert, so two concurrent callers cannot compute the
same value — neither computes it at all.

**A transaction alone would not have been sufficient, and that is the trap worth naming.**
Under `READ COMMITTED`, PostgreSQL's default, two transactions can both read the same
`last_value` before either writes. Wrapping a select-then-update in `DB::transaction()` looks
like a fix and is not one; the loser gets a unique violation the user sees.

The identical statement runs on PostgreSQL and SQLite 3.35+, verified on both **before** the
allocator was written. That gives one execution path and **no semantic divergence between the
test engine and the production engine** — a concurrency strategy that only exists on one of
them is a strategy nobody tests.

### Concurrency evidence

Real PostgreSQL, real OS-level contention: **16 simultaneous processes** — eight per Office,
started before any finished — each allocating 25 references.

```text
Office A   allocated=200  distinct=200  min=1  max=200  counter=200  contiguous ✓
Office B   allocated=200  distinct=200  min=1  max=200  counter=200  contiguous ✓
           failed jobs = 0, no unique violation reached any caller
```

Not merely "no duplicates": exactly contiguous, with each counter landing on its expected total
and the two Offices provably independent.

### Semantics recorded rather than assumed

**Uniqueness is per Office, never global.** Office A and Office B may both hold
`PRJ-2026-000001`. A global index would fail the second Office's first project of the year for
a reason nobody could explain. Two consequences outlive the allocator: a reference does not
identify a Project on its own — a lookup must carry the Office — and it is **never
authorization input**, so reach stays `office_id` / `created_by` / `pic_user_id` through the
resolver.

**Gaps are expected and mean nothing.** An allocation handed out and not used leaves one by
design; the alternatives are reusing references or serializing every create behind one lock.
The number is not a record count, sequential appearance has no legal weight, and a reference
belonging to a persisted Project is spent — archiving does not release it, restoring keeps the
same one, and no reuse feature exists.

**The year comes from the application clock**, stored explicitly on the counter row, never from
a browser, a locale, user input, or by parsing an existing reference. Tests freeze time, so
rollover is proven rather than hoped for: `PRJ-2026-000003` then `PRJ-2027-000001`, with the
2026 counter untouched.

**Nullable at M3.2, and that is a design choice.** The persistent development `projects` table
was inspected first and held 0 rows, so `NOT NULL` would have been safe as data migration. It
was withheld because M3.2 ships no creation path that assigns a reference — that is M3.3 — and
`NOT NULL` would make Project unwritable for a whole milestone. Whether to tighten is recorded
as M3.3's decision. Nothing was backfilled; a `MAX+1` backfill is forbidden outright.

**Immutable once allocated**, while still permitting the initial `null → reference` assignment,
so the guard cannot block the operation the column exists for.

### Guard tests

M3.1's "carries no internal reference column yet" was **narrowed, not deleted**. The half that
expired — `project_number` absent — was correct while D-094 held allocation back, since the
column and its allocator ship together. What survives is the part still worth guarding: there
must be **exactly one** reference column, because a second would be two answers to what a
Project is called. A new guard asserts the counter is not generalized into
`legal_number_sequences` or anything deed- or Matter-shaped.

### Verification

```text
Backend        1387 tests, 4721 assertions — 26 new (baseline 1361 / 4641)
Pint           passed
composer       validate --strict passed
Migrations     20, none pending
Permissions    171 / 171, sync idempotent twice
Frontend       format:check, lint, typecheck, build clean; i18n 608/608 — source untouched
Disposable DB  m32_reference_20260816 — target proven empty first; chain from zero to 20;
               schema verified; 16-process contention run; rolled back (counter table and
               column gone, M3.1 projects structure intact at 15 columns / 2 CHECKs / 4 FKs /
               6 indexes) and reapplied identically; dropped and absence confirmed
Dev database   forward migration only, 0 projects rows, no destructive command run
Scans          no MAX+1, COUNT+1, latest(), or orderByDesc() in the allocator; no legal
               numbering vocabulary; no Matter, project_parties, controller, request, resource,
               route, or frontend change; PermissionRegistry byte-untouched
```

### Boundary

No Project CRUD, no route, no frontend, no participation, no Matter. M3.3 integrates the
allocator into Project creation.

---

## 2026-08-16 — M3.1 Project schema and authorization foundation

Branch `feat/m3-project-matter`. **One forward migration** (19 total) — the first M3 schema.
**No permission** (171): every `projects.*` code was already canonical, and M3.1 gives seven of
the eight an implementation. Backend **1361 tests / 4641 assertions** — 67 new. **No route, no
controller, no frontend**: this is schema and authorization only, following the M2.1 precedent.
One new decision, **D-095**.

### What exists now

`projects` — ULID key, required Office, `title`, `description`, `status`, `priority`,
`pic_user_id`, the three dates, actor metadata, timestamps, soft delete. Four foreign keys, all
`ON DELETE RESTRICT`; two `CHECK` constraints on the code columns; five indexes.

Three of those indexes exist because they are **Data Scope predicates**, not because they are
foreign keys: `office_id`, `pic_user_id`, and `created_by` are queried on every scoped read.

`ProjectVisibility` implements D-088 as one `OR` branch per granted scope, so grants genuinely
union. `ProjectPolicy` maps eight abilities onto the seven canonical codes through
`EffectiveAccessResolver`. `PermissionScopeRules` gains an explicit Project entry, replacing the
permissive default M2 had left behind — the same four scopes, but now a decision rather than a
placeholder, and an explicit entry is what tells the difference.

### The parts most easily got wrong

**`OWN` is not `OFFICE` under another name.** An actor holding `projects.view` at `OWN` reaches
the Projects they created and *not* a colleague's in the same Office. A test asserts exactly
that, because if it ever passed, every `OWN` grant in the deployment would silently be wider
than the administrator intended.

**Grants union; nothing ranks.** `OWN` plus `OFFICE` reaches a Project the actor created in
another Office *and* every Project in their own — the case a "widest scope wins" implementation
gets right by luck and a "narrowest wins" one gets wrong. `ProjectVisibility` carries no
`widest`, `rank`, or `max` helper, and a test greps the source to keep it that way.

**Creation confines to the actor's Office unless the grant is `ALL`.** `OWN` and `ASSIGNED`
have no record to match at creation time; reading them as "may create anything they will own"
would let an office-scoped actor create anywhere, inverting the boundary.

**`restore` is the one ability that sees an archived row.** The ordinary predicate excludes
soft-deleted records, so without that the permission would answer false for every record it
exists to govern — unusable by construction. It widens which rows are considered, never which
scopes apply, and a test proves an out-of-scope archived Project is still refused.

**`projects.view_all` grants no reach.** It appears nowhere in the Policy or the predicate, and
a test both greps for its absence and proves that holding it at `ALL` alongside `projects.view`
at `OFFICE` still cannot reach another Office (D-090).

**Update implies neither assign nor change-status.** Enforced twice on purpose: separate Policy
abilities, and `pic_user_id`/`status` withheld from mass assignment so a future Action filling a
request body cannot route around the boundary (D-091).

### D-095 — two recorded schema departures

**`project_number` is not created yet.** M3.2 owns allocation, and the column arrives with its
allocator. Adding it nullable now would leave every M3.1 Project with a null reference and hand
M3.2 a backfill plus an unanswered uniqueness question — unique per deployment, per Office, per
Office and year? Deciding the column before deciding what fills it is how that gets answered by
accident. Exactly D-086's reasoning for the fingerprint columns.

**`priority` borrows the vocabulary the ERD defines under `tasks`.** The document names the
column on `projects`, `matters`, and `tasks` and gives `LOW`/`NORMAL`/`HIGH`/`URGENT` once, in
the last of the three. A transcription with a named source rather than an invention — but it is
the one Project field whose values were not written beside the column they govern, so it is
recorded rather than left to be reverse-engineered. Nullable.

`status` carries **no database default**: the schema records what the application decided, and
a default would be the thin end of the transition matrix D-091 refuses.

### Guard tests

One M2-era guard was **narrowed rather than deleted**. `PartySchemaTest`'s "introduces no M3
relation" asserted `projects` did not exist, which M3.1 intentionally makes false. The expired
assertion is gone; every other one is kept, including the point the test was always really
about — **Party gains no foreign key into Project**. It is renamed to say what it now guards.

The route-level guards in `CompanyRegistryStatusTest` and `CompanyRelationshipRegistryTest`
needed **no change at all**, because M3.1 ships no route. They will need narrowing at M3.3, and
not before.

### Verification

```text
Backend        1361 tests, 4641 assertions — 67 new (baseline 1294 / 4476)
Pint           passed
composer       validate --strict passed
Migrations     19, none pending
Permissions    171 registry / 171 database, sync idempotent twice
Frontend       format:check, lint, typecheck, build clean; i18n 608/608 — source untouched
Disposable DB  m31_projects_20260816 — target proven empty first; full chain from zero to 19;
               15 columns, 2 CHECKs, 4 RESTRICT FKs, 6 indexes; both CHECKs proven to refuse a
               translated label; rolled back (table gone, M2 tables intact) and reapplied
               identically; dropped and absence confirmed
Dev database   forward migration only, 0 rows affected, no destructive command run
Scans          no forbidden authorization pattern in Project code; no Matter, project_parties,
               primary_client, client_id, project_number, or MAX+1; routes, frontend, and
               PermissionRegistry all byte-untouched
```

### Boundary

No Matter, `matter_parties`, `project_parties`, service types, workflow, internal-reference
allocator, CRUD endpoint, or frontend entered M3.1. M3.2 is next.

---

## 2026-08-16 — M3.0 Project architecture lock

Branch `feat/m3-project-matter`, from the accepted `main` tip `fdda4e4`. **Documentation
only.** No migration (18), no permission (171), no model, policy, controller, route, request,
resource, registry entry, frontend code, or product test. Backend stays at 1294 tests / 4476
assertions because nothing executable changed.

Eight decisions, **D-087 through D-094**, plus a new architecture lock at
`13_M3_PROJECT_ARCHITECTURE.md` — the sibling of M2's `12_`.

### The conflict this milestone had to resolve first

The milestone was proposed as "Project / Matter". Three canonical sources —
`00_PROJECT_OVERVIEW.md` section 19, `CLAUDE.md` section 2, and the `DECISIONS.md` milestone
register — all read **M3 — Project Management** and **M4 — Matter & Workflow Engine**.

Discovery reported the discrepancy instead of choosing (`CLAUDE.md` section 58), and the
ruling is that the roadmap wins: **M3 implements Project only.** M3.0 documents the
Project → Matter boundary, because an aggregate edge cannot be described without naming what
attaches to it, and builds none of the other side (D-087).

### What is locked

**Data Scope for Project** (D-088). `OWN` is `created_by`, `ASSIGNED` is `pic_user_id`,
`OFFICE` is `office_id`, `ALL` is cross-office reach, `TEAM` grants nothing. M2 had left
`projects.*` in the permissive default with an explicit note that narrowing it early would
mean guessing; M3 is where the guess becomes a decision.

That Party withholds `OWN` (D-080) while Project grants it is not an inconsistency, and the
lock says why: a Party is a shared directory record and the colleague who typed it in has no
claim on the person it describes, while a Project is a unit of work somebody opened. The
reasoning did not transfer, so the answer did not either.

The forward-looking half matters more. **Future Matter or stage assignment must never expand
Project `ASSIGNED`.** When M4 adds `matters.pic_user_id` and a stage assignee, letting either
widen Project reach would be a new grant wearing an existing scope's name — silently widening
every role already configured with it, without anybody editing a role.

**`view_all` is superseded, not deleted** (D-090). `projects.view_all` and its Matter, Task
and Calendar siblings predate Data Scope and express the same thing `ALL` expresses. They stay
registered for compatibility and history — **the count stays at 171** — but no `view_all` code
may serve as backend cross-office authority, and no second reach mechanism may exist beside
`EffectiveAccessResolver`. Two answers to one question do not stay equal, and the looser one
wins by accident.

**Mutation boundaries** (D-091). `projects.assign` writes `pic_user_id` and nothing else;
`projects.change_status` writes `status` and nothing else; generic `projects.update` refuses
both. Accepting them in the ordinary update body would make one permission a silent superset
of another — the failure D-082 guards against for identity, one domain removed. **No status
transition matrix is invented**: M3 authorizes *who* may change status, never *which* changes
are legal.

**`primary_client_party_id` is rejected** (D-092), and recorded in `03_DATABASE_ERD.md` rather
than quietly dropped, so a reader finding it in the ERD and not in the schema finds the reason
in the same repository. `project_parties` already carries participation and has `is_primary`;
the column is a second mechanism for one fact, and it re-creates the "client" concept D-078
refused, one column at a time.

**Office ownership is required and immutable during M3** (D-089) — an engineering boundary,
explicitly not a claim of legal impossibility. What M3 refuses is inventing what a transfer
would mean for participants, future Matters, and references already issued.

**`projects.restore` restores a soft-deleted record** (D-093), and nothing else — not business
status `ARCHIVED` back to `OPEN`, not a workflow, not a completion. `ARCHIVED` and `deleted_at`
are different states with unfortunately similar names, and the lock names the awkwardness
rather than smoothing it over. Party gains no restore for symmetry: `projects.restore` is
canonical and a Party counterpart is not.

**The internal reference is ordinary office identification, never a legal number** (D-094).
No `MAX+1`. The allocation and concurrency design is locked before M3.2 rather than guessed
now.

### Decomposition

```text
M3.0   Project architecture lock                  <- this milestone
M3.1   Project schema + authorization foundation
M3.2   Project internal reference foundation
M3.3   Project core management
M3.4   Project <-> Party participation
M3.5   M3 quality gate
```

M3.1 is schema, Policy, predicates, constraints and architecture tests — not CRUD UI,
following the M2.1 precedent. It is also where the M2-era guards asserting `projects` does not
exist get **narrowed rather than deleted**; what stays true is that Party gains no Project
foreign key and that no deed, Warkah, or property surface appears. Those guards are untouched
at M3.0, because no M3 product surface exists yet for them to be wrong about.

**Matter begins at M4.0** with its own architecture lock — and deserves one, because the
canonical registry already splits its capability surface into `notary.matters.*` and
`ppat.matters.*` with no generic namespace, while the ERD gives it one table discriminated by
`domain`. A Policy that selects its permission namespace from row data is a new authorization
shape in this codebase.

### Non-goals, restated

Documents and uploads, property and land records, deed generation, legal numbering, billing,
tasks, calendar, notifications, global search, a persistent audit-log product, Party merge,
cross-office Party consolidation, the workflow engine, `service_types` master data, and every
Notary/PPAT deed, Minuta, Warkah, register and report surface. `08_NOTARY_WORKFLOW.md` and
`09_PPAT_WORKFLOW.md` remain placeholders requiring domain authority, and nothing may be
implemented from them.

### Open items

O-031, O-032 and O-033 remain open and untouched. M3.0 opened none.

---

## 2026-08-16 — M2.6 M2 Quality Gate

Branch `feat/m2-parties`. **No migration** (18 total) and **no permission** (171). An audit
milestone: no new product capability, no M3, no merge to `main`. Backend **1294 tests / 4476
assertions** — 2 new, both regression coverage for the one behavioural fix.

### What the gate found

Four defects fixed and one recorded. Three of the four are the shape M1.10 named (D-077): a
claim the repository made about itself that had quietly stopped being true.

**1. Two Policies said they had no HTTP surface.** `IndividualPolicy` carried "M2.1 exposes no
HTTP endpoint", written when that was true; M2.2 gave every ability in it a route.
`CompanyPolicy` carried "Nothing here is reachable over HTTP", and M2.3 and M2.4 built those
surfaces. Both now say what is actually true, and say when it changed.

**2. Six sites deferred identifier search to M2.5 as pending work.** Both list controllers,
both frontend list components, and two tests explained the exclusion of `nik`, `npwp`,
`tax_id`, and `registration_number` from directory search as *waiting for M2.5*.
`CompanyController` went further and named a keyed construction "nobody has designed" —
**D-086 designed it**. This mattered more than the usual stale comment, because it pointed the
wrong way: a reader would conclude identifier search was the planned next step. It is the
opposite. D-084 settled the rule strictly and permanently — a directory that answers "does
this identifier exist" is an existence oracle — and D-086 made `tax_id` technically matchable
without making it permissible. The exclusions were always right; only the reasons had expired.

**3. An N+1 in the reverse Individual → Companies view.** `can_view_company` was computed by
asking `CompanyPolicy::view` once per row. Because `EffectiveAccessResolver` is deliberately
uncached — a stale authorization cache fails in the direction that grants — each row cost a
fresh resolve plus an `exists()`. Measured before the fix: **16 queries for one relationship,
34 for ten, a steady two per additional row.** The Company-side relationship view was flat at
10, and the Party Directory flat at 13, so this was an asymmetry rather than a house style.

The per-row *check* is necessary — rows span different Companies and linkability genuinely
varies — but the actor's effective access does not vary by row, so resolving per row was
repeated work. `PartyVisibility::reachablePartyKeys()` asks the same predicate for every
company at once: one resolve, one query, identical answers. Two tests pin it — one asserting
the query count is **constant** rather than merely smaller, one asserting the batched flag
equals `CompanyPolicy::view` row by row across a live company, an archived company, and an
actor whose Company scope does not reach the Office.

An earlier draft of that second test tried to build a cross-Office relationship to compare
against and had its insert refused: M2.1's two composite foreign keys will not allow one. The
fixture was wrong, not the product — the same lesson M1.10 recorded — and the test now exercises
the two ways the answer legitimately varies instead.

**4. Recorded, not built: six fields the product supports everywhere except the interface.**
`gender`, `marital_status`, `village`, and `district` on Individual, and `village` and
`district` on Company, are accepted and stored by the Form Requests, present in the API
Resources, typed in the frontend, and **translated in both locales** — yet no form collects
them and no page shows them. The translated labels are the tell: the repository looks like it
supports these fields and does not.

This one is deliberately **not** fixed here. `gender` and `marital_status` carry legal weight
in Indonesian notarial practice, so deciding whether and how they appear is domain
specification, not a decision a quality gate may take (CLAUDE.md §62). Recorded as **O-033**.

### Verification

```text
Backend        1294 tests, 4476 assertions — 2 new (baseline 1292 / 4462)
Pint           passed
Frontend       format:check, lint, typecheck, build all clean
composer       validate --strict passed
Lockfiles      byte-identical to da779af, both sides
Migrations     18, none pending
Disposable DB  full chain from empty PostgreSQL; sync 171 then 0; rollback all 18
               leaving only the migrations table; re-migrated to 18; dropped
Smoke          38/38 over real PostgreSQL and Sanctum cookies, covering M2.1–M2.5
Clean clone    installed, keyed, formatted, linted, typechecked, tested and built
               from tracked files alone, following exactly the README's steps
```

The smoke walked the whole of M2 in one run: Individual and Company lifecycle with
`display_name` resynchronizing in both directions, the two-tier identity rules including
`parties.identity.update` conferring no readback, relationship add-and-close with a **409** on
a second close, `ALL` refusing to create a cross-Office relationship without disclosing whether
the person exists, the directory union, advisory duplicate detection with its Office bound
holding for an `ALL` actor, the reverse view's linkability flipping correctly when a company is
archived, and `OWN` and `ASSIGNED` failing closed on every surface.

**Teardown restored fifteen of the sixteen tracked counts exactly. The sixteenth is worth
stating rather than rounding off:** `sessions` went from 1 to 0. The surviving row from earlier
milestones had a `last_activity` of 2026-08-12 and `session.lifetime` is 120 minutes, so
Laravel's own database session garbage collector — `lottery` `[2, 100]`, and the smoke issued
well over a hundred requests — pruned a row that had been expired for four days. The teardown
did not do it: its guest-session clause only reaches rows newer than two hours. Correct
framework behaviour on stale data, not a product defect and not an incomplete teardown, but it
is a difference from baseline and is reported as one.

### Open items

**O-033 added** (six fields supported everywhere but the interface). O-031 and O-032 from M2.5
remain open and unchanged.

**O-018 re-verified rather than carried forward on trust:** next-intl is still 4.13.5 and still
contains no reference to `next/root-params`, while `setRequestLocale` remains load-bearing in
three files. Migration is still blocked upstream, not merely deferred.

O-021 and O-022 remain accepted deferrals on their own recorded terms — the sidebar still does
not carry the Notary and PPAT groups, and the Party Directory's page-level search is explicitly
not the global header search. O-004, O-010, O-015, O-017, O-024, O-025, and O-029 are unchanged.

---

## 2026-08-16 — M2.5 Party directory, duplicate detection, and reverse view

Branch `feat/m2-parties`. **One forward migration** (18 total) — the first since M2.1, and
expected. Permission count unchanged at **171**: the directory composes existing capabilities
and adds none. Backend **1292 tests / 4462 assertions** — 95 new. Frontend i18n **608 / 608**
exact. One new decision, **D-086**.

The backend landed first as a checkpoint commit (`459aeda`, observed CI-green as Quality #27)
with the frontend, the 72-step smoke, the disposable-database migration verification, and the
remaining documentation explicitly outstanding. This entry records the completed milestone.
The checkpoint stated 4430 assertions; a measured run gives **4462**, and the figure is
corrected here rather than carried forward.

### The decision this milestone existed to make

M2.0 deferred the sensitive-identifier duplicate mechanism and M2.1 deliberately added no
column, because locking a cryptographic design before reviewing it is how a weak one ships.
**D-086 settles it.**

`nik`, `npwp`, and `tax_id` use randomized encryption, so equality search against the stored
ciphertext is impossible by construction — and every obvious alternative is worse. Decrypting
the directory to compare in PHP does not scale and puts every identifier in memory to answer
one question; a plaintext copy defeats the encryption; an unkeyed hash of a 16-digit NIK is
brute-forceable in seconds.

The construction is a keyed blind fingerprint: an HKDF-SHA-256 subkey derived from the
application key under a versioned context string, then HMAC-SHA-256 of the normalized value.
Derived rather than reusing `APP_KEY` directly, so the purpose is domain-separated. Standard
PHP primitives only. No second production secret.

**Normalization is `trim` and nothing else.** Leading zeros, internal punctuation, and case
all survive, so `09.123.456.7-890.123` and `091234567890123` do **not** match. That is an
accepted false negative: no canonical document defines legal NPWP normalization, formats have
changed, and a guess would silently assert an equivalence nobody approved. Detection is
advisory, so a missed hint is the safe failure and a false claim is not.

The columns are indexed and **never unique** — uniqueness would assert identity, become a
cross-office existence oracle through rejected inserts, and turn advisory detection into
blocking enforcement. They are hidden at the model, absent from every Resource, and withheld
even from a holder of the full-view reveal permission, which authorizes the identifier
through the reviewed surface rather than the material derived from it.

Rotating `APP_KEY` invalidates every fingerprint, so
`php artisan parties:rebuild-identity-fingerprints` is the operational counterpart. It is
idempotent, prints counts only, and re-encrypts nothing.

### Advisory duplicate detection

Exact deterministic signals only — no fuzzy matching, no Levenshtein, no trigram, no score,
no "95% likely". A confidence number about identity is exactly the claim M2 has no authority
to make. Individual signals are NIK, NPWP, email, phone, and name-plus-birth-date; Company
signals are tax identifier, registration number, legal name, email, and phone.

**Always confined to the target Office**, including for an `ALL`-scoped actor: `ALL` permits
working in another Office, not a deployment-wide identity registry. A check for a NIK that
exists only elsewhere returns exactly what a check for a nonexistent one returns — no count,
no hint, no "match exists elsewhere".

**Sensitive signals answer to their own field permission.** Asking for a NIK match without
`parties.identity.nik.view_full` is a 403, not a quietly narrowed result, because silently
dropping the signal would let a caller infer the answer from its absence.
`parties.identity.update` is explicitly not accepted: writing a value is not licence to learn
that somebody else already has it.

Nothing blocks. No lifecycle Action refuses because a candidate exists, and a test records
the same tax identifier on two companies to prove it.

### Unified read-only Party Directory

`GET /api/v1/parties` — the first and only generic Party endpoint, and read-only forever.
Individual and Company own their lifecycles; a generic Party write would be a second way to
change the same records with none of their rules.

**No new permission.** Visibility is the union of `parties.view` and `companies.view`, and
the two are evaluated **independently and never collapsed** — an actor holding `parties.view`
at `OFFICE` and `companies.view` at `ALL` sees their own Office's people and every Office's
companies. Taking the widest scope would show records they cannot open; taking the narrowest
would hide records they can. The query is two capability-specific branches unioned, each
carrying its own predicate.

### Reverse Individual → Companies

The view M2.4 deferred. Read-only, two endpoints so the management/ownership split survives
the reversal, and `can_view_company` computed from the real Company policy with scope — a
company the actor cannot open is still named, because the person's history is about it, but
it is not linkable.

### Frontend

`/[locale]/parties` is the Party Directory: read-only, with search, a type filter, an Office
filter, and rows routed to the Individual or Company page. There is no New, Edit, or Archive
Party control and no generic Party detail page — lifecycle stays on the two subtype
directories, which this does not replace.

Navigation gained its first entry composed from more than one capability. "Directory" appears
when the account holds **either** `parties.view` or `companies.view`, expressed as an
`anyPermissions` list beside the existing `requiredPermission`. Requiring both would hide a
working page; inventing `parties.directory.view` would be a permission for a page rather than
for the records on it. Where both fields are set both must hold, so the new field can never
widen what a single required permission already narrowed.

Duplicate assistance is advisory in the interface as well as the API. The check runs once
before a save; if it finds anything, a neutral panel offers Review or Continue anyway, and
continuing performs the ordinary Action unchanged. A check that is refused, rate limited, or
unreachable lets the save through **immediately**, and the notice says the check did not run —
never that a duplicate exists, because reading existence into a refusal rebuilds the oracle the
permission closes. Save is disabled only while a request is in flight, never because a
candidate was found. There is no Merge, Replace, Use existing, or Archive duplicate control,
and no score is displayed.

Sensitive checks run only where the backend's own capability flag for that record says they
may — `can_reveal_nik`, `can_reveal_npwp`, `can_reveal_tax_id`, each computed from the real
Policy with Data Scope applied, which is strictly narrower than the check requires and so never
offers an assist the API would refuse. A field the caller cannot ask about is omitted from the
request rather than sent and refused, which would have taken the other field's assistance down
with it. The submitted identifier travels in a request body and nowhere else: the check is a
mutation with **no query key**, and the result is discarded on continue, cancel, save, and
unmount.

The Individual page gained a third section, **Companies** — the reverse view M2.4 deferred.
Read-only, with Management and Ownership as independent subsections each fetched only for a
holder of its own capability, so neither permission causes the other's data to be requested. A
company the caller cannot open is still named, because the person's history is about it, but it
links only when `can_view_company` says so.

### Verification

**72 / 72** on the full PostgreSQL + Sanctum smoke: real cookie-based SPA authentication with a
cookie jar and `X-XSRF-TOKEN`, no Bearer token anywhere. Highlights worth naming because they
are the claims easiest to assert and hardest to prove: the mixed-scope actor
(`parties.view` at `OFFICE`, `companies.view` at `ALL`) received their own Office's people
beside both Offices' organizations; an `ALL`-scoped check for Office A returned nothing for an
identifier that exists only in Office B; `parties.identity.update` alone got **403** on a
sensitive signal while its own identity update still succeeded; exhausting the duplicate
limiter at 31 attempts left both the reveal and password buckets working; and no response in
the entire run contained a fingerprint or a raw identifier outside the reveal and identity-write
endpoints that are meant to carry one.

Teardown restored the captured baseline **exactly** — every table count, and the surviving
session row identified as the one that predates the run. Redis returned to its baseline of zero
keys after the limiter TTLs expired.

The migration chain was verified from zero on a uniquely named disposable PostgreSQL database,
proven to be the target before anything destructive ran. Eighteen migrations; three `char(64)`
nullable fingerprint columns with plain btree indexes and **zero** `UNIQUE` constraints; the
fingerprint migration rolled back (columns and indexes gone, identity data intact) and reapplied
cleanly; the rebuild command populated, then reported zero changes on a second run. The database
was dropped and its absence confirmed.

Source scans found no `*_fingerprint` name outside the migration, models, fingerprint service,
identity Actions, duplicate query, maintenance command, and tests — none in any Resource,
frontend type, service, or component. No `localStorage`, `sessionStorage`, `document.cookie`, or
`console.*` call in frontend source; no identifier in any query key, URL, or fragment; no
generic Party mutation route; no merge, fuzzy, or scoring code.

### Documentation

`02` gained the composed-navigation rule and the note that no directory or duplicate permission
exists; `03` gained the fingerprint columns and had its index strategy corrected — it had listed
`individuals.nik`, `individuals.npwp`, and `companies.tax_id` as indexed, which cannot work,
since those columns hold randomized ciphertext; `06` gained the read-only aggregate, reverse
view, advisory-`POST`, and per-bucket rate-limit conventions; `07` gained "existence is a
disclosure" and the rules for derived cryptographic material; `12` gained the delivered frontend
and had section 15 corrected, which had said `ALL` "may see across Offices" — M2.5 decided the
opposite. The README documents the maintenance command.

---

## 2026-08-12 — M2.4 Company relationships

Branch `feat/m2-parties`. **No migration** (17 total) and **no permission** (171). Backend
**1197 tests / 4132 assertions** — 78 new. Eight API routes, two new Company detail sections,
i18n 535/535 exact. One new decision, **D-085**.

M2.1 built `company_people` and then deliberately left it alone. Everything M2.4 needed was
already there — both endpoints structurally constrained to their subtypes, the same-Office
invariant carried by two composite foreign keys through one column, no soft-delete column,
and history expressed as effective dates. The schema review found no defect and no migration
was required.

### Two surfaces, independent in both directions

Management (`DIRECTOR`, `COMMISSIONER`, `AUTHORIZED_PERSON`) answers to
`companies.management.*`. Ownership (`SHAREHOLDER`, `BENEFICIAL_OWNER`) answers to
`companies.shareholders.*`. Neither implies the other, and neither implies or is implied by
`companies.view` — who runs an organization, who owns it, and the organization's own details
are three separate questions.

That independence is structural rather than asserted. The two controllers share an abstract
base, and **the category is a property of the subclass, never a request parameter** — the
route points at a class, and the class knows what it is. Each surface rejects the other's
types with a 422. In the interface each section fetches its own endpoint behind its own
permission check, with its own query key, and the ordinary Company payload carries no
relationship data at all, so holding one capability cannot cause the other's data to be
requested.

### Append-and-close, now a property of the API (D-085)

D-083 already said history is preserved. It did not say what the API may therefore expose,
and that gap mattered: nothing in its wording forbids a `PATCH` that rewrites
`relationship_type` on an existing row, which would contradict its intent while satisfying
its letter. **D-085 closes that** — the public mutation surface is add and end, there is no
`DELETE` and no generic `PATCH` or `PUT` at any level, and `company_party_id`,
`individual_party_id`, and `relationship_type` are immutable once written. Superseding a
relationship is end-then-add: two rows, both readable.

Ending writes `effective_until` and nothing else. **It is not idempotent**: a second end
answers 409, because it asks to change a recorded end date, which is an amendment, and
quietly overwriting it would be the software correcting a legal record on its own initiative.
The end date is supplied by the caller and never defaulted to today — defaulting would invent
a fact about when an appointment ceased.

A relationship id used under the wrong Company, or on the wrong category's surface, answers
**404** rather than 403. A 403 would confirm the record is real and say which category it
belongs to, which is exactly what the permission split withholds.

### Same-Office, even for ALL

A relationship may only connect a Company and an Individual owned by the same Office, and
that holds for an `ALL`-scoped actor too: `ALL` grants reach and administrative visibility,
never the right to redefine domain ownership (D-080). The database makes a cross-office row
unrepresentable; the application refuses the candidate first, with a **generic 422 that does
not disclose whether that person exists** — the candidate list is same-Office precisely so it
cannot be used to probe another Office's directory.

### A narrower permission, and therefore a narrower payload

Picking a person is part of recording a relationship, so the candidate options endpoint is
authorized by the category's *update* permission rather than `parties.view` — requiring the
whole Party directory capability for a person-picker would grant far more than the task
needs. The price of asking for less is that the payload gives less: **an id and a display
name, and nothing else.** No identity, no masks, no contact details, no other companies.

The relationship resources are equally narrow. A relationship view permission is not a
sensitive identity permission (D-082), so neither surface carries NIK, NPWP, birth data, or
even a mask — a mask is still a statement about a sensitive value.

### Nothing about Indonesian corporate law

No director cap and no minimum. No required commissioner. No rule that one person holds one
role. No ownership total, no per-row cap at 100, no majority inference, and **beneficial
ownership is never derived from a shareholding** — it is recorded when somebody records it.
`ownership_percentage` is bounded only by its column, `decimal(7,4)`, and the smoke
deliberately records holdings summing to 175.5% to prove nothing objects. No date-transition
rule either, including any requirement that an end date follow a start date, because
`12_M2_PARTY_ARCHITECTURE.md` section 13 imposes none.

### History survives archiving

Archiving an Individual leaves their relationship rows exactly as they are — not ended, not
retyped, not deleted. Retiring somebody from the directory is not a statement about their
past appointments, and the relationship lists still show their name, flagged archived, read
from the retained Party. Archiving a Company likewise leaves `company_people` untouched while
making the ordinary relationship routes answer 404.

### Permission Matrix

The four relationship codes moved from *deferred* to implemented, which **empties the Party
domain from that list entirely** — what remains is `security.settings.*`, the flag's original
case. Four prior-milestone assertions were narrowed rather than deleted; each stated something
true when written that M2.4 deliberately changed.

### Verification

The specified 66-step smoke ran over real HTTP against **PostgreSQL 18** with the real Sanctum
SPA cookie flow. **66 / 66 passed.** It exercised the whole history story end to end: appoint
a director, end them, be refused a second end with 409, appoint a successor, and confirm the
first row still names the first person with its original type and end date — two `DIRECTOR`
rows coexisting because no cardinality rule was invented. Of 97 recorded responses, none
contained a raw identifier. Teardown restored the pre-smoke baseline exactly, by table count
and by row identity.

### Deferred, unchanged

Duplicate detection and identifier search remain M2.5, as does the reverse
Individual → Companies view — the relation exists, but adding it because the data is reachable
would be broadening scope on the strength of a foreign key. Amendment of a recorded
relationship stays undesigned.

---

## 2026-08-12 — M2.3 Company management

Branch `feat/m2-parties`. **No migration** (17 total) and **no permission** (171). Backend
**1119 tests / 3829 assertions** — 138 new. Nine API routes, four frontend routes, i18n
495/495 exact. The Party aggregate's second subtype, and the last one M2 defines.

M2.1 designed the schema so this milestone would be mechanical, and M2.2 settled the
patterns. What M2.3 added is the HTTP surface, one derivation rule the Individual side does
not have, and the interface.

### Company lifecycle

Create, list, detail, update, archive — the structural mirror of Individuals. Aggregate
writes go through domain Actions in a single transaction, because **"no Party without a
subtype" is the one M2.0 invariant no constraint can carry** (D-078). A test forces the
subtype insert to fail and proves no orphan Party survives.

`party_type` is set from the enum and never from input, so no request shape produces an
INDIVIDUAL through the Company endpoint. `entity_type` is validated against the live enum —
the seven values `03_DATABASE_ERD.md` names, transcribed and not extended — and `legal_name`
and `entity_type` are the only required fields, both for **structural** reasons. No corporate
rule is encoded anywhere: no required director, no unique registration number, no capital
rule, no format for anything. Those are legal questions this milestone has no authority to
answer.

**Lifecycle authorizes on `companies.*` and never additionally on `parties.*`.** Creating a
Company writes a Party row inside its transaction, but that is persistence composition, not
an authorization fact — requiring two permissions because of it would leak the schema into
the permission model. Authorization describes what a user may do, not how many tables it
touches.

Archive sets `parties.deleted_at` and leaves both the subtype and `company_people` untouched
(D-081): deleting relationship rows would destroy the history D-083 exists to keep, and an
archived company keeps its record of who was a director and when. Route binding resolves live
Companies only, so an archived record and an **Individual** Party id both answer 404.

### The derivation rule that differs from Individuals

`display_name` comes from `short_name` when one is intentionally present and `legal_name`
otherwise (D-079) — a short name exists precisely because somebody wanted the organization
displayed that way.

That rule has **two inputs**, which is why the update action recomputes the display name on
every update rather than only when a name field was submitted. Removing a short name changes
the display name without touching the legal name; adding one does the same. A conditional
"only sync when the name changed" would have to enumerate those cases correctly forever, and
asking the updated record what it should be called cannot get it wrong. Six tests cover the
combinations, and the smoke walks the full sequence over HTTP: legal-name rename, short name
added, short name removed.

### Sensitive tax identity

The Company `tax_id` **is** the NPWP, so it answers to the canonical
`parties.identity.npwp.view_full` — the same code an Individual's NPWP uses. **No
`companies.identity.*` family was invented**, because the identity surface belongs to the
aggregate rather than the subtype. `companies.view` reaches neither the surface nor the
value, `parties.identity.view` opens the surface with the value still masked, and
`parties.identity.update` returns a mask rather than echoing what was submitted.

Reveal reproduces the M2.2 contract exactly, which is what that contract was written down
for: `POST` rather than `GET`, `no-store` on the response, and the `party.identity.reveal`
limiter. Individual NIK, Individual NPWP, and Company `tax_id` share that one bucket on
purpose — alternating between fields, or between subtypes, must not buy extra budget — and a
test proves exhausting it still leaves the password route on its own budget.

### Permission Matrix honesty

The four Company lifecycle codes moved from *deferred* to implemented, which is the flag
working rather than the list churning: M2.2 put "Clients & Parties" in the sidebar without
shipping Companies, so the badge was earned then and is stale now.

**`companies.management.*` and `companies.shareholders.*` stay deferred**, and more sharply
than before: Companies is a live surface now, so an administrator granting
`companies.management.view` has every reason to expect a directors section. There is none.
The claim is checked against the router in both directions (D-077).

### Frontend

Four routes under `/[locale]/parties/companies`. The create and edit forms carry no `tax_id`
field at all, so `companies.update` cannot quietly acquire `parties.identity.update`. Detail
shows Overview and Identity only — no Management or Shareholders section, and the API sends
no relationship collection for one to render. Directory search covers the company names,
phone, and email; neither `tax_id` (encrypted, and unmatched by design) nor
`registration_number` (the duplicate signal M2.5 owns) is searchable.

Entity types render translated labels from stable codes. The Indonesian legal forms keep
their own names in both locales — *Yayasan*, *Perkumpulan*, *Koperasi*, *Firma* — with an
English gloss in parentheses rather than a substitute term, per `05_I18N_LEGAL_TERMINOLOGY.md`.

A revealed value is held in component state, cleared on unmount, and has **no query key**.
Nothing is written to `localStorage`, `sessionStorage`, or the URL.

### Verification

The specified 42-step smoke ran over real HTTP against **PostgreSQL 18** with the real
Sanctum SPA cookie flow — CSRF priming, cookie jar, `X-XSRF-TOKEN`, session cookie, no bearer
token — from the frontend origin. **42 / 42 passed.**

The stored `tax_id` column holds ciphertext that decrypts back to the submitted value; the
reveal response carries `no-store` through the real HTTP stack; and of 83 recorded responses
the raw identifier appears in exactly the one authorized reveal body and nowhere else, with
the application log carrying `PARTY_IDENTITY_REVEALED` metadata and zero raw values.

Teardown restored the pre-smoke baseline exactly — every table count and every row identity,
against a manifest captured before the first fixture existed. Permission count 171 and
migration count 17 throughout.

### Deferred, unchanged

Company relationships — management, shareholders, beneficial owners — remain M2.4. Duplicate
detection and identifier search remain M2.5. Office transfer stays undesigned and is refused
rather than approximated. NPWP format validation stays deferred pending domain authority.

**No new decision.** M2.3 is the Company half of rules D-078 through D-084 already settled,
built to the reveal contract M2.2 recorded. Adding a decision for ordinary CRUD that followed
its own architecture would be summarizing an implementation, not settling a conflict.

---

## 2026-08-12 — M2.2 Individual management

Branch `feat/m2-parties`. **No migration** (17 total) and **no permission** (171). Backend
**981 tests / 3451 assertions** — 85 new. Ten API routes, four frontend routes, i18n 419/419
exact. The first Party-domain business surface.

M2.1 was built so this milestone would be mechanical, and it was: the schema, the enums, the
scope predicates, and the policy abilities all existed already. What M2.2 added is the HTTP
surface, the transaction that carries the one invariant the database cannot, and the
interface.

### Individual lifecycle

Create, list, detail, update, archive. Aggregate writes go through domain Actions in a single
transaction, because **"no Party without a subtype" is the one M2.0 invariant no practical
constraint can enforce** (D-078) — it rests on that rollback and nothing else. A test forces
the subtype insert to fail and proves no orphan Party survives.

`party_type` is set from the enum and never from input, so no request shape produces a COMPANY
through the Individual endpoint. `display_name` is derived from the canonical full name in the
same transaction (D-079): a rename cannot leave the directory showing one name and the detail
page another. `office_id` is refused on update rather than ignored — moving a Party between
Offices crosses a security boundary and would strand any relationship pinned to the old one,
so M2.2 rejects it instead of inventing semantics.

Archive sets `parties.deleted_at` and leaves the subtype row alone (D-081). Route binding
resolves live Individuals only, which is why an archived record and a Company Party id both
answer **404** — telling a caller "wrong type" would confirm a record exists in a namespace
they were not asking about, possibly in an Office they cannot see. No `DELETE`, and no
restore: the registry defines `parties.archive` and no counterpart to authorize one with.

### Sensitive identity, D-082 end to end

M2.0 left the reveal route shape open between per-field operations and one conditionally
serializing endpoint. **M2.2 settled it as per-field operations**, and the reason is the
frontend cache: a conditional `GET` puts the raw value in an ordinary response, which is
exactly what ends up cached.

Ordinary list and detail serialize `nik_masked` / `npwp_masked` computed server-side. There
is no `nik` key in the payload to un-hide — two independent defences, the Resource's explicit
attribute list and the model's `#[Hidden]`, would both have to fail. Reveal is a `POST`
answering `no-store`, authorized per field: NIK and NPWP imply nothing about each other,
`identity.view` reveals neither, and `identity.update` returns masks rather than echoing what
was submitted, so writing a value confers no readback of another.

The reveal limiter is deliberately separate from the `security.*` buckets, and a test proves
exhausting it does not disable the password route — **the M1.9 defect, guarded rather than
remembered**. NIK and NPWP share the one reveal bucket on purpose: alternating fields must not
buy twice the budget.

The access event is logged with actor, record, and field. The value is not, at any level.

### Scope behaviour

`OFFICE` and `ALL` are the only scopes that reach a Party (D-080). `OWN`, `ASSIGNED`, and
`TEAM` fail closed — a holder of all eight `parties.*` codes at any of those three reaches
nothing, including list, which is refused outright rather than returning a reliably empty
page. Visibility is applied **in the query**, so an office-scoped caller's SQL never selects
another Office's rows and no filter can widen it; `permits()` runs the identical constraint
against one key, so "what appears in the list" and "what may I open" cannot drift apart.

### Permission Matrix honesty

`companies.*` joined the deferred list, which reads like a step backwards and is not. Before
M2.2 the Party module was absent from navigation, so `companies.view` needed no badge for the
same reason `projects.create` does not. Shipping Individuals put "Clients & Parties" into the
sidebar — the namespace now looks implemented, and an administrator granting `companies.view`
would reasonably expect something. `parties.*` left the list, and the claim is **checked
against the router** rather than asserted (D-077).

### Frontend

Four routes under `/[locale]/parties/individuals`. The create and edit forms carry no identity
fields at all, so `parties.update` cannot quietly acquire `parties.identity.update`. Detail
shows Profile and Identity only — no Companies section, because M2.4 owns relationships and an
empty tab is a promise the product cannot keep. Directory search covers name, phone, and
email; identifier search is M2.5 and would turn the directory into an existence oracle.

A revealed value is held in component state, cleared on unmount, and has **no query key** —
giving it one would put a raw NIK in a cache that outlives the component and survives
navigation. Nothing is written to `localStorage`, `sessionStorage`, or the URL.

### Verification

Closure ran the specified 40-step smoke over real HTTP against **PostgreSQL 18** with the real
Sanctum SPA cookie flow — CSRF priming, cookie jar, `X-XSRF-TOKEN`, session cookie, no bearer
token anywhere — from the frontend origin. **40 / 40 passed.**

What the smoke proves that the Pest suite cannot: the stored `nik` and `npwp` columns hold
ciphertext that decrypts back to the submitted value (228 and 200 bytes for 16- and 15-digit
inputs); the reveal response really carries `no-store` through the HTTP stack; the reveal
budget and the password budget are genuinely separate buckets; and 78 recorded responses
contain the raw identifiers in exactly the two authorized reveal bodies and nowhere else, with
the application log carrying `PARTY_IDENTITY_REVEALED` metadata and zero raw values.

Teardown restored the pre-smoke baseline exactly — every table count, and every row identity,
compared against a manifest captured before the first fixture existed. Permission count 171,
migration count 17, both unchanged throughout.

### Deferred, unchanged

Company management (M2.3), company relationships (M2.4), duplicate detection and identifier
search (M2.5). NIK and NPWP format validation stays deferred pending domain authority — no
canonical document freezes either format, and the Form Requests are exactly where somebody
would be tempted to add `digits:16` from memory.

**No new decision.** M2.2 introduced no durable architecture rule that D-078 through D-084 do
not already carry: the reveal transport is the mechanism D-082's "never in a URL, never in a
cache key" already required, and the separate limiter is D-071's reasoning applied forward.
Both are recorded as contract in `07_SECURITY_RULES.md` section 12 and
`12_M2_PARTY_ARCHITECTURE.md` section 11, where M2.3 will need them.

---

## 2026-08-11 — M2.1 Party schema and authorization foundation

Branch `feat/m2-parties`. **Four forward migrations** (17 total). Permission count unchanged
at **171**. Backend **896 tests / 3235 assertions** — 113 new. No API surface and no frontend
change: M2.1 makes M2.2 and M2.3 mechanical, it does not build them.

### The correction M2.1 had to make to M2.0

M2.0 claimed `party_id` as PK/FK "enforces one-to-one structurally". **It does not, quite.**
That gives no-orphan-subtype and no-duplicate-subtype, but it permits one Party to hold *both*
an Individual and a Company row, and says nothing about whether a subtype agrees with its
Party's `party_type`. The wording is corrected in place rather than left to be believed.

The gap is now closed by the database, not by convention. `parties` carries
`UNIQUE (id, party_type)`; each subtype pins its own `party_type` and completes a composite
foreign key back to it. One constraint yields three invariants: a subtype must match its
Party's type, a Party cannot hold both subtypes, and `party_type` cannot be updated while any
subtype exists — against raw SQL, not merely against Eloquent.

**One invariant stays honestly domain-only.** No practical constraint makes a parent row
require a child, so "no Party without a subtype" rests on the transaction M2.2 and M2.3 will
own. The architecture document now carries an enforcement table saying which is which, because
a domain rule documented as a database constraint is one somebody will later assume they
cannot break.

### Same-office relationships, enforced rather than intended

`company_people.office_id` is a constraint carrier: two composite foreign keys reference
`parties (id, office_id)` through that **one** column, so both endpoints must agree with it
and therefore with each other. A cross-office relationship is unrepresentable. Endpoint
subtypes are structural too — the foreign keys target `companies.party_id` and
`individuals.party_id`, so a relationship cannot point at an arbitrary Party.

### Sensitive identity

NIK, Individual NPWP, and Company `tax_id` are `encrypted` casts and hidden at the model.
Ordinary `toArray()` and `toJson()` carry none of them, proven by test — the moment a raw
identifier enters serialization it is also in a log line, a cache entry, and any response that
serialized the model without thinking.

Authorization is two-tier per D-082 and tested directly against the policies: opening the
identity surface reveals nothing, NIK reveal implies nothing about NPWP and the reverse,
`identity.update` confers no readback, and `companies.view` reveals no tax identifier. The
Company NPWP uses the canonical `parties.identity.npwp.view_full` — no `companies.identity.*`
family was invented.

### Scope metadata made truthful

Party permissions previously fell into the permissive default in `PermissionScopeRules` and
were offered at `OWN`, `ASSIGNED`, `OFFICE`, and `ALL`. Three of those the resolver could never
honour, so the Permission Matrix was offering grants that would save and then do nothing. They
now offer **OFFICE and ALL only** (D-080), and the unsupported three fail closed in
`PartyVisibility`.

The deferred list is deliberately **unchanged**. Party is absent from navigation entirely,
exactly as `projects.*` is, so it needs no badge — adding one would contradict the semantics
M1.10 settled and imply Party is partially shipped.

### Verification

```text
Backend        896 tests, 3235 assertions — 113 new (baseline 783)
Pint           passed
Migrations     17, all applied
Disposable DB  full chain from empty PostgreSQL; 13 invariants proven there; dropped
Frontend       format:check, lint, typecheck, build all clean — zero runtime files changed
Scans          authorization, role-name, no-Client, no-M3, no-party-route: all pass
```

The PostgreSQL run proved what SQLite cannot: the CHECK constraints on `party_type`,
`entity_type`, and `relationship_type`, and that stored NIK and `tax_id` are ciphertext rather
than readable identifiers.

---

## 2026-08-11 — M2.0 Party architecture lock, resolving O-004

Branch `feat/m2-parties`, from `main` at `501401f`. **Documentation only.** No migration, no
model, no endpoint, no permission. Migration count stays 13; canonical permission count stays
**171**; backend 783 tests / 3043 assertions unchanged.

The milestone exists so that M2.1 transcribes an architecture instead of inventing one while
writing migrations.

### The decision that shapes the rest

**One Party aggregate. "Client" is a word, not a table** (D-078). A `clients` table would
freeze a role into a master record, which CLAUDE.md section 17 already refuses for Party
roles — the same person is a seller in one matter and a director in another. Subtypes take
`party_id` as both primary key and foreign key, so "exactly one subtype per Party" and "no
orphan subtype" are enforced by the schema rather than by convention. `party_type` is
immutable; a wrong type is archived and recreated, visibly.

**This resolves O-004**, which had been deferred since M0.1 as a cosmetic label mismatch
between "Party / Individual / Company" and "Client Database". It was cosmetic right up until
the milestone that would have turned the second reading into a duplicate table.

### Sensitive identity, locked two-tier

The live registry carries four canonical codes (D-001) that the planning material did not
account for. Read from the registry rather than assumed, they form two tiers (D-082):

```text
parties.identity.view            opens the surface — NIK and NPWP stay masked
parties.identity.update          mutation; confers no full readback
parties.identity.nik.view_full   raw NIK only
parties.identity.npwp.view_full  raw NPWP / tax identifier only
```

Neither tier-2 code implies the other. Company tax identity uses the existing NPWP code — no
`companies.identity.*` family invented.

**A browser not authorized for a raw identifier never receives it** — absent from the payload,
not hidden by CSS. `07_SECURITY_RULES.md` section 12 said "avoid returning full values when
unnecessary", which is weaker than what M2.1 must build, so it was strengthened rather than
left to be discovered later.

Format validation for NIK and NPWP is **deferred**: no canonical document freezes either
format, and encoding a guess would reject real identifiers.

### Four ERD fields dropped, each with a reason

`parties.status` and `companies.status` competed with `deleted_at` for lifecycle authority;
`companies.phone` / `companies.email` duplicated the Party contact fields that `individuals`
does not carry; `company_people.is_current` duplicated what `effective_until` already says.
Each is a second source of truth whose disagreement would be invisible. `deleted_at` is now
the sole archive authority (D-081), and `03_DATABASE_ERD.md` section 6 carries a pointer so
the blueprint cannot be read as current.

### Also locked

Party is Office-owned; `OWN`, `ASSIGNED`, and `TEAM` **grant nothing** and fail closed
(D-080) — `OWN` must not become `created_by`, since typing in a record is not a claim on the
person it describes. Company relationships preserve history and never overwrite a predecessor
(D-083); management and ownership map to their existing separate permission surfaces without
inventing corporate-law cardinality. Duplicate detection is advisory and Office-scoped, never
auto-merging, which is also why no `UNIQUE` constraint is placed on any identifier — it would
be a cross-office existence oracle (D-084).

### Boundary

No Project, Matter, Document, Property, Warkah, global Search, or audit-log module. Project
remains M3. Six open questions are recorded rather than assumed — none blocks M2.1, and the
two most likely to tempt improvisation while writing migrations (the keyed dedup fingerprint
and NIK/NPWP formats) are named explicitly.

New document: `12_M2_PARTY_ARCHITECTURE.md`. `docs/10` and `docs/11` were already taken, so
it takes the next free number rather than overwriting either.

---

## 2026-08-11 — M1.10 M1 Quality Gate, resolving O-023

Branch `feat/m1-identity`. **One forward migration** (13 total). Permission count unchanged
at **171**. An audit milestone: no new product capability, no M2, no merge to `main`.

### What the gate actually found

Three defects, all of the same shape — a claim the repository made about itself that had
quietly stopped being true (D-077):

**1. The documented quality gate was weaker than CI.** `CLAUDE.md` §51/§52 listed three
frontend commands; `.github/workflows/quality.yml` enforces four. That gap produced the red
run on `c231eda` in M1.9. `README.md` had all four all along, which is the point worth
naming: one document being right is no help when another is the one being followed.

**2. The Permission Matrix badged a built feature as unavailable.** `users.reset_password`
still carried "not yet available" nine commits after M1.9 implemented it. Corrected, and
the list is now asserted against the router — a badge cannot outlive the gap it describes.
`security.settings.view` and `security.settings.manage` take its place, because those
genuinely have no endpoint, and they sit beside `security.sessions.*` and
`security.mfa.manage`, which are live. The bilingual hint that named a specific milestone
was rewritten to be milestone-agnostic, since naming one is how it went stale.

**3. `README.md` gave setup instructions that could not work.** It told a new developer to
create their first user through `php artisan tinker`, which M1.6 superseded with
`php artisan app:bootstrap`. Following the README would fail: a user requires an Office
(FK-required) and permissions.

### O-023, resolved

`UNIQUE (organization_id, code)` on `offices`, implementing D-037 rather than deciding
anything new. Composite, not global — two Organizations may each run a `PUSAT`.

D-037 had scheduled it beside a matching Form Request. **That condition could not be met
inside M1**: M1 ships no Office write endpoint, so there was no validation layer to
disagree with, and deferring again would carry an already-decided invariant past the
milestone that closes M1. Data safety was verified before the migration was written —
`offices` held 0 rows and the duplicate query returned none.

### A missing test the codebase claimed to have

`DefaultRoleRegistry` has always documented that "a test greps for exactly that" about
role-name branching. **No such test existed.** The M1.4A scan covers permission codes only,
so `hasRole('SUPER_ADMIN')` would have passed it untouched. Added, with a sentinel that
fails if the scan walks fewer than 50 files — M1.8 shipped a scan that reported clean
because `rg` was missing, and that lesson is now encoded rather than remembered.

### Verification

```text
Backend        783 tests, 3043 assertions — 8 new (baseline 775)
Pint           passed
Frontend       format:check, lint, typecheck, build all clean
composer       validate --strict passed; lockfiles byte-identical to baae1bc
Migrations     13, all applied
Disposable DB  full chain from empty PostgreSQL; sync 171, re-run 171; dropped
Smoke          49/49 steps over real Sanctum cookies on localhost, 0 failures
Teardown       permissions 171, users 0, organizations 0, offices 0, sessions 0
Clean clone    installed, migrated, tested and built from tracked files alone
```

The eight: five for office-code uniqueness plus a migrate/rollback/re-migrate probe, one
role-name branching scan, and two keeping the deferred-permission list honest.

The smoke exercised M1.1 through M1.9 in one run — office boundary and `ALL` boundary,
effective-access projection, profile and locale, password change and reset, email change,
session revocation, the full MFA lifecycle, account disable, and the administrator
continuity invariant — then the two new O-023 semantics against real PostgreSQL.

Two smoke failures were my own fixture's fault, not the product's: `RolePermissionScope` is
fully guarded by design, so both `updateOrCreate` and `firstOrNew` were correctly refused.
The fixture was fixed; the model was not touched.

### Open items

O-023 resolved. O-015 remains open but its disposition was **wrong** and is corrected:
`frontend/AGENTS.md` and `frontend/CLAUDE.md` are regenerated by `next dev` — verified in
`generate-agent-files.js` — so deleting them produces a recurring dirty tree, not a tidier
repository. O-029 remains open and deliberately unclaimed. O-004, O-010, O-017, O-018,
O-021, O-022, O-024, O-025 remain accepted deferrals, each re-verified against the
repository rather than carried forward on trust.

---

## 2026-08-11 — M1.9 Account Security, resolving O-028 and O-030

Branch `feat/m1-identity`. **One forward migration**, adding seven columns to `users`.
No new canonical permission — the count stays at **171**. The four codes this milestone
needed (`users.reset_password`, `security.sessions.view`, `security.sessions.revoke`,
`security.mfa.manage`) were already in the registry and had been waiting for an
implementation.

### The rule the administrative surface is shaped around

An administrator **restores** access and never **acquires** it (D-071). They can trigger
a reset, end sessions, and remove a second factor. There is no endpoint in the
application that lets them choose a password, see a temporary one, read a reset token, or
read or set a two-factor secret.

The reason is specific to this domain: someone who can silently become another user can
sign a deed as them, and in a Notary office that is not a recoverable mistake.

Self-service is the mirror of it and needs **no permission at all** — `security.*`
describes administering other people, and requiring one to change your own password would
mean an account could be forbidden from securing itself. Enforced structurally, as D-066
did for the profile: no self-service route accepts an id.

### Password

`PasswordRules` is now the single source for every place a password is set — creation,
bootstrap, self-service change, reset completion (D-070). Four copies of
`Password::default()` would look identical right up to the day one was tightened and the
weakest path quietly became the policy.

Changing a password revokes every **other** session and regenerates the current session
id (D-072). Completing a **reset** revokes everything and creates **no session**:
auto-login there would turn one emailed link into a complete bypass of MFA. Roles,
permissions, scopes, overrides, Office, profile, locale, and two-factor configuration all
survive both — a password reset is not an account reset, and tests assert each of them.

### Two-factor

RFC 6238 via `pragmarx/google2fa`, QR via `bacon/bacon-qr-code`. **No cryptography was
written** (D-076).

An account with two-factor is **never logged in by its password alone** (D-075).
`POST /login` answers `202 {"two_factor": true}` and creates nothing;
`POST /login/two-factor-challenge` is what creates the session. After the password step,
`/api/v1/me` answers 401 and `last_login_at` is untouched — the tests assert this
directly, because the distinction is the entire security value.

`two_factor_secret` and `two_factor_confirmed_at` are separate columns on purpose:
enrolment counts only once a code verifies, so closing the setup dialog cannot lock
somebody out. Secret and recovery codes are encrypted at rest; recovery codes are also
hashed and consumed one at a time, returned raw **exactly once** and unrecoverable
afterwards — including to the user and to any administrator.

### Email address

Two-step, and the current address holds until the new one is proven (D-073). Only a
SHA-256 of the token is stored, the link goes to the **new** address, and confirmation
needs the token *and* a signed-in session, so a forwarded email cannot move an account.
Resolves **O-030**.

### Sessions

Enumerable because `SESSION_DRIVER=database`, which is what makes "sign out everywhere"
real rather than aspirational (D-074). **A raw session id never leaves the server** — the
API works in SHA-256 digests, which name a row without being usable as one. Payloads,
CSRF tokens, and full user-agent strings are never exposed.

**Disabling an account now ends its open sessions.** M1.5 left this to M1.9 and in doing
so left a real hole: no *new* session could start, but every open one kept working until
it expired.

### Rate limiting

Named limiters, because bucket sharing is deliberate in one case and a bug in the other.
Laravel's unnamed throttle keys authenticated requests on the user id alone, so every
route carrying it would share one budget by accident — mistyping a password three times
would block starting an enrolment. Everything taking `current_password` shares
`security.password` **on purpose**, so the rule cannot be used as an oracle by rotating
between endpoints.

### Verification

```text
Backend      775 tests, 3024 assertions — 133 new (baseline was 642)
Pint         passed
Frontend     lint, typecheck, build all clean
Migration    applied to PostgreSQL 18.4; 12 migrations, all Ran
Smoke        66/66 steps over real HTTP + Sanctum cookies, 0 failures
Teardown     fixtures removed; permissions still 171, users 0, sessions 0
```

The 133 split across seven files: password change (12), sessions (16), email change (19),
two-factor enrolment (23), two-factor login (20), administrative security (24), and
surface/leakage scans (19). Two M1.5 inventory assertions were updated rather than
deleted — the `users` column list and the `api/v1/users` route list both grew, and both
exist precisely to make that growth visible.

The smoke ran the browser's own flow — CSRF cookie, cookie jar, XSRF header, no bearer
token — and confirmed the properties that matter end to end: no session after the
password step for a two-factor account, a spent recovery code refused, the other device
signed out by a password change, the reset token absent from every administrative
response, disabling ending live access immediately, and a completed reset creating no
session.

The first smoke run **failed** at steps 43–48 with 429s. That was not a test artifact —
it was the shared-throttle defect described above, found because the smoke exercised the
endpoints in the order a real person would. Fixed with named limiters, then covered by
two tests so it cannot return.

---

## 2026-08-10 — M1.7 Permission-aware navigation, resolving O-026

Branch `feat/m1-identity`. **No migration.** The interface now derives what it shows from
the same authorization model the backend enforces with.

### O-026, resolved

`GET /api/v1/me` built its `permissions` array from Spatie's `getAllPermissions()`. That
counted direct user-permission grants the model excludes (D-029, D-041), carried no Data
Scope, and ignored overrides — so the browser and the backend could disagree about what
somebody could do. Presentation-only, so never a vulnerability; a defect nonetheless.

The endpoint now reports **effective access** from `EffectiveAccessResolver` (D-062):

```text
permissions        canonical codes the account effectively holds, canonical order
permission_scopes  each one's exact Data Scope set, documentation order
roles              unchanged, and still presentation only
```

A permission appears only when granted. Excluded exactly as the resolver excludes them:
direct package grants, stale codes, grants missing scope metadata, expired overrides,
malformed ALLOW overrides, and canonical names with no row. DENY and ALLOW overrides and
multi-role unions are all reflected. The endpoint stays read-only — a test asserts every
statement it issues is a `select`, and the expired override it sets up is still there
afterwards.

### One rule, two entry points

`resolve()` and the new `resolveAll()` load plain `AuthorizationState` and hand it to the
same private `decide()` (D-061). Nothing about allow/deny, scopes, or ordering exists
twice, so the projection cannot drift from the check that guards an endpoint. A test
resolves **every** canonical permission both ways against a fixture carrying multi-role
unions, an active DENY, an active ALLOW, an expired override, a scope-less grant, a corrupt
scope value, a stale permission, and a direct grant — and requires identical answers,
scope order and source included.

`resolveAll()` costs **four queries regardless of registry size**; a test asserts resolving
171 permissions is no more expensive than resolving one.

### Presentation follows it

`can()`, `canWithScope()`, `PermissionGuard` (now scope-aware), and navigation filtering
all read the projection, never a role name (D-063). `canWithScope` is exact membership:
`{OFFICE}` does not satisfy `ALL`, `{OFFICE, ALL}` does. There is no "wide enough" helper
and no ordering anywhere.

Record-level predicates are deliberately **not** reproduced in React — an office-scoped
administrator sees an Edit control, and whether a particular colleague is theirs to edit is
the Policy's answer when the request arrives.

Navigation requires two independent conditions: the destination must be **implemented** and
the account must hold the permission (D-064). Bootstrap gives `SUPER_ADMIN` all 171
permissions, so permission alone would light up Projects, Notary, PPAT, and Billing and
link to routes that 404. Parents render only when a child survives; desktop and mobile
share one filtered result. Users needs `users.view` at any scope; Roles needs `roles.view`
at `ALL`.

Saving the matrix or role membership invalidates `["auth", "me"]` (D-065), so an
administrator who changes their own access sees the interface follow immediately rather
than after signing out. The matrix and the membership dialog render read-only when the
account may view but not assign.

### Verified

**Backend** 598 tests, 2306 assertions — 28 new. Pint clean. `migrate:status` unchanged
at 11.

Five M0.7/M0.8 `/me` tests changed meaning and were **rewritten, not deleted**, each noting
why — including one that asserted direct grants appeared alongside inherited ones, which was
O-026 itself and is now asserted in the inverse.

**Frontend** format, lint (0 errors), typecheck, and build all pass. The repository has no
frontend test framework and M1.7 did not add one; instead a scratchpad harness runs the
**real** `navigation.ts` and `can.ts` under Node with the `@/` alias resolved — 31/31 across
helper semantics, TEAM treated as an exact value, the parent-menu rule, role names conferring
nothing, and a fully-privileged administrator still seeing only implemented destinations.

**PostgreSQL, over a real Sanctum session** — 22/22: an OFFICE user holding `users.view` and
not `roles.view`, a global user the reverse, a multi-role union, an active DENY, an active
ALLOW replacing role scopes, an expired override falling back, a direct package grant
excluded, and a stale permission excluded. Temporary data removed; 171 canonical permissions
preserved.

### Also recorded

**D-059** and **D-060** were implemented at M1.6 and cited by its code but never written
down. Both are now recorded — a citation pointing at nothing is worse than no citation.

**O-028** (reset password, M1.9) and **O-029** (override administration, unowned) both
remain open and untouched.

---

## 2026-08-10 — M1.8 Profile & language preference

Branch `feat/m1-identity`. Self-service profile and a persistent interface
language. **No migration** — `phone` and `deleted_at` arrived with M1.5 and
`preferred_locale` has existed since M0.

### Self-service, not administration

```text
GET   /api/v1/profile     authentication only
PATCH /api/v1/profile     authentication only
```

No permission guards either, and none was invented — the registry has no
`profile.view` and adding one so a menu entry could render would put a fake
capability in a catalogue whose value is that it describes real ones (D-066). The
target is always the caller: there is no `/profile/{user}` and no query string
that introduces one.

Deliberately **not** routed through `UserPolicy`, which excludes `OWN` from
administrative update on purpose (D-049). Bending that to fit self-service would
weaken the rule it exists to state.

Editable: `name`, `phone`, `preferred_locale`. Everything else is **rejected with
422 rather than silently dropped** — an interface that appears to accept a change
it never made is worse than one that declines. `email` and `office` are shown as
plain text rather than disabled inputs, because a disabled field still reads as
"editable, just not now" (D-067).

A profile save touches no pivot: role memberships, direct permissions, scope
metadata, and overrides are asserted unchanged, and the `/me` authorization
projection is asserted byte-identical before and after each editable field.

### Language

D-020 kept the URL the only source of the active locale and left this gap
explicitly open; M1.8 fills it without reopening detection (D-069).

Exactly **one** moment a stored preference decides a locale: the redirect after
signing in. `preferred_locale = en` lands on `/en/dashboard` even from
`/id/login`. After that the URL is authoritative again — opening `/en/...` with a
stored `id` shows English, is never rewritten, and never quietly updates the
preference. Typing a URL is a navigation, not a declaration about future
sessions.

The Language Switcher **is** an explicit declaration, so it persists first and
navigates second. Navigating first would leave the interface in English while the
stored preference silently stayed Indonesian on a failed request; on error
nothing moves and the failure is reported. One mutation path serves both the
header switcher and the profile page, so the two cannot drift.

A stored value the routing configuration does not recognize falls back to `id`,
and **reading it never repairs it** — writing to the database as a side effect of
a page load is how a silent fix becomes impossible to explain later.

`localeDetection` and `localeCookie` stay off; nothing touches `localStorage`,
`sessionStorage`, `navigator.language`, or `accept-language`.

### Frontend

`/[locale]/profile`, reached from the account menu — not a Settings destination,
since Settings holds administration and this is its opposite. Personal
information, read-only work context, and a language section. **Preferences**
links to that section rather than a second screen holding one control. There is
**no Security entry**: M1.9 owns it, and a menu item leading nowhere is worse
than an absent one.

Saving name or phone refetches `["auth","me"]`, so the header and account menu
stop showing a stale name without a sign-out. 44 new keys; id and en at exact
parity (231 = 231).

### Verified

**Backend** 642 tests, 2478 assertions — 44 new. Pint clean. `migrate:status`
unchanged at 11.

**Frontend** format, lint (0 errors), typecheck, and build all pass, with the new
route present. The repository has no frontend test framework and this did not add
one; a scratchpad harness compiled the real `landing-locale.ts` with the project's
own TypeScript and ran 23 checks over it and the routing configuration, including
a source scan proven capable of finding a known string and of stripping comments
before reporting nothing.

**PostgreSQL, over the real Sanctum session flow** — 40/40. Read the profile,
edited name, phone, and language, and confirmed all seven forbidden fields and
four malformed locale codes answer 422. `/me` showed the new name and language
while permissions, scopes, and roles stayed byte-identical; the original password
still signed in; a planted unsupported `preferred_locale` was reported as-is and
**not** repaired by reading it; no `NEXT_LOCALE` cookie was ever set. Temporary
data removed; 171 canonical permissions preserved.

One fixture bug worth noting: the first run failed a union assertion because the
fixture gave a single role two scopes for one permission, which
`UNIQUE(role_id, permission_id)` correctly prevents — the union is *across* roles
(D-028, D-053). The fixture was wrong, not the product.

### Also recorded

**O-030** — self-service email change has no defined flow and is deferred to
M1.9, which owns the neighbouring questions. Until then an address is corrected
by an administrator through User Management, which is deliberate and attributable.

**O-028** and **O-029** remain open, untouched.

---

## 2026-08-10 — M1.6 Permission Matrix & deployment bootstrap

Branch `feat/m1-identity`. Authorization configuration becomes editable, and a fresh
deployment becomes provisionable. **No migration** — every table M1.6 needs already
existed.

### Configuring authorization

```text
GET  /api/v1/permissions                  permissions.view   + ALL
GET  /api/v1/roles/{role}/permissions     permissions.view   + ALL
PUT  /api/v1/roles/{role}/permissions     permissions.assign + ALL
GET  /api/v1/users/{user}/roles           permissions.view   + ALL
PUT  /api/v1/users/{user}/roles           permissions.assign + ALL
```

A grant and its Data Scope are written, re-scoped, and removed **together** (D-053). The
resolver ignores a grant without scope metadata (D-039), so a half-applied save would
produce a role that looks configured everywhere and does nothing — the write path cannot
create one, and tests assert grants and scope rows stay equal across every kind of edit.

Saves are complete replacements. Rejected and tested: non-canonical permissions, stale
rows the sync preserved, other-guard permissions, duplicate codes, and any scope the
permission does not allow. **`TEAM` is never assignable** — the catalogue never offers it,
the endpoint rejects it, and a legacy `TEAM` row is reported as-is rather than
reinterpreted as `OFFICE` (D-042).

Role assignment is guarded by `permissions.assign`, **not `users.update`** (D-055):
granting a role changes what somebody can do, and a test gives a user every `users.*`
permission at `ALL` to confirm the role endpoints still refuse them.

### The lockout invariant

M1.6 makes the configuration editable, which means it can be edited into a state nobody
can edit back. Every mutation of role permissions, role membership, or activation now runs
inside a transaction that ends by asking whether an **active, non-deleted** user still
resolves `permissions.assign` at `ALL`. If not: rollback and **409** (D-056).

Capability-based, never name-based — a custom role satisfies it, the `SUPER_ADMIN` name
alone does not. Disabled and soft-deleted users do not count. Losing your own access is
allowed while somebody else keeps theirs. The precise rule is that *this operation must not
be what causes the loss*, so an unprovisioned deployment is not made inexplicably
read-only.

This also hardened M1.5's activation: disabling the last administrator is the same lockout
by another route, and is now refused too.

### Bootstrap

`php artisan app:bootstrap` — interactive, one-time, transactional. Creates one
Organization, one Office, the canonical permissions, the nine default roles, and the first
administrator, whose capability comes solely through a role.

`SUPER_ADMIN` receives **every canonical permission explicitly, each at `ALL`** — no
wildcard, no `Gate::before`, no name check (D-057). The other eight roles are created
**empty**: the high-level matrix grades modules `F`/`V`/`A`/`—`, which cannot become 171
codes and scopes without inventing the mapping, and invented authorization is worse than
absent authorization.

No default password and no password option; the secret is typed at a hidden prompt and
never printed or stored in plaintext. Re-running changes nothing, and nothing
resynchronizes default roles — a deleted or renamed one stays that way (D-058).
`SyncCanonicalPermissions` was extracted from the M1.2 command so bootstrap reuses it
in-process; `permissions:sync` behaves exactly as before.

### Frontend

`/[locale]/settings/roles/[id]` — the matrix, reached from the roles list. Permission
codes are loaded from the backend catalogue; **none of the 171 appear in frontend source**.
A permission is off, or on with an explicitly chosen scope: nothing defaults to `ALL`,
because the widest reach should never be what you get by not deciding. Scope choices come
from the backend rules, so `TEAM` never appears and a global permission shows `ALL` alone.
`users.reset_password` is badged as not yet available (O-028). Malformed stored
configuration is surfaced rather than hidden.

Role membership moved onto the user list as its own dialog. No direct-permission control,
no per-user scope, no override editing. 62 new keys; id and en at exact parity (195 = 195).

### Verified

**Backend** 569 tests, 2026 assertions — 134 new. Pint clean. `migrate:status` unchanged
at 11.

**Real PostgreSQL**, on an isolated `notary_ppat_m16` database created and dropped for the
purpose: the bootstrap suite (24), matrix (68), continuity (19), and role assignment (23)
all pass against the real engine. The working development database was never used for
bootstrap testing.

**HTTP smoke over the real Sanctum session flow** — 35/35, as an administrator provisioned
exactly as bootstrap provisions one: catalogue of 171 with no `TEAM` and
`users.reset_password` flagged deferred, the administrator role holding all 171 at `ALL`, a
default role starting empty and then configured, `TEAM` / global-`OFFICE` / stale /
duplicate all rejected with 422, membership assigned and read back, both lockout attempts
refused with 409 and rolled back, a capability-less user refused everywhere, anonymous 401,
and direct-permission, override, and reset-password routes all 404. Temporary data removed;
171 canonical permissions preserved.

**Frontend** format, lint (0 errors), typecheck, and build all pass.

### Also recorded

**O-029** — `user_permission_overrides` still has no administrative surface, and no
milestone owns that work. Recorded rather than quietly assumed: a per-user exception
overrides the role result outright and expires, which needs an audit trail and a
considered design, not a checkbox added because the table exists.

**O-028** stays open (reset password, M1.9). **O-026** stays open (`/me` permission
presentation, M1.7) and M1.6 depends on none of it — every endpoint authorizes through the
resolver.

---

## 2026-08-09 — M1.5 User domain & User Management

Branch `feat/m1-identity`. User **records** only — no role assignment, no permission
matrix, no password reset, no session management, no profile self-service, no
permission-aware navigation.

### Schema

One migration completing `users` against `03_DATABASE_ERD.md` section 4: `phone`
(nullable, unformatted — no country prefix imposed) and `deleted_at`. Nothing removed;
`email_verified_at` stays per D-031. No `organization_id`, `role_id`, `tenant_id`, or
`team_id`, and no membership pivot — one primary Office per user (D-027).

### Users are retired, not deleted

`User` gained `SoftDeletes` and the table gained `deleted_at`, but there is **no deletion
of any kind** — the registry defines no `users.delete`, so no endpoint exists and `DELETE`
answers 405 (D-050). Accounts are turned off with `users.disable`. The column is
foundation: a person's account is referenced by the Minuta Akta they prepared and the
audit trail they appear in, so the record must outlive their employment.

This lowered the practical risk in **O-025** — with no deletion path, the product cannot
orphan Spatie's foreign-key-less morph pivots. The item stays open because the package
behaviour is unchanged.

### Authorization

A User is Office-owned, so unlike a Role definition all three record predicates work
(D-049): `ALL` reaches everyone, `OFFICE` matches `target.office_id`, `OWN` matches the
actor. `ASSIGNED` and `TEAM` reach nobody. `OWN` is deliberately **not** an administrative
predicate — it grants sight of yourself, never the right to edit your own administrative
record, which is M1.8's subject.

`UserVisibility` turns scopes into a SQL constraint, and **the record check runs that same
constraint against a single key** rather than reimplementing it — so a user hidden from
the list can never remain fetchable by id. Filtering happens in the query, so an
office-scoped caller's SQL never selects another Office's rows and the pagination total
leaks no count. A `?office_id=` filter narrows what is visible and cannot widen it.

Every decision goes through `UserPolicy` → `EffectiveAccessResolver` (D-048). No raw
`can()`, no role names, no `Gate::before`.

### API

```text
GET    /api/v1/users              users.view      list, search, paginate
POST   /api/v1/users              users.create    + destination Office authorized
GET    /api/v1/users/{user}       users.view
PATCH  /api/v1/users/{user}       users.update    + destination Office if moving
POST   /api/v1/users/{user}/disable   users.disable
POST   /api/v1/users/{user}/enable    users.disable
GET    /api/v1/users/options      users.create OR users.update — form metadata
```

Administrative update owns four fields: name, email, phone, Office. Password,
`preferred_locale`, `is_active`, `email_verified_at`, `last_login_at`, roles, and
permissions are all discarded rather than ignored, since `validated()` returns only the
declared rules.

Activation is separate so that turning off somebody's access is never a side effect of
editing their phone number (D-052), and **self-disable is refused with 409** at every
scope including `ALL` — the actor is authorized, so 403 would be a lie; what blocks it is
that it ends their own access and may leave nobody able to undo it.

Creation accepts an initial password, hashed and never returned, validated with Laravel's
own `Password::default()` (D-051). A new account holds zero roles, zero direct
permissions, and zero overrides.

**`users.reset_password` stays registered and unimplemented** — the capability is
canonical, the flow is not. Recorded as **O-028** for M1.9.

### Frontend

`/[locale]/settings/users`, reached by direct URL; the sidebar is unchanged. List with
debounced search and pagination, create and edit dialogs, and activation confirmations,
with loading, empty, no-matches, error, and forbidden states. Status is never carried by
colour alone. The Office selector is filled from the scope-filtered options endpoint, so
an office-scoped administrator simply sees one option — a convenience, not the control.

No role selector, no permission selector, no scope selector, no reset-password button, no
locale editing. 68 new `users.*` keys; id and en at exact parity (137 = 137).

### Verified

**Backend** 434 tests, 1346 assertions, all passing — 97 new, every earlier test still
green. Pint clean. `migrate:status` 11 migrations, nothing pending.

Three M1.3 tests changed meaning and were **rewritten rather than deleted**: `User::delete()`
is now a soft delete, so the override cascade and the `created_by` restriction are asserted
against `forceDelete()`, which is what those foreign keys actually govern, plus a new test
that a soft delete preserves the override. Both rollback probes now compute their step
count from the migration filenames instead of hardcoding it, so adding a migration no
longer silently makes them test the wrong thing.

**Frontend** format, lint (0 errors), typecheck, and build all pass. The single warning is
pre-existing in `login-form.tsx`.

**PostgreSQL, over the real Sanctum session flow** — 47/47 checks. An `ALL` administrator
listed across Offices, created in another Office, reassigned, hit 422 on a duplicate email,
was refused self-disable with 409, disabled a user and confirmed they could no longer log
in, then re-enabled and confirmed they could. An `OFFICE` administrator saw only their own
Office, was refused a cross-office detail, create, update, move, and disable, and was
offered exactly one Office. An `OWN` user saw only themselves and was refused an
administrative edit of their own record. A user holding all four permissions as direct
package grants was refused everything. `DELETE` answered 405; reset-password and role
routes answered 404. Users created through the API held zero roles. All temporary data
removed; 171 canonical permissions preserved.

---

## 2026-08-09 — M1.4A Authorization gate hardening

Branch `feat/m1-identity`. Resolves **O-027** before more endpoints are written. No
migration, no schema change, no frontend change, no new authorization engine.

### The hole

spatie/laravel-permission registers a `Gate::before` callback that answers **any ability
whose name matches a permission the user holds**, reading package state directly. So:

```text
$user->can('roles.view')        true — from a direct grant, no Data Scope, no
                                override consulted, no registry check
resolver->allowsGlobally(...)   false
```

Two answers to one question, and the idiomatic one was wrong. Nothing had exploited it —
`RolePolicy`'s abilities are named `viewAny`, `view`, `create`, `update`, `delete`
precisely so the callback could not answer them — but one `middleware('can:users.create')`
would have bypassed canonical-registry validation, Data Scope, DENY overrides, and the
exclusion of direct grants, all at once. `CLAUDE.md` section 24 was actively recommending
that form.

### The fix

`config('permission.register_permission_check_method')` is now `false` — the package's own
documented switch, whose stated purpose is "if you want to implement custom logic for
checking permissions". Verified against the installed 8.3.0 source rather than assumed:
`registerPermissions()` has exactly one caller, guarded by that flag, and nothing else in
the package depends on it. **No vendor file was modified.**

Roles, permissions, `role_has_permissions`, `model_has_roles`, `HasRoles`,
`hasPermissionTo()`, `givePermissionTo()`, and every relationship are untouched — none of
them route through the Gate. Laravel Policies work exactly as before, because a policy
ability is not a permission code.

### Documentation corrected

`CLAUDE.md` section 24 recommended `$user->can('ppat.matters.create')` as the preferred
backend check. It now shows that form as unsafe alongside the role-name check it always
warned about, and requires a Policy delegating to the resolver. `07_SECURITY_RULES.md`
section 9 gained the same boundary. Recorded as **D-048**.

### Verified

331 tests, 1085 assertions, all passing — 29 new plus 9 rewritten. Pint clean.
`migrate:status` unchanged at 10. No frontend diff.

The nine tests that asserted the old behaviour were **rewritten, not deleted**, each with
a note on why the expectation changed — including the M0.8 test whose comment had called
the Gate "what controllers and policies will use". The storage relationship it really
tested is still asserted; only the reading of it moved.

New enforcement: zero Gate before/after callbacks registered; a canonical permission name
refused by the Gate even for a user who genuinely holds it at `ALL`; and a source scan of
`app/` that fails the suite if any file authorizes a `resource.action` string through
`can()`, `Gate::allows()`, `hasPermissionTo()`, and friends. That scan was itself checked
against nine unsafe and six valid samples so it cannot pass vacuously.

Every M1.3 rule re-asserted through the new seam: `ALL` still required for global records,
`OFFICE`-only still refused, direct grants still ignored, active DENY still wins, active
ALLOW at `ALL` still allows and at `OFFICE` still refuses, expired overrides still ignored,
stale permissions still refused, `SUPER_ADMIN` still inert.

Confirmed on **PostgreSQL** over the real Sanctum session flow: an administrator holding
`roles.view` at `ALL` is allowed; the same permission at `OFFICE` only is refused; a direct
package grant is refused; and `can('roles.view')` answers false for all three. Temporary
data removed, 171 canonical permissions preserved.

### O-026 stays open

`/api/v1/me` still reports permissions via `getAllPermissions()`. That is a **presentation**
defect affecting menu visibility, not a backend authorization one — no security decision
reads it — and M1.7 owns it. M1.4A changed nothing there.

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
