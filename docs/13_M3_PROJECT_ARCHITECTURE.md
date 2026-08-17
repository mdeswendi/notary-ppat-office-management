# M3 — Project Architecture

**Status:** `LOCKED — M3.0`

Sibling of `12_M2_PARTY_ARCHITECTURE.md`. Where that document locked the Party aggregate,
this one locks Project. It records what M3 may build, what it must not, and — as importantly
— which of its statements are transcribed from canonical sources and which are engineering
decisions taken here.

Every ruling below was reviewed and accepted before this document was written. Nothing in it
is inference promoted to fact.

---

## 1. Scope

M3 implements **Project only**.

Matter is a separate persistence entity with its own lifecycle, and it belongs to **M4**
together with `matter_parties`, Notary Matter, PPAT Matter, the `notary.matters.*` and
`ppat.matters.*` implementations, and the Workflow Engine.

This resolves a conflict the M3.0 discovery found and did not silently choose between. The
milestone was proposed as "Project / Matter", while three canonical sources —
`00_PROJECT_OVERVIEW.md` section 19, `CLAUDE.md` section 2, and the `DECISIONS.md` milestone
register — all read **M3 — Project Management** and **M4 — Matter & Workflow Engine**. The
roadmap wins. M3.0 documents the Project → Matter *boundary* because an aggregate edge cannot
be defined without naming what attaches to it, but it creates no Matter anything.

See **D-087**.

---

## 2. Terminology

**Project** and **Matter** are genuinely separate persistence entities. Neither is a display
label for the other.

```text
Project    one client engagement, transaction, or larger legal requirement
Matter     the operational unit of work inside it            (M4)
```

`CLAUDE.md` section 15 states it directly: *"Do not merge Project and Matter into one database
entity."* `00_PROJECT_OVERVIEW.md` sections 5 and 6 define both, and
`03_DATABASE_ERD.md` sections 7 and 9 give each its own table. The discovery considered
collapsing them in either direction and found no canonical support for either reading.

---

## 3. Project aggregate

Project is the **M3 aggregate root**: the lifecycle authority and the ownership boundary for
everything M3 builds.

Matter is a **future child aggregate with its own lifecycle** — not a component of Project.
That distinction matters at the boundary: a child aggregate is referenced, not owned in the
cascade sense, and M4 decides its own archive and lifecycle rules rather than inheriting
Project's.

**M3 invents no Project-to-Matter cardinality.** Whether a Project must have at least one
Matter, may have none, or caps at some number is an M4 question with a domain component. M3
writes no such constraint, in the database or anywhere else.

---

## 4. Office ownership

`projects.office_id` is **required**, and **immutable for the duration of M3**.

M3 ships no Project Office-transfer operation: no endpoint, no Action, no administrative
path.

This is an **engineering boundary, not a claim of legal impossibility**. An office may well
have a legitimate reason to move a Project one day. What M3 refuses to do is invent the
semantics of that move — what happens to participants, to future Matters, to internal
references already issued — before anyone has specified them. Any future transfer requires
its own architecture decision.

The same reasoning M2 applied to Party (D-080), reached independently rather than inherited
by analogy.

See **D-089**.

---

## 5. Departures from `03_DATABASE_ERD.md` section 7 — each deliberate

M2 set the precedent that the ERD field lists are a canonical proposal, not a transcription
target, and that departures are recorded rather than silently made (`12_M2_PARTY_ARCHITECTURE.md`
section 5). M3 follows it.

