# M6 — Notary Architecture

**Status:** `LOCKED — M6.0`

Sibling of `12_M2_PARTY_ARCHITECTURE.md`, `13_M3_PROJECT_ARCHITECTURE.md`,
`14_M4_MATTER_ARCHITECTURE.md` and `15_M5_DOCUMENT_TASK_ARCHITECTURE.md`. Where those locked the
Party, Project, Matter and Document aggregates, this one locks the **Notary legal output**: the
Notarial Deed, its Minuta Akta, and the two registers that record them.

It records what M6 may build, what it must not, and — as importantly — which of its statements are
transcribed from canonical sources, which are engineering decisions taken here, and which remain
questions **nobody in this repository has the authority to answer.**

This lock is unlike its four predecessors in one respect, and the difference is the reason it was
written before any code: **M6 is the first milestone whose specification is deliberately empty.**

Every ruling below was reviewed and accepted before this document was written. Nothing in it is
inference promoted to fact.

---

## 1. Scope

M6 implements the **Notary domain's legal records**: the Notarial Deed and the Minuta Akta, together
with the Matter extension that classifies a Notary Matter.

M6 does **not** implement PPAT. `01_ARCHITECTURE.md` section 28 places **M7 — PPAT** after this
milestone, so PPAT Deeds, Warkah, properties, taxes, PPAT registers and PPAT reports are outside M6
entirely.

The sentence this document exists to hold:

> **The office's Notary workflow is blocked on domain validation, not on engineering. M6 builds the
> records that workflow will act on, and refuses to guess the workflow.**

That is not a limitation being apologized for. It is D-104's ruling applied one milestone later, and
section 5 is where it bites.

---

## 2. Terminology

Transcribed from `05_I18N_LEGAL_TERMINOLOGY.md` and fixed. These are never translated into
substitutes:

```text
Akta Notaris      Notarial Deed
Minuta Akta       Original Deed Record
Repertorium       the Notary's register of deeds
Protokol Notaris  Notary Protocol
Legalisasi        (retains the Indonesian term)
Waarmerking       (retains the Indonesian term)
```

**Legalisasi and Waarmerking are Service Types, not entities.** `05_I18N_LEGAL_TERMINOLOGY.md`
lines 133–134 catalogue them as terminology; `service_types` (M4.1) is the container that holds
them. M6 creates **no** `legalisasi` or `waarmerking` table, and seeds no service type — consistent
with every milestone since M4, none of which has seeded catalogue content.

**A Deed is not a Document.** The Deed is the legal record: its number, its date, its state, who
reviewed and approved it. The *file* lives on a Document and its immutable versions (M5.1). The Deed
points at Documents; it never stores bytes. This is the same separation section 2 of the M5 lock
drew between a Document and a file, applied one level up.

---

## 3. What already exists, and what M6 inherits

### 3.1 Permissions — the count stays at 177

**Every Notary code M6 implements is already canonical**, registered since the catalogue was
transcribed at M1.2 and unimplemented ever since:

```text
notary.matters.view        notary.matters.view_all    notary.matters.create
notary.matters.update      notary.matters.assign      notary.matters.change_stage
notary.matters.complete    notary.matters.cancel
notary.matters.parties.view   notary.matters.parties.manage

notary.deeds.view          notary.deeds.create        notary.deeds.update
notary.deeds.review        notary.deeds.approve       notary.deeds.finalize
notary.deeds.number

notary.minuta.view         notary.minuta.create       notary.minuta.update
notary.minuta.archive      notary.minuta.release

notary.register.view       notary.register.create     notary.register.update
notary.register.finalize   notary.register.export
```

**M6 therefore registers no permission. The canonical count stays at 177** — as it did at M5.2, M5.3
and M5.4. A milestone that finds itself wanting a new Notary code should read the next subsection
before adding one.

### 3.2 What the catalogue does *not* contain, verified against the live registry

