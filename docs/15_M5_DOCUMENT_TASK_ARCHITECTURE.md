# M5 — Document & Task Architecture

**Status:** `LOCKED — M5.0, amended at M5.1 and M5.2`

Amendments are marked in place and never silently overwrite what the lock said. M5.1 (D-116) closed
the two questions section 14 assigned to it — `is_current` and `document_number` nullability — and
corrected the decomposition in section 13, which had put the junction tables one milestone too late.

**M5.2 (D-117) supersedes one of this lock's own rulings.** Section 10.2 said M5 would encode *no
transition matrix*; M5.2 encodes one, by decision rather than by drift, and section 10.2 records both
the original position and why it was reversed. It also moved the document frontend forward from M5.5.

Sibling of `12_M2_PARTY_ARCHITECTURE.md`, `13_M3_PROJECT_ARCHITECTURE.md` and
`14_M4_MATTER_ARCHITECTURE.md`. Where those locked the Party, Project and Matter aggregates, this
one locks Document Management and Tasks. It records what M5 may build, what it must not, and — as
importantly — which of its statements are transcribed from canonical sources, which are engineering
decisions taken here, and which remain questions nobody has the authority to answer yet.

Every ruling below was reviewed and accepted before this document was written. Nothing in it is
inference promoted to fact.

---

## 1. Scope

M5 implements **Document Management and Tasks**.

M5 does **not** implement any Notary or PPAT legal output. `01_ARCHITECTURE.md` section 28 places
**M6 — Notary** and **M7 — PPAT** after this milestone, so Notarial Deeds, Drafts, Minuta Akta,
Repertorium, PPAT Deeds, Warkah, properties, taxes, registers and reports are outside M5 entirely.

The two sentences this document exists to hold:

> **A legal document is reachable only by streaming it from a surface that authorized the actor
> against the record first. Never by URL.**

> **A document version is written once and never overwritten.**

See sections 5 and 6, and **D-114**, **D-115**.

---

## 2. Terminology

```text
Document          the office's record of a document: what it is, whose it is, its state
Document Version  one uploaded file, immutable, one of several a Document may have
Task              a unit of work somebody is asked to do, optionally about a Project or Matter
```

**A Document is not a file.** The Document carries identity, classification and state; the file
lives on a Version. That separation is what makes "never overwrite" expressible: correcting a
document adds a version, and the old bytes stay exactly where they were.

---

## 3. What already exists, and what M5 inherits

**All seventeen permissions are already canonical**, registered since the catalogue was transcribed
and unimplemented ever since:

```text
documents.view            documents.sensitive.view
documents.upload          documents.download        documents.sensitive.download
documents.update          documents.verify          documents.archive
documents.delete

tasks.view                tasks.view_all            tasks.create
tasks.update              tasks.assign              tasks.complete
tasks.reopen              tasks.delete
```

**M5 therefore registers no permission. The canonical count stays at 177.** Any milestone that finds
itself wanting an eighteenth document code should re-read this line first: the surface was
catalogued before it was built, and adding to it is a decision, not a detail.

**`tasks.view_all` is superseded, exactly as `projects.view_all` and `*.matters.view_all` are**
(D-090). Reach is Data Scope `ALL`. No Policy ability may consult it, and it must not become a
second reach mechanism.

**Storage already exists and is already private.** The `local` disk roots at
`storage_path('app/private')`. M5 adds no disk.

---

## 4. Canonical ordering, transcribed

`03_DATABASE_ERD.md` section 32 is the only explicit ordering statement in the canonical documents,
and it is unusually specific here:

```text
6.  documents and document relations
7.  tasks, calendar, activity, audit
8.  properties, ownership, PPAT matter extensions
9.  Notary deeds and Minuta
10. PPAT deeds and Warkah
```

Two things follow, and neither is an invention of this document:

- **Documents precede Tasks.** M5's internal order is not a preference.
- **Audit sits in the same batch as Tasks**, not somewhere distant. D-033 kept it out of M1 on
  exactly this ordering. Section 8 returns to it.