| ERD field | M3 disposition | Why |
|---|---|---|
| `primary_client_party_id` | **Rejected** | Duplicate persistence. `project_parties` already carries participation, and the ERD gives it an `is_primary` flag. Two mechanisms for one fact drift apart, and the column-shaped one additionally re-creates the "client" concept D-078 refused. If primary designation is retained it lives on `project_parties`. See **D-092**. |
| `project_number` | **Withheld until M3.2** | The column and its allocator arrive together (**D-095**). See below. |
| `status` | Retained, vocabulary unchanged | Canonical in ERD section 7. Not a workflow stage and not a deletion state — section 10 below. **No database default**: the application decides an initial state, not the schema. |
| `priority` | Retained, nullable | Vocabulary transcribed from the one place the ERD defines it — see below. |
| `deleted_at` | Retained | Soft delete is the persistence-level state, distinct from business status. |
| everything else | **Built at M3.1** as ERD section 7 lists it | `office_id`, `title`, `description`, `pic_user_id`, `opened_at`, `target_completion_date`, `completed_at`, `created_by`, `updated_by`, timestamps. |

Recording a rejection here is the point: a future reader finding `primary_client_party_id` in
the ERD and not in the schema should find the reason in the same repository rather than
assume an oversight.

**`project_number` is withheld rather than added empty** *(M3.1, D-095)*. M3.2 owns
internal-reference allocation, and adding the column now would leave every M3.1 Project with a
null reference and hand M3.2 a backfill plus a uniqueness question it has not answered. This
follows D-086 exactly: M2.1 refused to add a fingerprint column before its construction was
settled, "because a column added on speculation is one somebody fills in wrongly."

**`priority`'s vocabulary comes from ERD section 23, not section 7** *(M3.1, D-095)*. The ERD
lists a `priority` column on `projects`, `matters`, and `tasks` and defines the values —
`LOW`, `NORMAL`, `HIGH`, `URGENT` — exactly once, under tasks. M3.1 reads that as one shared
vocabulary, since the document names the same column three times and gives one set of values,
and no competing vocabulary exists anywhere. That is a transcription with a named source, but
it is the one Project field whose values were not written beside the column they govern, so it
is flagged rather than left to be discovered. Nullable, so an office that does not use it is
not forced to.

---

## 6. Project Data Scope predicates

M2 left `projects.*` in `PermissionScopeRules`' permissive default with an explicit note that
narrowing it would mean *"deciding what `notary.deeds.approve` at `OWN` means before the
Notary domain has been designed."* M3 is where that decision becomes legitimate for Project,
and only for Project.

```text
OWN        project.created_by   == actor.id
ASSIGNED   project.pic_user_id  == actor.id
OFFICE     project.office_id    == actor.office_id
ALL        cross-office Project reach
TEAM       no Project-domain grant
```

Four things follow, and each is load-bearing:

**These are predicates, not a ladder.** `ALL` does not "outrank" `OFFICE`; it is an
independent condition that happens to subsume it. Multiple grants **union** their predicates
(D-028). Nothing ranks or collapses them.

**`OWN` is `created_by` here, and that is not a contradiction of M2.** M2 refused `OWN` for
Party on Party-specific reasoning — a Party is a shared directory record, and the colleague
who typed it in has no claim on the person it describes. A Project is not a shared reference
record; it is a unit of work somebody opened. The reasoning did not transfer, so the answer
did not either.

**`ASSIGNED` is the PIC and nothing else.** `pic_user_id`, one column, one comparison.

**Future Matter or stage assignment must never expand Project `ASSIGNED`.** When M4 adds
`matters.pic_user_id` and M4's workflow adds `matter_stage_instances.assigned_user_id`, it
will be tempting to let those widen a person's Project reach, on the reasoning that somebody
working a Matter must see its Project. That is a **new grant** wearing an existing scope's
name, and it would silently widen every role already configured with Project `ASSIGNED`. If
Matter workers need Project visibility, that is its own decision and its own predicate.

**Unknown or missing scope metadata fails closed** (D-039), unchanged.

See **D-088**.

---

## 7. Mutation boundaries

Three capabilities that a naive implementation would fold into one ordinary update, kept
apart deliberately.

```text
projects.update          ordinary attributes
projects.assign          project.pic_user_id, and nothing else
projects.change_status   project.status, and nothing else
```