```text
notary.deeds.lock        ABSENT
notary.deeds.void        ABSENT
notary.deeds.delete      ABSENT
notary.register.delete   ABSENT
notary.minuta.delete     ABSENT
notary.protocol.*        ABSENT  — all four
```

These are not oversights to be corrected by this milestone. Three of them —
`lock`, `void`, `delete` — are precisely the **post-finalization correction mechanisms**
`CLAUDE.md` section 29 says *"must follow documented business rules"*, and
`08_NOTARY_WORKFLOW.md` section 6 lists as an open question. **The catalogue's silence and the
workflow document's silence agree with each other**, which is evidence rather than coincidence.

**M6 builds no act that has no canonical code.** Sections 8.4 and 10 record what that costs.

### 3.3 `notary.deeds.number` exists, and it matters

The catalogue anticipated that **assigning a deed number is its own capability**, separate from
`finalize`. Nothing in this repository had noticed that until M6.0. It is the code section 8.3 uses,
and its existence is what makes numbering expressible without inventing a numbering *rule*.

### 3.4 Structural prerequisites — all already present

```text
matters_id_office_id_unique      M4.2
documents_id_office_id_unique    M5.1
users_id_office_id_unique        M5.4
```

**M6 adds no support key.** Every composite foreign key it needs can be written against a unique
index that already exists — the first milestone since M2 for which that is true.

### 3.5 What M4 and M5 already give the Notary domain

- **`matters` with a `domain` discriminator** (M4.2). There is no separate `notary_matters` root and
  M6 does not create one; section 7's table is an **extension**, keyed by `matter_id`.
- **`/notary/matters` as a route-derived permission namespace** (D-101, M4.4). M6's deed routes
  follow the same construction and for the same reason.
- **Matter ↔ Party participation** (M4.5). The parties to a deed are the parties to its Matter. M6
  builds no second participation surface.
- **The workflow engine** (M4.6, M4.7) — running, and **empty**, per D-104.
- **Documents, versions, private storage and the relation surfaces** (M5.1–M5.3).

### 3.6 One blocked junction becomes unblocked

D-118 recorded `notary_deed_documents` as blocked because `notary_deeds` **did not exist**, and was
explicit that this was structural rather than a scoping preference. Creating the table removes that
blocker. `DocumentRelationType` already names `notary_deed` as a blocked case precisely so adding it
later is *"adding a case and a migration rather than redesigning the enum."*

**M6 does not build that junction.** It records that the obstacle is gone and leaves the surface to
the milestone that wants it — the deed's three document pointers (section 8.2) cover what M6 needs.

---

## 4. Canonical ordering, transcribed

`03_DATABASE_ERD.md` section 32:

```text
7.  tasks, calendar, activity, audit
8.  properties, ownership, PPAT matter extensions
9.  Notary deeds and Minuta                      <- M6
10. PPAT deeds and Warkah
11. registers, protocol, taxes, billing, advanced reporting
```

Two things follow, and neither is an invention of this document:

- **Deeds and Minuta are one batch. Registers and protocol are a different, later one** — later even
  than PPAT deeds. The prompt that initiated M6 grouped all four together; the canonical ordering
  does not, and section 9 follows the ordering.
- **Audit is still batch 7 and still absent.** D-115 rules that no sensitive-download surface ships
  before it exists. M6 adds no such surface and does not lift that gate.

The section closes with *"Do not create all future tables prematurely if the milestone does not
require them"*, which is the rule sections 9 and 10 apply.

---

## 5. The five questions M6 may not answer

`08_NOTARY_WORKFLOW.md` is stamped `DRAFT — DOMAIN VALIDATION REQUIRED` and
`DO NOT IMPLEMENT FROM THIS DOCUMENT YET`. Its section 2 states why: `CLAUDE.md` section 62
prohibits inventing Notary procedures, approval requirements, **deed numbering rules**, registration
deadlines, or document requirements when the specification does not define them. Its section 6 lists
seven open questions.