That section also closes with *"Do not create all future tables prematurely if the milestone does
not require them"*, which is the rule section 7 applies.

---

## 5. Private storage, and the route that had to go

### 5.1 What was found

The `local` disk shipped with `'serve' => true`. That registers two routes into the very directory
M5 will fill:

```text
GET  /storage/{path}   storage.local
PUT  /storage/{path}   storage.local.upload
```

The GET is not open — `ServeFile` aborts without a valid relative signature when the disk's
visibility is private, which it is. **But a signed URL is a transferable bearer token that bypasses
the authorization chain entirely**: no Policy, no `EffectiveAccessResolver`, no Data Scope, and no
distinction between `documents.download` and `documents.sensitive.download`. Anyone holding the
string holds the file.

For KTP, NPWP, Minuta Akta and Warkah that is precisely what `CLAUDE.md` section 21 forbids —
*"authorization protected"*, *"unavailable through predictable public URLs"* — and section 54's
*"never expose private document URLs"*.

### 5.2 The ruling

**`serve` is now `false`, changed at M5.0 before any document exists to reach through it** (D-114).
Both routes are gone; the application's own 127 routes are untouched.

**No document surface may issue a signed URL, a temporary URL, or any other URL that resolves to
storage.** Downloads stream from a controller that has authorized the actor against the Document
record. This is the M5 equivalent of D-048's rule for permissions: there is one authorization path,
and a second one that happens to work is the problem rather than a convenience.

**`public` disk is never used for a legal document.** No `public/uploads`, no symlinked path, no
exception for "just a preview".

### 5.3 Storage layout

```text
documents/{office_id}/{YYYY}/{MM}/{stored_filename}
```

`stored_filename` is generated, never the uploaded name. The original is kept in metadata
(`original_filename`) so a download can restore it, and is **never** used as a path component:
user-supplied filenames carry traversal sequences, case collisions and characters no filesystem
agrees about.

`office_id` leads the path so a misconfigured backup or an operator listing a directory sees the
Office boundary the database enforces.

---

## 6. Versioning

Transcribed from `CLAUDE.md` sections 19 and 20, and `03_DATABASE_ERD.md` section 13:

```text
documents          identity, classification, state
document_versions  storage_disk, storage_path, original_filename, stored_filename,
                   mime_type, file_size, checksum_sha256, uploaded_by, uploaded_at,
                   version_number, is_current
```

**A version is written once.** Correcting a document uploads a new version; the previous file is not
replaced, moved, or deleted. `03_DATABASE_ERD.md` section 13 states it in three words — *"Never
overwrite an existing version"* — and it is the reason the file lives on the version rather than on
the document.

**`checksum_sha256` is `char(64)` and is computed at upload**, from the bytes actually written. It
exists so a later reader can prove the file is the one that was uploaded, which is only meaningful
if nothing ever rewrites it.

**`version_number` is unique per document.** Allocation is atomic, following D-103's rule for Matter
references: no `MAX+1`, no `COUNT+1`, no read-then-write.

### 6.1 The `is_current` question — resolved at M5.1

Exactly one version should be current. The obvious expression is a partial unique index —
`UNIQUE (document_id, is_current) WHERE is_current` — and **that is the shape M4.6 already refused
once** (D-111): partial indexes do not exist on the SQLite connection the test suite runs on, so
PostgreSQL and SQLite would disagree about what is representable, and a rule the tests cannot see is
a rule that decays.

M5.0 did not settle it. What M5.0 settled is that the milestone which builds the table must choose
deliberately between a partial index, an application invariant with a test, or a nullable
`current_version_id` on `documents`, and must say which and why.

**M5.1 chose the pointer** (D-116). `is_current` does not exist; `documents.current_version_id` is a
nullable ULID, and "at most one current version" is structural rather than a rule something has to
maintain.

**A bare pointer would not have been enough**, and that is the part worth recording: it could have
named a version belonging to some *other* document, and nothing would have objected. So
`document_versions` carries a support key `UNIQUE (document_id, id)` — redundant for uniqueness,
required for a composite foreign key — and `documents` declares