**`projects.assign` means mutating `pic_user_id`.** Generic `projects.update` must not touch
it. Reassigning work is a different act from correcting a title, and the registry has always
had a separate code for it.

**Workflow and stage assignees are not Project assignment.** They do not exist yet, and when
they do they will not write `pic_user_id`.

**`projects.change_status` is separate from `projects.update`.** Generic update must not
mutate status. Status moves through a dedicated action and authorization boundary.

**M3 invents no transition matrix.** Which status may follow which is an operational rule
nobody has specified. M3 authorizes *who may change status*; it does not encode *which
changes are legal*. Inventing one here would be exactly the failure `CLAUDE.md` section 62
prohibits, one domain removed.

See **D-091**.

---

## 8. Party participation

`project_parties` is the **canonical and only** source of Project ↔ Party participation.

- **The role lives on the relationship**, never on the Party record (`CLAUDE.md` section 17,
  D-078). The same person is a client on one Project and a counterparty on another.
- **No raw Party sensitive identity is copied into any Project-domain table.** No NIK, no
  NPWP, no `tax_id`, no mask, no fingerprint. Project references a Party by id and reads
  identity, if it ever needs to, through the Party surfaces that already authorize it (D-082).
- **No Client persistence.** No `clients` table, no `client_id`, no denormalized Party copy
  (D-078).
- **No `primary_client_party_id`.** See section 5.

**M3 invents no participant semantics.** Not a mandatory primary client, not an
exactly-one-primary rule, not a catalogue of legal participant roles. `03_DATABASE_ERD.md`
offers *example* role codes and labels them as examples; a real catalogue, and any cardinality
rule attached to it, needs domain authority.

See **D-092**.

**Built at M3.4** *(D-098)*, which answered the questions building it raised:

```text
projects.parties.view     read the list          171 -> 173
projects.parties.manage   add, correct, remove
```

Two dedicated capabilities, neither implying the other, both judged against the **parent
Project** by the four D-088 predicates. `projects.update` reaches neither, and there is no
`projects.parties.view_all`.

The same-Office invariant is **structural**: composite foreign keys through one `office_id`
carrier make a cross-office participation unrepresentable, and `projects` gained the
`UNIQUE (id, office_id)` support key the pattern requires. `ALL` reaches a Project in another
Office and links a Party from *that* Office; it never bridges two.

**Managing participation is not authority to discover Parties.** A candidate must also be
reachable under `parties.view` or `companies.view` — the two branches evaluated independently —
and a submitted `party_id` is re-resolved through that authorized query rather than trusted.

**Participation is current working state, not history.** No `updated_at`, no `deleted_at`, no
period columns; removing a participation deletes the row and leaves both records untouched.
`company_people` keeps history because deeds depend on it (D-083); nothing yet depends on a
Project's participant list as it stood last week, and the mechanism is not built ahead of the
requirement.

`role_code` stays nullable and opaque, `is_primary` stays a designation with no cardinality, and
a Project with no participants is complete rather than a draft — M3.3's create surface was not
changed.

---

## 9. Internal reference

A Project's internal reference is **ordinary office identification**. Its shape follows
`CLAUDE.md` section 38's internal-reference examples.

It is explicitly **not**:

```text
a deed number
a repertorium number
a land or government registration number
any legally significant document number
```

`CLAUDE.md` section 38 already separates the two concepts; this restates it for Project so no
future reader treats `PRJ-2026-000001` as carrying legal weight.

**No `MAX(number) + 1` allocator**, which is unsafe under concurrency.

See **D-094**.

### Delivered at M3.2

```text
PRJ-2026-000001        format, minimum six digits, grows rather than wrapping
(office_id, year)      allocation namespace — each Office an independent annual sequence
UNIQUE (office_id, project_number)   never global; two Offices may both hold PRJ-2026-000001
```