**Five of those seven are business rules a Notary deed surface would ordinarily encode:**

| `08_NOTARY_WORKFLOW.md` section 6 | M6 disposition |
|---|---|
| *"What are the deed numbering rules, and who assigns the number?"* | **Blocked.** No format, no allocator. See 8.3 |
| *"What is the correct Repertorium entry procedure and period?"* | **Blocked.** No register table in M6. See 9 |
| *"What triggers Minuta Akta archiving, and what release conditions apply?"* | **Blocked.** Metadata only. See 8.5 |
| *"What correction mechanisms are permitted after finalization?"* | **Blocked.** No lock, void or supersede path. See 8.4 |
| *"Which stages require Principal approval rather than staff completion?"* | **Blocked, and doubly so.** See below |

The remaining two — which service types the office handles, and the stage sequences per service type
— are D-104's territory and unchanged.

**The approval question is blocked twice over.** It is an open domain question *and* the shape the
prompt proposed to answer it with — *"default hanya PRINCIPAL dan SUPER_ADMIN"* — is a **role-name
authorization**, which D-032, D-041 and D-048 forbid as a mechanism regardless of what the domain
source eventually says. Who may approve is decided by holding `notary.deeds.approve` at a Data Scope
that reaches the record, and by nothing else. When the domain source answers, the answer is expressed
as a **role configuration**, not as a role-name check in code.

### 5.1 What "blocked" means here, precisely

It does **not** mean the column is absent. Where `03_DATABASE_ERD.md` names a column, M6 creates it,
because the field lists are canonical transcription and a schema that matches the ERD is not a legal
claim. It means **no code path reaches it**, no endpoint offers it, and no interface control implies
it exists.

This is the D-109 pattern — Matter's `IN_PROGRESS`, `WAITING` and `ON_HOLD` are stored vocabulary
with no control that sets them — and the D-102 pattern, where `matters.deleted_at` is *"reserved
schema capability with no code path."* M6 applies both, and section 8.4 says exactly which values
land in that category.

---

## 6. What M6 may build

Stated affirmatively, so the milestone is not defined only by its refusals:

- **`notary_matters`** — the Matter extension, transcribed from `03_DATABASE_ERD.md` section 10.
- **`notary_deeds`** — transcribed from section 17, with the two dispositions section 8.1 records.
- **`notary_minuta`** — transcribed from section 17, metadata only.
- **The lifecycle ladder `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED`** — see section 8.4. This one
  is *not* a guess.
- **Deed numbering as a stored, office-unique, manually-supplied value** — see section 8.3.
- **CRUD, Policy, Data Scope, Office boundary, and the deed frontend** — engineering throughout.

---

## 7. `notary_matters`

### 7.1 Field list, transcribed

`03_DATABASE_ERD.md` section 10:

```text
matter_id
deed_category
requires_minuta
requires_register_entry
notes
created_at
updated_at
```

### 7.2 What M6 decides about them

**`matter_id` is the primary key**, not a surrogate ULID beside it. This is an extension row, one per
Matter, and a second identifier would be a second way to name the same thing. It carries a composite
`(matter_id, office_id) -> matters (id, office_id)` and therefore **also stores `office_id`** — an
addition to the canonical field list, recorded here rather than made quietly, and the same
construction every junction since D-080 has used.

**The three semantic columns are stored and read, never acted on.**
`03_DATABASE_ERD.md` line 770 names `deed_category`, `requires_minuta` and `requires_register_entry`
as *"domain-semantic and unvalidated"*, which is why M4 refused to persist anything standing in for
them. M6 may now persist them, because M6 is the milestone the ERD assigns them to — but persisting a
flag is not the same act as **branching on it**.