```text
documents (id, current_version_id)  ->  document_versions (document_id, id)
```

the construction `company_people` (D-080), `project_parties` (D-098), `matters` (D-107),
`matter_parties` (D-105) and `workflow_templates` (D-111) all use for the same-Office invariant,
applied here to a same-Document one. A cross-document pointer is unrepresentable rather than merely
wrong.

**The key arrives by `ALTER` in its own migration**, because the two tables reference each other and
neither can declare its key inline. SQLite cannot add a foreign key to an existing table, so that
migration is PostgreSQL-only and a model guard holds the identical rule on the test connection —
both are covered by tests.

`current_version_id` is nullable **permanently**, not pending: a Document legitimately exists before
its first file lands, which is the ordinary state between creating the row and writing the version.

---

## 7. Document relations: three of seven

`03_DATABASE_ERD.md` section 14 recommends seven junction tables. **Four of them reference tables
that do not exist**, and a foreign key cannot point at a table that is not there — the reasoning the
M4 lock used for "M4.1 precedes M4.2".

| Junction | Target | M5 |
|---|---|---|
| `party_documents` | `parties` | **buildable** |
| `project_documents` | `projects` | **buildable** |
| `matter_documents` | `matters` | **buildable** |
| `property_documents` | `properties` | blocked — batch 8, M7 |
| `notary_deed_documents` | `notary_deeds` | blocked — batch 9, M6 |
| `ppat_deed_documents` | `ppat_deeds` | blocked — batch 10, M7 |
| `matter_requirement_documents` | `matter_requirements` | blocked — see section 9 |

**M5 builds the three that are buildable and stubs none of the rest.** They are not created empty,
not created without their foreign key, and not replaced by a polymorphic column. Section 14 is
explicit on the last point: *"Prefer explicit junction tables over overly generic polymorphic
relationships where strong referential integrity is important."*

> **Re-confirmed at M5.3** *(D-118)*, because the milestone brief asked for the three blocked
> junctions to be created. They cannot be: verified against the schema rather than assumed —
> 31 `Schema::create` calls across all 35 migrations, and none of them is `properties`,
> `notary_deeds` or `ppat_deeds`. Those migrations would fail on PostgreSQL.
>
> The four are **named as blocked** in `App\Domains\Document\Enums\DocumentRelationType` rather than
> omitted, so adding one later is adding a case and a migration rather than redesigning. A request
> naming one gets a field error on `entity_type`, and a test asserts each junction table is still
> absent.

**Every junction foreign key is `RESTRICT`.** Deleting a Party must never take a document with it,
and a document must never become unreachable because something it was attached to went away.

---

## 8. Audit: required, absent, and not improvised

`CLAUDE.md` section 21 requires sensitive files to be *"audited where appropriate"*. **No audit store
exists.** `audit_logs` has never been built, D-033 kept it out of M1 on the batch-7 ordering, and
`audit.view` / `audit.export` are registered and unimplemented.

**M5.0 records this as a gap rather than filling it.** Three things are ruled here:

1. **No half-measure ships.** Writing download events to the application log is not audit in the
   sense section 31 means: a log file is not append-only in that sense, is not queryable by
   resource, and is the kind of stopgap that quietly becomes permanent.
2. **No sensitive-download surface lands before the question is answered.** The capability to read a
   KTP scan and the record of who read it belong in the same milestone; shipping the first without
   the second creates exactly the untraceable access section 21 exists to prevent.
3. **When it is built it follows section 31 exactly**: append-only, never updated or deleted from
   the application, with actor, event, resource, old and new values, IP address, user agent and
   reason — and **it never logs the document's contents, nor the identifier the document is about**.
   D-105's rule that free-text fields are a leak surface applies with more force here, not less.

Whether audit lands as its own M5 slice or as a prerequisite milestone is a scoped decision, not
something the document surface may assume its way past.

---

## 9. Requirements and workflow gating: deferred, and doubly so

`matter_requirements` carries `required_before_stage_code` — a document requirement that gates a
workflow stage transition.

**It is not built in M5, and the reason is stronger than a preference.**