**One atomic statement, no read-then-write.** `INSERT … ON CONFLICT (office_id,
reference_year) DO UPDATE SET last_value = last_value + 1 RETURNING last_value`. The increment
happens inside the database against a row the engine locks for the duration of the upsert, so
two concurrent callers cannot compute the same value — neither computes it at all. A
transaction alone would **not** be enough: under `READ COMMITTED`, two transactions can both
read the same value before either writes.

The identical statement runs on PostgreSQL and on SQLite 3.35+, verified on both before the
allocator was written, so there is **one execution path and no semantic divergence** between
the test engine and the production engine. PostgreSQL remains authoritative, and the
contention evidence is taken there: 16 simultaneous OS processes, 400 allocations across two
Offices, every value distinct, both counters landing exactly on their expected totals.

**The year comes from the application clock**, never from a browser, a request locale, user
input, or by parsing an existing reference. It is stored explicitly on the counter row, and
tests freeze time so rollover is proven rather than hoped for.

**Gaps are expected and carry no meaning.** An allocation that is handed out and then not used
leaves a gap by design; the alternatives are reusing references or serializing every create
behind one lock. The sequence is **not a record count**, and its order carries no legal weight.
A reference that belongs to a persisted Project is spent: archiving does not release it,
restoring keeps the same one, and no reuse feature exists.

**`project_number` was nullable at M3.2** — a design choice, not a data-forced one. The
persistent development table was verified empty first. M3.2 ships no creation path that
assigns a reference (that is M3.3), so `NOT NULL` would have made Project unwritable for a
whole milestone. Under the composite unique index, multiple unassigned Projects per Office were
fine because both engines treat NULLs as distinct.

**`NOT NULL` since M3.3** *(D-097)*, by forward migration
`2026_08_16_180000_require_project_internal_reference`. M3.3 owns the only path that creates a
Project and that path always allocates, so the column now carries the guarantee the domain
always intended. The precondition was checked rather than assumed: the persistent development
database held zero Projects and zero null references, and **no backfill was invented** — a
deployment holding null references must resolve them deliberately.

**Immutable once allocated.** The model refuses a rewrite. The `null → reference` branch the
guard once needed is now unreachable, since the column can no longer be null.
The counter table is Project-specific and is deliberately **not** generalized into
`legal_number_sequences` or anything deed-, repertorium-, or Matter-shaped — that would pull
numbering nobody has validated into a milestone that owns none of it.

---

## 10. Lifecycle: three separate concerns

`CLAUDE.md` section 18 and `08_NOTARY_WORKFLOW.md` section 4 both insist these not be merged.
Project keeps them apart:

```text
Business status      the canonical ERD section 7 vocabulary
Workflow stage       does not exist in M3 — M4 owns it
Persistence state    deleted_at, soft delete
```

**Archive is the awkward one, and the awkwardness is named rather than smoothed over.** The
business vocabulary contains `ARCHIVED`, and the table separately carries `deleted_at`. They
are **different states with unfortunately similar names**.

`projects.restore` is retained, and its meaning is narrow:

> **restore a soft-deleted Project persistence record.**

It does **not** mean: change business status `ARCHIVED` back to `OPEN`; reverse a workflow;
undo a completion; or undo any legal event.

**Party gains no restore for symmetry.** M2 refused to invent one because no restore
permission existed for it; Project has `projects.restore` in the canonical registry and Party
does not. The registry is the reason, and it applies to one and not the other.

See **D-093**.

---

## 11. Permission surface

**M3.0 added no permission, and the canonical count stayed at 171 through M3.3.** All eight
`projects.*` lifecycle codes were already canonical (`02_MENU_AND_PERMISSIONS.md` section 7).

```text
projects.view
projects.view_all      superseded for reach — see below
projects.create
projects.update
projects.assign
projects.change_status
projects.archive
projects.restore
```

**M3.4 took the count to 173** *(D-098)*, adding the two participation capabilities recorded in
section 8 — `projects.parties.view` and `projects.parties.manage`, the first codes any milestone
has added since the catalogue was transcribed. That addition was deliberate and reviewed, not a
drift from the sentence above; this section stated the M3.0 position and is corrected at M3.5 to
say which part of it expired and when.