Specifically: **`requires_register_entry` triggers nothing.** The prompt asked that finalizing a deed
automatically create a register entry when this flag is true. That is the Repertorium procedure
question, and there is no register table in M6 to create an entry in. The column records the office's
own classification; what the office does with it is theirs until a domain source says otherwise.

**`deed_category` stays opaque and nullable**, exactly as `document_type_code` did at M5.1 (D-116):
the ERD gives no vocabulary for it, and the examples elsewhere in the canonical set are prose.

---

## 8. `notary_deeds`

### 8.1 Field list, transcribed

`03_DATABASE_ERD.md` section 17:

```text
id  office_id  matter_id  deed_number  deed_date  deed_type_code  title  status
draft_document_id  final_document_id  minuta_document_id
reviewed_at  reviewed_by  approved_at  approved_by
finalized_at  finalized_by  locked_at
created_at  updated_at
```

```text
status:  DRAFT  UNDER_REVIEW  APPROVED  FINALIZED  VOID  SUPERSEDED
```

**Two dispositions, both recorded rather than drifted into:**

**`locked_by` is not added.** The canonical list carries `locked_at` and no `locked_by`, and the
asymmetry is not obviously an omission: the other three timestamps pair with an actor because a
person performs each act, while locking — under every reading available — is a **consequence** of
finalization rather than a separate act somebody performs. Adding an actor column would assert that
somebody locks a deed, which is one of the correction-mechanism questions. The column stays absent
until a domain source describes who locks and why. *(Contrast M5.4, where `created_by` **was** added:
there, the Data Scope `OWN` predicate structurally required an owner and no existing column could
serve. Nothing here requires `locked_by`.)*

**`deleted_at` is not added, and there is no soft delete.** The ERD gives `notary_deeds` no
`deleted_at`; section 33 says finalized legal records *"should generally use states such as ARCHIVED,
VOID, SUPERSEDED, CANCELLED rather than destructive deletion"*; `CLAUDE.md` section 30 forbids
user-facing hard delete for finalized Deeds outright; and **there is no `notary.deeds.delete`
capability to authorize one.** Four canonical sources agree. The prompt asked for soft delete
restricted to `DRAFT` status — a defensible product idea that would nonetheless mean adding a column
the ERD omits and a permission the catalogue omits, to build an act nobody has asked for in writing.

**`created_by` is not added either.** Unlike Task, the `OWN` predicate has somewhere else to go —
see section 8.6.

### 8.2 The three document pointers

`draft_document_id`, `final_document_id` and `minuta_document_id` each point at a **Document**, not a
version. The Document already carries its own current-version pointer through the composite key M5.1
built (D-116), so a deed naming a Document gets the current file for free and the version history
behind it stays intact.

Each is a composite `(x_document_id, office_id) -> documents (id, office_id)`, so a deed cannot point
at another Office's document. All three are **nullable** and **`RESTRICT` on delete**: a Document a
deed depends on cannot be removed out from under it.

**Three pointers, not one polymorphic column with a role.** The ERD names three, they mean three
different things, and a deed may legitimately hold all three at once.

### 8.3 Deed numbering — the shape, without the rule

**`deed_number` is stored, office-unique when present, and supplied by the office.**

```text
UNIQUE (office_id, deed_number)   partial: only where deed_number IS NOT NULL
```

- **No format is validated.** `{deed_type_code}/{register_number}/{year}` is the prompt's proposal
  and it is exactly the rule section 5 says M6 may not invent.
- **No allocator is built.** D-108's Matter allocator produces `N-YYYY-NNNNNN`, and D-103 already
  ruled that identifier is *"an operational identifier, never a legal deed number"* — *"not a deed
  number, a repertorium number, a minuta or Warkah number."* Reusing it here would be precisely the
  conflation those two decisions exist to prevent.
- **Nullable**, following `document_number` at M5.1 and `matter_number` at M4.2: no creation path
  allocates one, so requiring it at creation would assert that the number exists before the deed
  does — which is half of *"who assigns the number, and when?"*