- **D-104 forbids it.** M4 built a workflow mechanism with no content precisely because the two
  workflow documents remain `DRAFT — DOMAIN VALIDATION REQUIRED`. A gating rule is workflow content
  of the most consequential kind: it decides when work may proceed.
- **The domains differ.** What an AJB requires before signing and what an Akta Perubahan requires
  are different lists, authored by different authorities, and neither has been authored here.
- **The table it points at does not exist.** `matter_requirements.requirement_template_id`
  references `service_document_requirements`, a batch-4 table M4 did not build. So even a
  schema-only stub would need two tables invented ahead of their content.

**M5 builds neither table.** Not empty, not with the column present-but-unused, not as a nullable
placeholder — the D-095 rule that a column added before its semantics are settled is one somebody
fills in wrongly.

M4.7 already established the shape this will take when it arrives: **the mechanism may exist without
the content**, and the content becomes configuration an office enters once a qualified domain source
completes the workflow documents.

---

## 10. Documents

### 10.1 Field list, transcribed

`03_DATABASE_ERD.md` section 13:

```text
id  office_id  document_number  document_type_code  title  status  is_sensitive
document_date  expiry_date  notes
created_by  created_at  updated_by  updated_at  archived_at  archived_by  deleted_at
```

Status, transcribed and complete — no eighth value may be added:

```text
DRAFT  RECEIVED  UNDER_REVIEW  VERIFIED  FINAL  ARCHIVED  VOID
```

### 10.2 What M5 decides about them

**`document_type_code` is opaque and nullable**, following `role_code` (D-105) and D-111. No
canonical document defines a document-type catalogue; `KTP`, `NPWP`, `AKTA` and `SERTIPIKAT` are
examples in prose, not a validated list. **No enum, no `Rule::in`, no `CHECK`, and no dropdown built
from an invented list.** A `document_types` master table is a real design with a real owner and is
not M5's to invent.

**`is_sensitive` is set by whoever uploads and is not inferred.** Deriving it from
`document_type_code` would mean encoding which document kinds are sensitive — a judgement that
varies by office and is exactly the kind of rule section 62 forbids inventing. Default `false`, and
the interface must make the choice visible rather than quiet.

**No transition matrix.** M5 authorizes *who* may verify or archive a document and never encodes
*which* status may follow which. `VOID` and `FINAL` are canonical vocabulary; whether M5 can reach
them is a milestone question, and any status the product cannot set must be recorded as unreachable
rather than quietly implied — the D-109 precedent.

> **Superseded at M5.2** *(D-117)*. The ruling above is kept verbatim rather than rewritten, because
> what it got right still holds: the *legal* question of what a deed or a Warkah may become stays
> uninvented, and M6 and M7 remain untouched by anything here.
>
> What it got wrong is that the guards M5.2 requires cannot be expressed without a matrix. Three
> rules are now encoded on `DocumentStatus`:
>
> ```text
> upload   ->  RECEIVED
> verify   RECEIVED, UNDER_REVIEW   ->  VERIFIED
> archive  VERIFIED, FINAL          ->  ARCHIVED
> delete   DRAFT, RECEIVED          ->  (soft deleted)
> ```
>
> They are **operational, not legal**: an office may not verify the same document twice, may not
> archive what was never verified, and may not delete what somebody has already verified. That last
> one is the point — `02_MENU_AND_PERMISSIONS.md` section 13 requires `documents.delete` be *"heavily
> restricted"*, and "only before verification" is the restriction.
>
> **Upload creates `RECEIVED`, not `DRAFT`.** M5.1 created `DRAFT`; had that continued, verification
> would have been permanently unreachable, since nothing moves a Document out of `DRAFT`. An endpoint
> that answers 422 to every document that exists is worse than no endpoint.
>
> `DRAFT`, `UNDER_REVIEW`, `FINAL` and `VOID` are **unreachable in M5.2 and recorded as such** — the
> D-109 precedent this section already invoked, now applied to four statuses instead of none.