### `view_all` is superseded by Data Scope `ALL`

`projects.view_all`, `notary.matters.view_all`, `ppat.matters.view_all`, `tasks.view_all` and
`calendar.view_all` predate the Data Scope model. They express reach, which is exactly what a
Data Scope expresses, and `CLAUDE.md` section 26 warns against duplicating a permission per
scope.

The ruling:

- The codes **remain registered**, for compatibility and documentation history. **M3.0 removes
  nothing**, and this ruling changed no count.
- For **reach semantics they are superseded by Data Scope `ALL`**.
- **No `view_all` code may be used as backend cross-office authorization authority.** Not
  `projects.view_all`, not the Matter or Task or Calendar equivalents.
- **No second reach mechanism may exist alongside `EffectiveAccessResolver`.** One resolver
  answers reach, or two answers eventually disagree and the looser one wins by accident.

This is a supersession, not a deletion, and it is recorded rather than silently ignored.

See **D-090**.

### Authorization shape, unchanged

Inherited from M1 and M2 without modification: `Controller::authorize` → Policy →
`EffectiveAccessResolver` → Data Scope. No permission-code authorization as backend authority,
no role-name authorization, no `SUPER_ADMIN` bypass (D-048, D-032, D-041).

`PermissionScopeRules` gains a Project-domain entry replacing the permissive default — in
**M3.1**, which owns the code. M3.0 edits no registry and no rules class.

---

## 12. The Project → Matter boundary (documented, not built)

Recorded so M4 inherits a boundary rather than inventing one:

- Matter references Project. Project does not embed Matter.
- Matter is a **child aggregate with its own lifecycle**, not a component.
- Cardinality is **undecided** and is M4's to decide.
- The Matter capability surface is **already split by domain** in the canonical registry —
  `notary.matters.*` and `ppat.matters.*`, with no generic `matters.*` — and the MVP sidebar
  (`02_MENU_AND_PERMISSIONS.md` section 26) splits the navigation the same way while listing
  `Projects` as a single top-level entry.
- That split is the main reason M4 deserves its own architecture lock: a single `matters`
  table discriminated by `domain` would mean the Matter Policy selects its permission
  namespace from row data, which is a genuinely new authorization shape in this codebase.

M3 builds none of it.

---

## 13. Non-goals

Not part of the M3 foundation, and not to be introduced incidentally:

```text
documents / uploads                 property and land records
deed generation                     legal numbering of any kind
invoices / payments / billing       tasks
calendar                            notifications
global search                       persistent audit-log product
Party merge                         cross-office Party consolidation
workflow engine                     service_types master data
Matter, matter_parties              Notary / PPAT deed, Minuta, Warkah,
                                    register and report surfaces
```

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` remain placeholders requiring domain
authority. **Nothing may be implemented from them** — not service type rules, stage sequences,
approvals, deed numbering, Warkah composition, registers, reporting, or any Notary/PPAT
workflow semantics.

---

## 14. Milestone decomposition

```text
M3.0   Project architecture lock                  <- this document
M3.1   Project schema + authorization foundation
M3.2   Project internal reference foundation
M3.3   Project core management
M3.4   Project <-> Party participation
M3.5   M3 quality gate
```

**M3.1 — delivered.** The `projects` table (one forward migration, 19 total), the `Project`
model, `ProjectStatus` and `ProjectPriority`, `ProjectVisibility`, `ProjectPolicy`, and the
`PermissionScopeRules` Project entry replacing the permissive default. **No CRUD UI, no route,
no permission** — the count stays at 171 — following the M2.1 precedent exactly.

Enforcement landed where it actually holds, which is not always the database:

```text
Project cannot exist without its Office      DATABASE   NOT NULL + FK RESTRICT
PIC and actor FKs are real users             DATABASE   FK RESTRICT
Only canonical status / priority codes       DATABASE   CHECK  (PostgreSQL)
                                             MODEL      enum cast (SQLite test connection)