- **Set through `notary.deeds.number`**, its own canonical capability (section 3.3), on its own
  endpoint. Not folded into `finalize`, because folding them would assert that numbering happens at
  finalization, which is the other half of the same open question.

**Whether a deed must carry a number to be finalized is left unenforced** and recorded in section 12.
An office that requires it enforces it as a matter of practice until the rule is written down.

### 8.4 The lifecycle — what is reachable and what is stored vocabulary

**Reachable in M6:**

```text
create   ->  DRAFT
review   DRAFT              ->  UNDER_REVIEW    notary.deeds.review
approve  UNDER_REVIEW       ->  APPROVED        notary.deeds.approve
finalize APPROVED           ->  FINALIZED       notary.deeds.finalize
```

**This ladder is not invented.** `CLAUDE.md` section 29 states it verbatim as the legal-record
lifecycle — `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED → LOCKED` — and section 64 states its
consequence: once finalized, prevent normal edits, show the record as locked, preserve original
values. That is a constitution-level statement about legal records generally, not content inferred
from the draft workflow document. Encoding it is the same act D-117 and D-119 took for Document and
Task, with the difference that here the source is explicit.

**Stored vocabulary with no code path — the section 5.1 category:**

```text
VOID         no path, no notary.deeds.void capability
SUPERSEDED   no path, no capability
locked_at    column present, nothing writes it, no notary.deeds.lock capability
```

`CLAUDE.md` section 29 lists `CORRECTION`, `AMENDMENT`, `SUPERSEDE` and `VOID` as *"possible future
correction mechanisms"* that *"must follow documented business rules"*. No such rules exist. The CHECK
constraint admits all six values, because the vocabulary is canonical; the API offers four.

**Backwards transitions are refused.** An `APPROVED` deed does not return to `DRAFT` through this
surface — that is a correction mechanism, and correction mechanisms are the blocked question. What an
office does today is what it did before the software existed.

**`FINALIZED` is read-only**, per `CLAUDE.md` sections 29 and 64. `update` is refused on it, and the
capability flag the interface reads says so, so no control is offered that would answer 422.

### 8.5 `notary_minuta` — metadata, and not the archive lifecycle

Transcribed from `03_DATABASE_ERD.md` section 17:

```text
id  notary_deed_id  document_id  archive_location  volume_number  bundle_number
archived_at  archived_by  release_status  notes  created_at  updated_at
```

**`office_id` is added** — the canonical list omits it, and the composite foreign keys to
`notary_deeds` and `documents` require a carrier. Recorded as an extension, the same way section 7.2
records it for `notary_matters`. *(Contrast `task_comments` at M5.4, which correctly has none: it
reaches its Office through its task in one join and needs no composite key of its own. Minuta needs
two.)*

**One Minuta per Deed**, enforced by `UNIQUE (notary_deed_id)`. The ERD does not state the
cardinality; this is an engineering decision, and it is the conservative one — a Minuta Akta is the
original record of *one* deed. If an office needs a second, that is a rule to state, not an index to
drop quietly. *(The reverse of D-116's ruling on document relations, where the open schema was the
conservative choice because no cardinality rule existed either way. Here the term itself carries the
cardinality.)*

**`release_status` is stored and its lifecycle is not built.** The ERD names the column and gives it
**no vocabulary at all** — the `DRAFT / ARCHIVED / RELEASED` triple is the prompt's invention. Worse,
*"What triggers Minuta Akta archiving, and what release conditions apply?"* is open question four.
So M6 creates and reads the column, and builds **no** archive and **no** release endpoint, leaving
`notary.minuta.archive` and `notary.minuta.release` registered-and-unimplemented — the state
seventeen document codes sat in for five milestones (D-064).

**`archive_location`, `volume_number` and `bundle_number` are free text.** They describe a physical
shelf. Inventing a structure for them would be inventing the office's filing system.