**`document_number` is an internal office identifier and never a legal number.** `DOC-{YYYY}-{NNNNNN}`,
allocated atomically per Office and calendar year, following D-103 and D-108 exactly. It is not a
deed number, not a repertorium entry, and carries no legal weight.

**M5.1 settled it as nullable** (D-116), following the `project_number` / `matter_number` precedent
exactly: no creation path allocates one yet, so `NOT NULL` would make a Document unwritable for a
whole milestone. The allocator ships with the column; the milestone that builds upload stamps the
reference inside the creating transaction and tightens the column then, as M3.3 and M4.4 each did.

### 10.3 Data Scope

```text
OWN       documents.created_by = actor id
OFFICE    documents.office_id  = actor office
ALL       cross-office reach
ASSIGNED  no grant — a Document has no assignee
TEAM      no grant — no Team entity exists (D-042)
```

`ASSIGNED` is withheld for the reason D-080 withheld it from Party: there is no assignment entity
for the predicate to match. Offering it would let an administrator save a silently powerless grant.

**M5.1 implemented this table verbatim** in `App\Domains\Document\DocumentVisibility` and, so that
the Permission Matrix cannot offer what the resolver will not honour, narrowed all nine
`documents.*` codes to `OWN, OFFICE, ALL` in `PermissionScopeRules` (D-116). Withholding `ASSIGNED`
only in the predicate would have left the dead control this section warns about still visible in the
interface.

### 10.4 Sensitive access is a separate capability, in both directions

`documents.sensitive.view` and `documents.sensitive.download` are **independent codes, not
escalations**. Holding `documents.view` does not imply seeing a sensitive document's metadata, and
holding `documents.download` does not imply downloading its file. Neither implies the other, and
`documents.update` reaches none of them — the D-091 and D-110 discipline.

**A sensitive document the actor may not see is not silently omitted from a list.** M4.5 settled the
shape (D-110): the row appears as a minimal stub, because hiding it misreports what the office holds
to somebody authorized to read the collection. What the stub may carry — and whether even the title
of a sensitive document is safe to show — is M5.2's to settle, and it is a genuine question rather
than a formality.

---

## 11. Tasks

### 11.1 Field list, transcribed

`03_DATABASE_ERD.md` section 15:

```text
id  office_id  project_id  matter_id  title  description  status  priority
assigned_to  assigned_by  due_at  completed_at  completed_by
workflow_stage_instance_id  created_at  updated_at  deleted_at
```

```text
status:    OPEN  IN_PROGRESS  WAITING  COMPLETED  CANCELLED
priority:  LOW  NORMAL  HIGH  URGENT
```

**`created_by` is absent from the canonical field list**, while `assigned_by` is present. That is a
transcription question M5.4 must resolve explicitly rather than by adding a column on instinct: the
Data Scope `OWN` predicate needs an owner, and if `assigned_by` is not it, something must be. It is
recorded here so the milestone meets it as a decision rather than a surprise.

**Resolved at M5.4** *(D-119)*: **`created_by` is added**, and the extension to the canonical list is
recorded rather than quietly made. `assigned_by` cannot be the owner — it records who last handed the
work over, so ownership would move between people without anybody deciding it, and a task nobody has
assigned yet would have no owner at all.

**`workflow_stage_instance_id` is omitted at M5.4**, and unlike the blocked document junctions of
section 7 it *could* have been written: `matter_stage_instances` has existed since M4.7. It is left
out because **nothing would set it** — see 11.3.

### 11.2 Data Scope

```text
OWN       tasks.created_by  = actor id     <- resolved at M5.4 (D-119)
ASSIGNED  tasks.assigned_to = actor id
OFFICE    tasks.office_id   = actor office
ALL       cross-office reach
TEAM      no grant (D-042)
```

**A Task's `ASSIGNED` is its own predicate and widens nothing else.** Being assigned a Task confers
no Matter reach and no Project reach — the symmetric statement of D-100, which forbade a stage
assignee widening Matter `ASSIGNED`. The three assignment concepts stay separate: `projects.pic_user_id`,
`matters.pic_user_id`, `tasks.assigned_to`.