Office is immutable during M3                MODEL      updating() guard — an update rule,
                                                        which a column cannot express
Assign / change-status are not update        MODEL      the fields are not fillable
                                             POLICY     separate abilities, separate codes
Authorization reach                          POLICY     resolver + ProjectVisibility, never
                                                        the database
```

One M2-era guard was **narrowed rather than deleted**: `PartySchemaTest`'s "introduces no M3
relation" asserted `projects` did not exist, which M3.1 intentionally makes false. The
assertion that expired was removed and every other one kept, including the point the test was
always really about — Party gains no foreign key into Project. The route-level guards needed no
change at all, because M3.1 ships no route.

**M3.3 — delivered.** The Project product surface: create, list, detail, ordinary update, PIC
assignment, status change, archive, restore, and the archived surface, plus the frontend. One
forward migration tightening `project_number` to `NOT NULL` (21 total). **No new permission —
the count stays at 171**; every capability was already canonical.

```text
GET    /api/v1/projects                       projects.view
POST   /api/v1/projects                       projects.create      — own Office always
GET    /api/v1/projects/archived              projects.restore     — before the {id} binding
GET    /api/v1/projects/{id}                  projects.view
PATCH  /api/v1/projects/{id}                  projects.update      — ordinary attributes only
PATCH  /api/v1/projects/{id}/assignment       projects.assign      — pic_user_id only
GET    /api/v1/projects/{id}/assignment/options
PATCH  /api/v1/projects/{id}/status           projects.change_status — status only
DELETE /api/v1/projects/{id}                  projects.archive     — soft delete
POST   /api/v1/projects/{id}/restore          projects.restore
```

The literal `archived` path is registered **before** the `{id}` binding, and a test pins that
ordering — reversed, the archived surface would be read as a Project id and answer 404.

What the system decides at creation, why `ASSIGNED` alone cannot authorize it, why a PIC must
be same-Office, and why a refusal keys on presence rather than emptiness are all recorded in
**D-097**. The two M2-era route guards this milestone invalidated were narrowed, not deleted,
exactly as M3.1 predicted: they now assert that no surface appears beyond the milestone that
owns it, and that Project is never a Party sub-resource.

**M3.4 — delivered.** `project_parties` and the five nested routes that maintain it, one forward
migration (22 total), and the **two permissions that took the canonical count to 173**. The
design and every rule it declined to invent are in section 8 and **D-098**.

**M3.5 — delivered.** The M3 quality gate. **No migration** (22) and **no permission** (173); no
new product capability, by design. Its findings, and what it deliberately left open, are in
`CHANGELOG.md`.

**M4.0** opens Matter with its own architecture lock.

---

## 15. Unresolved items

| Question | Status | Blocks M3.1? |
|---|---|---|
| Project internal reference allocation and concurrency design | **Resolved at M3.2** — atomic upsert-returning over an `(office_id, reference_year)` counter, uniqueness scoped to the Office, proven under real multi-process contention. See section 9. | **No** |
| Whether `project_number` should become `NOT NULL` | **Resolved at M3.3** — tightened by forward migration once creation always allocates. Precondition verified (0 Projects, 0 null references); no backfill invented. See D-097. | **No** |
| Participant role catalogue and any cardinality rule | Requires domain authority; ERD codes are examples only | **No** |
| Status transition matrix | Not invented; M3 authorizes who may change status, not which changes are legal | **No** |
| Project Office transfer | Refused for M3; requires its own architecture decision | **No** |
| Project-to-Matter cardinality | M4 | **No** |
| Whether Matter workers need Project visibility | A new predicate if ever needed; must not silently widen `ASSIGNED` | **No** |

None blocks M3.1. Each is recorded rather than quietly assumed.

---

**Status:** `LOCKED — M3.0`