### 8.6 Data Scope

```text
OWN       the Matter's pic_user_id  — inherited, see below
ASSIGNED  the Matter's pic_user_id
OFFICE    notary_deeds.office_id = actor office
ALL       cross-office reach
TEAM      no grant (D-042)
```

**A Deed's reach is its Matter's reach.** A deed carries no `pic_user_id`, no `assigned_to` and no
`created_by`, so `OWN` and `ASSIGNED` resolve **through the parent Matter** rather than against a
column of the deed's own. This is deliberate and is the narrower reading: it means holding
`notary.deeds.view` at `OWN` reaches deeds on Matters the actor leads, and nothing else.

**Reaching a Matter still confers no Deed authority**, and reaching a Deed confers no Matter
authority — D-100's ruling, restated one level down. The two are judged by their own capability and
their own scope; the Matter supplies only the *predicate* for `OWN` and `ASSIGNED`, never the grant.

`PermissionScopeRules` gains an explicit Notary-deed entry offering all four assignable scopes, as
`TASK_DOMAIN` did at M5.4 — offered as a decision rather than as the permissive default's
"nobody has decided yet."

---

## 9. Registers — not built in M6

`03_DATABASE_ERD.md` section 21 defines `notary_register_entries`, and section 32 places registers in
**batch 11** — after Notary deeds (9) and after PPAT deeds (10). M6 is batch 9.

Three independent reasons, any one of which would be sufficient:

1. **The canonical ordering puts it two batches later.**
2. **The Repertorium procedure and period are open question two.** A register is not a list of rows;
   it is a legally-prescribed book with rules about what enters it, in what order, within what
   period, and when it closes. `register_number`, `period_year` and `period_month` are the *shape* of
   those rules, not the rules.
3. **`notary.register.delete` does not exist**, and the prompt's surface requires it.

The four register codes — `view`, `create`, `update`, `finalize`, plus `export` — stay registered and
unimplemented. **Nothing in M6 writes a register entry, and no deed action creates one.**

---

## 10. Protocol — not built in M6, and not the shape the prompt described

**The canonical table is `protocol_records`, not `notary_protocols`.**
`03_DATABASE_ERD.md` section 22:

```text
id  office_id  domain  record_type  reference_number  period_year
storage_location  status  finalized_at  finalized_by  notes  created_at  updated_at
```

```text
domain:  NOTARY  PPAT
```

One table with a **domain discriminator**, exactly as `matters` has — not a Notary-specific table.
It carries **no junction to deeds whatsoever**: no `notary_protocol_items`, no `sequence_no`, no
`protocol_id + notary_deed_id` composite key. It has no `protocol_number`, no `period_start_date`,
no `period_end_date`, no `closed_at`, no `closed_by`, and the ERD names **no status vocabulary** for
it.

The prompt's design — two tables, a deed↔protocol many-to-many, an `OPEN / CLOSED / ARCHIVED`
lifecycle and four `notary.protocol.*` permissions — is a coherent product idea and **none of it is
canonical.** Building it would mean creating two non-canonical tables and four non-canonical
permission codes to implement a lifecycle no document describes.

`02_MENU_AND_PERMISSIONS.md` line 1066 lists Notary Protocol under *"Later milestones may
activate"*, and the permission catalogue has no protocol code at all — the two agree.

**M6 builds no protocol table and no protocol surface.** Recorded in section 12 as an item needing
both a domain source and a permission decision.

---

## 11. Authorization shape, inherited unchanged

```text
Controller::authorize(...)  ->  Policy  ->  EffectiveAccessResolver  ->  Data Scope
```

No permission-code authorization as backend authority, no role-name checks, no `SUPER_ADMIN` bypass
(D-048, D-032, D-041). Data Scopes are **predicates, never a ladder**; multiple grants **union**
(D-028); unknown or missing scope metadata **fails closed** (D-039).