**`OWN` and `ASSIGNED` are two predicates and neither contains the other** *(M5.4, D-119)*. The M5.4
plan proposed defining `OWN` as *"created_by OR assigned_to"* and `ASSIGNED` as the same thing "for
consistency"; that would have made `OWN` a superset of `ASSIGNED`, leaving `ASSIGNED` unable to express
anything `OWN` did not already — a ranking between scopes, which D-028 forbids. Kept apart they answer
two questions an administrator may want to grant separately, *"work I raised"* and *"work I was
given"*, and an actor holding both reaches the union.

### 11.3 What M5 does not decide

**`task_templates` is not built.** `03_DATABASE_ERD.md` section 15 lists it with
`workflow_stage_id`, `default_assignee_role` and `due_days_offset` — auto-creating tasks from a
workflow stage. That is workflow content: which stage produces which task, for whom, and by when.
D-104 applies unchanged, and the table additionally carries `default_assignee_role`, which would be
role-name authorization if anything ever read it as such (D-032, D-041).

This is also why `workflow_stage_instance_id` is omitted from `tasks` at M5.4: `task_templates` is
what would fill it, so the column would be a nullable pointer no code can set — the placeholder D-095
refused.

~~**No transition matrix for tasks either.** M5 authorizes who may complete or reopen; it does not
encode which status may follow which.~~

**Superseded at M5.4** *(D-119)*, by decision rather than drift — as D-117 did for section 10.2, and
with less tension: a Task status is **operational, not legal**. Nothing about it says what a deed or a
Warkah may become. The matrix `TaskStatus` encodes:

```text
create    ->  OPEN
progress  OPEN, WAITING              ->  IN_PROGRESS
wait      OPEN, IN_PROGRESS          ->  WAITING
complete  OPEN, IN_PROGRESS, WAITING ->  COMPLETED
reopen    COMPLETED                  ->  IN_PROGRESS
cancel    anything not yet finished  ->  CANCELLED
delete    COMPLETED, CANCELLED       ->  (soft deleted)
```

**Deletion is the rule the others exist to support**: nothing in flight disappears without somebody
saying what happened to it. **Completion is reversible and cancellation is not** — un-cancelling would
undo a statement that the work will not happen, so a mistaken cancellation is corrected by raising a
new task, which leaves a record of both.

---

## 12. Authorization shape, inherited unchanged

```text
Controller::authorize(...)  ->  Policy  ->  EffectiveAccessResolver  ->  Data Scope
```

No permission-code authorization as backend authority, no role-name checks, no `SUPER_ADMIN` bypass
(D-048, D-032, D-041). Data Scopes are **predicates, never a ladder**; multiple grants **union**
(D-028); unknown or missing scope metadata **fails closed** (D-039).

**Frontend gates are presentation only** (D-113). A `can_download` flag decides what is offered; the
endpoint authorizes again, and the file is streamed only after it does.

---

## 13. Milestone decomposition

```text
M5.0   Document / Task architecture lock       <- this document
M5.1   Document schema + private storage foundation   (includes the three junction tables)
M5.2   Document management surface + document frontend + Project/Matter sections
M5.3   Document relation surfaces (attach / detach) + Party document sections   <- done
M5.4   Task schema + management + task frontend   <- done
M5.5   (absorbed into M5.4)
M5.6   M5 quality gate
```

**Corrected at M5.1** *(D-116)*. This list originally put the three junction tables in M5.3, and
M5.1 built them instead. The reason is structural rather than a change of mind: `party_documents`,
`project_documents` and `matter_documents` each carry an `office_id` constraint carrier with a
composite foreign key into `documents (id, office_id)`, and that support key is created by the
`documents` migration. Splitting the tables from the key they depend on would have left a milestone
boundary running through one invariant. **M5.3 keeps the surfaces** — attaching, detaching, and the
sections that show them — which is where the authorization work actually is.

**Audit is deliberately unnumbered.** Section 8 rules that no sensitive-download surface lands
before it exists; whether it becomes M5.2a, a prerequisite milestone, or part of M5.4's batch-7
grouping is a scoped decision that belongs to whoever takes it, not to this list.