**The route decides the permission namespace** (D-101). Deed routes live under `/notary/deeds`, and
the namespace comes from the route rather than from anything the caller sends — the M4.4
construction, unchanged.

**Every act gets its own capability, and none implies another.** `notary.deeds.update` does not reach
`review`; `review` does not reach `approve`; `approve` does not reach `finalize`; `finalize` does not
reach `number`. The D-091 discipline, and here it is load-bearing rather than stylistic: an office
that separates preparing a deed from approving it is expressing something about who may bind the
office legally.

**Frontend gates are presentation only** (D-113). Capability flags fold status eligibility into
capability so no control is offered that would answer 422; the endpoint authorizes again regardless.

**Sensitive identity stays masked.** NIK and NPWP are never copied into deed, minuta, register or
protocol tables, into query keys, URLs, or logs — the standing rule since M2.

---

## 12. Milestone decomposition

```text
M6.0   Notary architecture lock                       <- this document
M6.1   notary_matters + notary_deeds schema + Policy    (no routes, like M5.1)
M6.2   Deed management surface + deed frontend
M6.3   notary_minuta — metadata and document link, no archive/release lifecycle
```

**M6.1 is schema, Policy and Data Scope — not CRUD UI**, following M2.1, M3.1, M4.1, M4.2 and M5.1
exactly.

**M6.2 ships the frontend with the endpoints**, following M5.2 and M5.4: a deed surface nobody can
exercise is a milestone nobody can accept.

**There is no M6.4.** Registers and protocol are not deferred *within* M6; they are outside it, per
sections 9 and 10 and the canonical batch ordering. Numbering them here would imply they are one
domain answer away, and they are two batches and a permission decision away.

**No milestone in M6 seeds content.** No service types, no deed type catalogue, no workflow stages,
no register periods.

---

## 13. Unresolved items

| Question | Status | Blocks M6.1? |
|---|---|---|
| Deed numbering rules, and who assigns the number | **OPEN — `08_NOTARY_WORKFLOW.md` §6.** M6 stores an office-supplied, office-unique `deed_number` through `notary.deeds.number` and validates no format | **No** |
| Whether a deed must carry a number before it may be finalized | **OPEN.** Left unenforced rather than guessed in either direction | **No** |
| Repertorium entry procedure and period | **OPEN — §6.** No register table in M6; batch 11 | **No** |
| What triggers Minuta archiving, and what releases it | **OPEN — §6.** `release_status` stored with no vocabulary and no lifecycle; `notary.minuta.archive` and `.release` stay unimplemented | **No** |
| Correction mechanisms after finalization | **OPEN — §6 and `CLAUDE.md` §29.** `VOID`, `SUPERSEDED` and `locked_at` are stored vocabulary with no code path, and no capability exists for any of them | **No** |
| Which capability satisfies deed approval, per service type | **OPEN — §6.** `notary.deeds.approve` authorizes it; *which roles hold it* is office configuration, never a role-name check (D-032, D-041, D-048) | **No** |
| Whether `notary_deeds` should carry `locked_by` | **DEFERRED at M6.0.** The ERD omits it and nothing structurally requires it. Revisit when the locking act is described | **No** |
| Whether a Deed may have more than one Minuta | **DECIDED at M6.0**: one, enforced by a unique index. The term carries the cardinality. A second requires a stated rule, not a dropped index | **No** |
| Protocol: table shape, lifecycle, and four missing permission codes | **OPEN, and outside M6** (§10). Needs a domain source *and* a catalogue decision | **No** |
| `notary_deed_documents` junction | **UNBLOCKED but not built** (§3.6). The obstacle D-118 recorded was the missing table; M6.1 removes it | **No** |
| Audit | **OPEN since M5** (D-115). M6 adds no sensitive-download surface and does not lift the gate | **No** |

---

**Status:** `LOCKED — M6.0`