**M5.1 is schema, storage, allocator and Policy — not CRUD UI**, following M2.1, M3.1, M4.1 and
M4.2 exactly.

**Corrected again at M5.2** *(D-117)*. The document frontend was listed for M5.5; it ships with the
endpoints instead, because a nine-endpoint surface with no way to exercise it is a milestone nobody
can accept. M5.5 keeps the Task frontend, which is the part that genuinely depends on M5.4.

**The Matter and Project detail pages gain their document sections at M5.2**, and they are
**sections, not tabs** — the M4.5 and M4.7 precedent on those same two pages, and the ruling this
list has always carried: the repository has no `Tabs` primitive, and adding one is a design decision
affecting pages M4 already shipped rather than a side effect of a document milestone.

**M5.3 keeps the attach and detach surfaces**, which is where the authorization work actually is.
M5.2 writes junction rows only as part of an upload, where the target is re-resolved through its own
domain's visibility first.

**Corrected a third time at M5.4** *(D-119)*. M5.5 held the Task frontend on the reasoning that it
"genuinely depends on M5.4". It ships with M5.4 instead, for the reason M5.2 gave when it absorbed the
document frontend: a twelve-route surface with no way to exercise it is a milestone nobody can accept.
The dependency claim was true and the conclusion did not follow from it. **M5.5 is not renumbered** —
the identifier stays retired so nothing above it shifts.

**No milestone in M5 seeds content.** No document types, no task templates, no requirement
catalogues.

---

## 14. Unresolved items

| Question | Status | Blocks M5.1? |
|---|---|---|
| Where audit lives, and what it records | **OPEN, and section 8 rules that no sensitive-download surface ships before it is answered.** Batch 7 per the ERD | **No** — M5.1 writes no download surface |
| Document type catalogue | **DOMAIN VALIDATION REQUIRED.** `document_type_code` stays opaque and nullable; the ERD's examples are prose | **No** |
| Whether a sensitive document's *title* is safe to show in a list | **DEFERRED at M5.2, not answered** (D-117). Rather than render a stub whose contents nobody has decided, the list **excludes** sensitive documents an actor cannot reach — a query condition, so the pagination total stays honest. The milestone that wants a stub still has to decide what it may carry | Closed for M5.2 |
| `is_current` uniqueness — partial index, application invariant, or a pointer on `documents` | **RESOLVED at M5.1** (D-116). `is_current` is gone; `documents.current_version_id` carries a **composite** foreign key `(id, current_version_id) -> document_versions (document_id, id)`, so a pointer at another document's version is unrepresentable | Closed |
| `document_number` required or nullable at first | **RESOLVED at M5.1** (D-116): nullable, because no creation path allocates one yet. The milestone that builds upload stamps it inside the creating transaction and tightens the column | Closed |
| `tasks` has `assigned_by` but no `created_by`, while Data Scope `OWN` needs an owner | **RESOLVED at M5.4** (D-119). `created_by` is added and the extension to the canonical field list is recorded. `assigned_by` cannot be the owner: it moves on every reassignment, and an unassigned task would have no owner at all. `OWN` is `created_by`, `ASSIGNED` is `assigned_to`, and the two stay separate predicates that union when both are held | Closed |
| Requirement templates and workflow gating | **DOMAIN VALIDATION REQUIRED** and structurally blocked: `service_document_requirements` does not exist (D-104) | **No** |
| `task_templates` and auto-creating tasks from a stage | Deferred. Workflow content, plus a `default_assignee_role` column that must never become role-name authorization | **No** |
| Document preview and thumbnailing | Not in scope. Rendering a legal document is a second delivery path and needs its own security argument | **No** |
| Retention, expiry, and what `expiry_date` obliges | **OPEN.** The column is canonical; whether anything acts on it is undecided, and expiring a legal document is not an engineering decision | **No** |
| The three blocked junctions | Structurally blocked until M6 / M7 build their tables (section 7) | **No** |

Both questions M5.1 owned are now closed above; the rest are recorded rather than quietly assumed
and belong to later milestones.

---

**Status:** `LOCKED — M5.0`
