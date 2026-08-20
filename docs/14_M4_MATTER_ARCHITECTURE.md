# M4 — Matter & Workflow Architecture

**Status:** `LOCKED — M4.0`

Sibling of `12_M2_PARTY_ARCHITECTURE.md` and `13_M3_PROJECT_ARCHITECTURE.md`. Where those
locked the Party and Project aggregates, this one locks Matter and the Workflow Engine. It
records what M4 may build, what it must not, and — as importantly — which of its statements are
transcribed from canonical sources, which are engineering decisions taken here, and which remain
questions nobody has the authority to answer yet.

Every ruling below was reviewed and accepted before this document was written. Nothing in it is
inference promoted to fact.

---

## 1. Scope

M4 implements **Matter and the Workflow Engine mechanism**.

M4 does **not** implement any Notary or PPAT legal output. `01_ARCHITECTURE.md` section 28 places
**M6 — Notary** and **M7 — PPAT** after this milestone, and that ordering is why deeds, Drafts,
Minuta Akta, Repertorium, Notary Protocol, PPAT Deeds, property and land records, Warkah, tax
processing, registers and PPAT reports are all outside M4 entirely.

The single most important sentence in this document:

> **M4 builds a configurable workflow mechanism. It seeds no workflow content, because none
> exists.**

See section 9, and **D-104**.

---

## 2. Terminology

```text
Project    one client engagement, transaction, or larger legal requirement   (M3, delivered)
Matter     the operational unit of work inside it                            (M4)
Workflow   the configurable stage mechanism a Matter runs through            (M4)
```

**Matter Status and Workflow Stage are different concepts and must never be merged.** `CLAUDE.md`
section 18, `00_PROJECT_OVERVIEW.md` section 6, and section 4 of both workflow drafts all say so
independently.

```text
Matter Status:   IN_PROGRESS
Workflow Stage:  NOTARY_REVIEW
```

---

## 3. Matter is a required child of Project

`matters.project_id` is **required**. A Matter always belongs to exactly one Project.

```text
Project  1 ────< Matter  0..*
```

- One Project may have **many** Matters.
- A Project may exist with **zero** Matters — a Project with no Matter is complete, not a draft.
- **Project never embeds Matter state.** No counter, no current-Matter pointer, no rolled-up
  status. Matter references Project, never the reverse (D-087).
- Matter is a **child aggregate with its own lifecycle**, not a component of Project. It does not
  inherit Project's archive rules, and Project's lifecycle actions do not cascade into it.

M3 deliberately fixed no cardinality (D-087). M4 fixes it, and only the half it has authority
over: the *structural* requirement that a Matter names a parent. Whether an office's practice
expects every Project to carry at least one Matter is an operational question, and no minimum is
encoded anywhere.

See **D-099**.

---

## 4. Office ownership

`matters.office_id` is **required**, **inherited from the parent Project at creation**, and
**immutable for the duration of M4**.

It is not a free field. The creating actor does not choose it and cannot submit it; it is copied
from the parent Project, which is the only thing that can legitimately decide it.

**The same-Office relationship is structural, not merely validated:**

```text
matters (project_id, office_id)  ->  projects (id, office_id)
```

`projects` has carried the `UNIQUE (id, office_id)` support key this composite foreign key
requires **since M3.4** (D-098), so M4 reuses it rather than adding one. A Matter whose Office
disagrees with its Project's Office is *unrepresentable*, not merely refused — the pattern proven
twice already, for `company_people` (D-080) and `project_parties` (D-098).

`matters` additionally carries its own `UNIQUE (id, office_id)` support key, because
`matter_parties` will need it at M4.5 (section 8).

**M4 ships no Office-transfer operation** for Project or Matter — no endpoint, no Action, no
administrative path. This is an **engineering boundary, not a claim of legal impossibility**,
identical in reasoning to D-089: what a transfer would mean for participants, references already
issued, and workflow already run is undesigned, and inventing it is worse than refusing it.

See **D-099**.

---

## 5. Authorization

### 5.1 The shape is inherited unchanged

```text
Controller::authorize(...)  ->  Matter Policy  ->  EffectiveAccessResolver  ->  Data Scope
```

No permission-code authorization as backend authority, no role-name authorization, no
`SUPER_ADMIN` bypass (D-048, D-032, D-041). Data Scopes are **predicates, never a ladder**;
multiple grants **union** (D-028); unknown or missing scope metadata **fails closed** (D-039).
No widest-scope, no rank, no `maxScope`.

### 5.2 Matter Data Scope predicates

`matters.*` sat in `PermissionScopeRules`' permissive default with an explicit note that
narrowing it would mean deciding what a scope meant for a domain nobody had designed. M4 is where
that becomes legitimate — for Matter, and only for Matter.

```text
OWN        matter.created_by   == actor.id
ASSIGNED   matter.pic_user_id  == actor.id
OFFICE     matter.office_id    == actor.office_id
ALL        cross-office Matter reach
TEAM       no Matter-domain grant
```

`TEAM` is withheld here as everywhere: no Team entity exists (D-042).

### 5.3 Matter authorization is independent of Project authorization

**Reaching a Project confers no Matter authority.** An actor who may view, update, or even
archive a Project gains, by that fact alone, no right to view or change any Matter beneath it.
Every Matter ability is judged against the Matter by the predicates above.

This is deliberately the harder answer. The easy one — "if you can see the Project you can see its
Matters" — would make Project reach a silent superset of Matter reach, and would mean an
administrator granting `projects.view` had granted Notary and PPAT work visibility without ever
naming those capabilities.

**The converse is already forbidden and stays forbidden.** D-088 and `07_SECURITY_RULES.md`
prohibit Matter or stage assignment from widening Project `ASSIGNED`. M4 adds the symmetric rule
so neither direction leaks.

### 5.4 The one place the parent is consulted

**Creating a Matter validates the parent Project through canonical Project authorization.** A
Matter may not be created under a Project the actor cannot canonically reach, and the check is the
ordinary Project reach question answered by the ordinary Project mechanism — not a new predicate,
and not a relaxation.

That is the whole of the interaction. It applies to creation, where a parent is being chosen. It
does not extend to reading, updating, assigning, completing, or cancelling an existing Matter,
each of which answers only to its own capability.

### 5.5 Stage assignment is not Matter assignment

**`matter_stage_instances.assigned_user_id` does not count toward Matter `ASSIGNED`.**

Matter `ASSIGNED` means `matter.pic_user_id == actor.id`, one column, one comparison. When the
workflow gains stage assignees at M4.7 it will be tempting to let them widen Matter reach, on the
reasoning that somebody working a stage must see its Matter. That would be a **new grant wearing
an existing scope's name**, silently widening every role already configured with Matter
`ASSIGNED` — the exact failure D-088 named one milestone earlier, one domain across. If stage
workers need Matter visibility, that is its own decision and its own predicate.

See **D-100**.

---

## 6. Domain split: routes, namespaces, and persistence

### 6.1 One table, one discriminator

M4 builds **one root table, `matters`, carrying a canonical `domain` discriminator**:

```text
NOTARY
PPAT
```

This follows `03_DATABASE_ERD.md` section 9 exactly.

**M4 builds neither `notary_matters` nor `ppat_matters`.** Those extension tables and every field
in them — `deed_category`, `requires_minuta`, `requires_register_entry`, `land_office_region`,
`tax_processing_required`, `registration_required` — are domain-semantic, unvalidated, and belong
to **M6 and M7**. M4 persists none of them, and adds no column of its own standing in for one.

This follows D-095 exactly: a column added on speculation is one somebody fills in wrongly.

### 6.2 Domain-split routes

```text
/api/v1/notary/matters
/api/v1/ppat/matters
```

and their nested descendants. **The generic `/api/v1/matters?domain=...` form is refused.**

`06_API_CONVENTIONS.md` carried the generic form while the same document used domain-prefixed
paths for deeds, the canonical registry splits the capability surface with no generic namespace,
and `02_MENU_AND_PERMISSIONS.md` section 26 splits the sidebar the same way. Three sources against
one, and the one was internally inconsistent. **The generic form is corrected at M4.0**, not left
to be discovered by whoever writes the first route.

### 6.3 The permission namespace comes from the route, never the body

```text
Notary route  ->  notary.matters.*
PPAT route    ->  ppat.matters.*
```

**The namespace is a property of the route context.** It is never selected from a request-body
`domain` field, and no Policy reads row data to decide which permission to resolve.

This is the ruling that keeps M4 inside the authorization shape this codebase already has.
`13_M3_PROJECT_ARCHITECTURE.md` section 12 flagged the alternative as *"a genuinely new
authorization shape"* — a Policy choosing its own permission namespace from the record it is being
asked about. Route-derived namespacing makes the question ordinary again: each route knows its
capability before it touches the database.

**For an existing Matter, the persisted `domain` must match the domain route.** A Notary route
handed a PPAT Matter's id **fails closed through the repository's canonical binding convention** —
the same **404** that M3.4's nested participation binding returns for a foreign parent (D-098), and
for the same reason: a 403 would confirm the record exists in a domain the caller did not name.

See **D-101**.

---

## 7. Permission surface

**M4.0 adds no permission. The canonical count stays at 173.**

All sixteen Matter codes are already canonical (`02_MENU_AND_PERMISSIONS.md` sections 10 and 11):

```text
notary.matters.view          ppat.matters.view
notary.matters.view_all      ppat.matters.view_all         superseded for reach — D-090
notary.matters.create        ppat.matters.create
notary.matters.update        ppat.matters.update
notary.matters.assign        ppat.matters.assign
notary.matters.change_stage  ppat.matters.change_stage
notary.matters.complete      ppat.matters.complete
notary.matters.cancel        ppat.matters.cancel
```

`master.services.*`, `master.workflows.*` and `master.requirements.*` are likewise already
canonical, so the master-data and workflow milestones add nothing either.

**`view_all` remains a registered compatibility identifier and is not an alternate reach
mechanism** (D-090). No `view_all` code may be used as backend cross-office authorization
authority, and no second reach mechanism may exist beside `EffectiveAccessResolver`.

### The one expected addition, and it is not at M4.0

Matter participation has **no canonical permission**, exactly as Project participation had none
before M3.4 registered two (D-098). **M4.5 is expected to add four:**

```text
notary.matters.parties.view     notary.matters.parties.manage
ppat.matters.parties.view       ppat.matters.parties.manage
173 -> 177
```

Four rather than two, because the domain split is real: the role matrix in
`02_MENU_AND_PERMISSIONS.md` section 5 gives Notary Staff full access to Notary Matters and
view-only on PPAT Matters, and the reverse for PPAT Staff. A single pair spanning both domains
would hand each of them the other's participation.

**`view` and `manage` are independent, and `manage` does not imply `view`** — the M3.4 answer
(D-098), for the M3.4 reason: a silently implied capability is one nobody configured and nobody
can revoke. Neither is reached by `notary.matters.update` or `ppat.matters.update`.

**They are not registered at M4.0.** The count moves when the milestone that gives them routes
registers them, following the M3.4 precedent exactly.

---

## 8. Matter ↔ Party participation

`matter_parties` is **independent of `project_parties`**. Not inherited, not copied, not
synchronized — three distinct mechanisms, all refused.

A Project's participants may later serve as **candidate context** when adding a Matter
participant, which is a convenience for whoever is typing. It is not a data relationship, and
nothing propagates in either direction. Two tables that silently mirror each other drift apart,
and the drift is discovered by somebody reading the wrong one.

**The same-Office invariant is structural**, as it is everywhere else:

```text
matter_parties.office_id  ->  matters (id, office_id)
                          ->  parties (id, office_id)
```

Two composite foreign keys through **one** carrier column, so both endpoints must agree with it
and therefore with each other. `matters` provides its `UNIQUE (id, office_id)` support key
(section 4); `parties` has carried its equivalent since M2.1. **A cross-office Matter
participation is unrepresentable, including for an actor holding `ALL`** — `ALL` grants reach and
administrative visibility, never permission to redefine domain ownership.

`03_DATABASE_ERD.md` section 9 lists `matter_parties` without `office_id`, exactly as it listed
`project_parties` without one. The carrier is a **recorded departure**, not an oversight, and it
is recorded in the ERD itself.

### What M4 does not build into it

| ERD field | M4 disposition | Why |
|---|---|---|
| `represented_by_party_id` | **Deferred — DOMAIN VALIDATION REQUIRED** | A Party acting through another Party is representation, proxy, or legal capacity. Which of those it means, when it is permitted, and what it implies for a deed are legal questions with no canonical answer here. Guessing would invent an Indonesian notarial rule (CLAUDE.md section 62). |
| `sequence_no` | **Deferred — semantics unvalidated** | Display order, signing order, legal priority, and appearance order are four different things and the column name distinguishes none of them. A wrong guess is invisible until a deed is drafted from it. |
| `role_code` | Built, **nullable and opaque** | No enum, no `Rule::in`, no `CHECK`. The ERD's `SELLER`, `BUYER`, `SELLER_SPOUSE`, `DIRECTOR`, `COMMISSIONER`, `WITNESS` and the rest are labelled **example role codes**; constraining the column would turn examples into the catalogue the document says they are not (D-092, D-098). |

**No cardinality rule is invented.** Not a mandatory role, not a required seller, not a
one-buyer-per-Matter limit, not an exactly-one anything. Each such rule is a business rule wearing
an implementation's clothing.

**Participation is current working state, not historical legal participation.** No effective
periods, no historical versioning, no soft delete, no legal audit history. `company_people` keeps
history because deeds executed in March depend on who was a director in March (D-083); nothing yet
depends on a Matter's participant list as it stood last week, and the mechanism is not built ahead
of the requirement. If correction semantics are later needed they must be designed explicitly in
the milestone that needs them, not approximated now.

See **D-105**.

---

## 9. The Workflow Engine

### 9.1 What exists and what does not

**The engine's shape is canonical. Its content does not exist.**

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are `DRAFT — DOMAIN VALIDATION REQUIRED`,
each stamped `DO NOT IMPLEMENT FROM THIS DOCUMENT YET`, each stating that no workflow content has
been authored and **none may be inferred from other documents in this repository**. Between them
they carry sixteen unanswered domain questions.

Section 4 of both drafts is explicit that the structural vocabulary they do carry consists of
*"architectural facts, not legal rules"*. That distinction is what makes M4 possible at all.

### 9.2 What M4 may build

The mechanism, from `03_DATABASE_ERD.md` section 11:

```text
workflow_templates        office + service type, versioned, default/active flags
workflow_stages           ordered stages belonging to a template version
matter_workflows          one Matter's instantiation of a template version
matter_stage_instances    per-stage state, with name snapshots taken at instantiation
matter_stage_history      append-only record of transitions
```

**Snapshotting is the point, not decoration.** `CLAUDE.md` section 18 requires that editing a
template must not retroactively change a Matter already running. `matter_stage_instances` carries
`stage_code` and both snapshot names for exactly that reason, and `matter_workflows` records the
`workflow_version` it was instantiated from.

Stage instance status, canonical:

```text
PENDING  ACTIVE  COMPLETED  SKIPPED  BLOCKED
```

### 9.3 What M4 must never seed or infer

```text
Notary stage sequences          PPAT stage sequences
default templates               approval points
required-before-stage rules     tax gating
deed gating                     legal completion conditions
service type catalogue content  responsible-role-per-stage rules
```

A configurable engine shipped with no content is the correct outcome, and it is stated plainly
rather than presented as a limitation: **the office's actual workflow is blocked on domain
validation, not on engineering.** When a qualified domain source completes the two workflow
documents, the content becomes configuration, entered through the master-data surfaces, and no
schema change should be required to accept it.

### 9.4 Transitions

M4 authorizes **who may change a stage** — `notary.matters.change_stage` and
`ppat.matters.change_stage`, already canonical. It does **not** encode which stage may follow
which, because no canonical document defines that.

**Whether a stage transition carries legal state, as opposed to being purely operational, is
undecided and recorded as such.** It matters: if a transition ever gates deed finalization, its
immutability and audit requirements are stricter than an operational status field's.
`matter_stage_history` is therefore treated as append-only from the outset, which is the safe
direction to be wrong in.

See **D-104**.

---

## 10. Lifecycle

### 10.1 Business status

Canonical vocabulary, transcribed from `03_DATABASE_ERD.md` section 9:

```text
OPEN  IN_PROGRESS  WAITING  ON_HOLD  COMPLETED  CANCELLED  ARCHIVED
```

**M4 invents no transition matrix.** It authorizes *who* may change, complete, or cancel a
Matter — three separate canonical capabilities — and never *which* status may follow which. That
is an operational rule nobody has specified, and inventing one is exactly what `CLAUDE.md`
section 62 prohibits, one domain removed. The M3 precedent is D-091, taken for the same reason.

### 10.2 Archive and restore are not in M4

**M4 ships no Matter archive or restore lifecycle**, and **M4.0 registers no archive or restore
permission.** The canonical registry gives Matter eight codes per domain and none of them is
`archive` or `restore` — unlike Project, which has both. The absence is the registry's, and M4
does not fill it by invention.

`matters.deleted_at` may exist as reserved schema capability where the locked ERD carries it, with
**no API lifecycle reaching it.** A column without a surface is honest; a surface without a
permission is not.

**Business status and persistence deletion remain separate concepts**, and this is the trap worth
naming: `CANCELLED`, `COMPLETED` and `ARCHIVED` are **business statuses and must never be reused
as synonyms for soft deletion.** `ARCHIVED` and `deleted_at` are different states with
unfortunately similar names — the same awkwardness D-093 named for Project, restated here because
the Matter vocabulary contains the same trap and Matter has no restore path to recover from a
wrong answer.

---

## 11. Internal reference

A Matter's internal reference is **ordinary office identification**, following `CLAUDE.md`
section 38. It is explicitly **not** a deed number, a repertorium number, a land or government
registration number, or any legally significant document number.

```text
N-YYYY-NNNNNN     Notary
P-YYYY-NNNNNN     PPAT
```

Both prefixes are transcribed from `CLAUDE.md` section 38's internal-reference examples.

**Namespace: Office + calendar year + domain.** Three components, because the two prefixes are
distinct and a shared counter would make `N-2026-000001` and `P-2026-000001` compete for the same
value.

**A dedicated Matter allocator.** The M3.2 Project allocator is deliberately **not** generalized —
`13_M3_PROJECT_ARCHITECTURE.md` section 9 refused to turn it into `legal_number_sequences` or
anything deed-, repertorium- or Matter-shaped, and M4 honours that refusal rather than quietly
reversing it by extending the same table.

**One atomic statement, no read-then-write.** The M3.2 pattern — `INSERT … ON CONFLICT … DO UPDATE
SET last_value = last_value + 1 RETURNING last_value` — is the proven approach, and it runs
identically on PostgreSQL and SQLite 3.35+.

Forbidden, all of them unsafe under concurrency:

```text
MAX(number) + 1     COUNT(*) + 1     latest() + 1     read-then-write
```

**Gaps are expected and carry no meaning.** The sequence is not a record count and its order
carries no legal weight. **A reference is immutable once assigned.**

See **D-103**.

---

## 12. Service types

M4 owns the **service type master-data infrastructure** — the table, its bilingual fields, its
Office and domain scoping, and the surfaces that maintain it. `master.services.view` and
`master.services.manage` are already canonical.

**No legal service catalogue is canonical yet, and none is seeded.** Which services a Notary and
PPAT office actually offers is the first open question in both workflow drafts. M4 ships the
container empty.

**`matters.service_type_id` is therefore nullable in M4.** A Matter may exist without a service
type until domain content is validated. Making it required would make Matter uncreatable for as
long as the catalogue is empty, which is the M3.2 lesson about `project_number` in reverse
(D-095): a constraint that outruns the data it constrains blocks the milestone that would satisfy
it.

### Delivered at M4.1 *(D-106)*

One forward migration (23 total) and **no permission — the count stays at 173**; both codes were
already canonical. **Backend foundation only: no route, controller, request, resource, frontend
page, or navigation entry**, following the M2.1 and M3.1 precedent.

```text
service_types    Office-owned, one row per service the office offers
                 code + domain + both names required; both descriptions optional
UNIQUE (office_id, code)    a code identifies one service within its Office
UNIQUE (id, office_id)      the support key M4.2's composite FK will reference
CHECK domain IN (NOTARY, PPAT)
CHECK default_duration_days IS NULL OR >= 0
```

**Office, `code`, and `domain` are identity, not content**, and the model refuses to change any of
them after creation: other records classify themselves by them, so rewriting one silently
redefines what they mean. Both names, both descriptions, `sort_order` and `default_duration_days`
are ordinary content an office may correct.

**Retirement is `is_active`, and there is no other lifecycle** — no delete, no soft delete, no
archive, no restore, and no canonical code that could authorize one. An inactive Service Type
stays readable so records referencing it keep their classification (`CLAUDE.md` section 63), which
is also why M4.2's Matter foreign key must never be designed as `SET NULL`.

**Data Scope is `OFFICE` and `ALL` only** — the Party answer (D-080), not the Project one. `OWN`
would have to mean `created_by` and the table deliberately has no such column; `ASSIGNED` has no
assignee to match; `TEAM` has no Team entity. `PermissionScopeRules` offers exactly the two the
visibility class can honour, so an administrator cannot save a silently powerless grant. The other
twelve `master.*` families keep the permissive default — their domains are still undesigned.

**Creation always lands in the actor's own Office, including for `ALL`.** `ALL` is reach over
records that already exist, never authority to decide which Office a new one belongs to (D-098's
line, restated).

**`legal_term` and `preserve_legal_term` are withheld.** They appear in the ERD field list and are
defined nowhere else in the repository, and a separate `legal_terms` table exists with its own
permissions — at least three readings are plausible. Withheld until validated, exactly as M3.1
withheld `project_number` (D-095).

---

## 13. Sensitive identity

NIK, NPWP, tax identifiers and every other protected Party identifier remain **exclusively** in
the Party identity architecture (D-082).

They are never copied into:

```text
matters                  matter_parties           workflow_templates
workflow_stages          matter_stage_instances   matter_stage_history
browser storage          URLs                     query keys
logs                     ordinary Matter resources
```

Matter participation exposure follows the M3.4 pattern when it is built: a **minimal Party stub**
— id, display name, type, archived flag, and a `can_view_party` computed from canonical Party and
Company visibility per subtype — with **no masks**, because a mask is still a statement about a
sensitive value.

**Visibility is evaluated in bulk, never per row.** The actor's effective access does not vary by
row, so a per-row resolve is the N+1 M2.6 measured and fixed on the Party reverse view and M3.4
avoided by construction.

**Free-text audit fields are a leak surface and are named as one.** `matter_stage_history.reason`
and comparable fields are audit-shaped free text: they must never be used to persist Party
identity or an uncontrolled identity copy, and nothing in the product may populate them from
identity data.

---

## 14. Non-goals

Not part of M4, and not to be introduced incidentally:

```text
documents / uploads / versioning     M5        tasks                              M5
Notarial Deeds, Drafts, Minuta       M6        Repertorium, Notary Protocol       M6
PPAT Deeds, property and land        M7        Warkah, taxes, registers, reports  M7
billing / invoices / payments        M8        dashboard analytics                M8
global search                        later     deed or legal numbering of any kind
participant legal-role catalogue               legal workflow content
Project or Matter Office transfer              notary_matters / ppat_matters tables
represented_by_party_id                        sequence_no
Matter archive / restore lifecycle             status transition matrix
```

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` remain placeholders requiring domain authority.
**Nothing may be implemented from them.**

---

## 15. Milestone decomposition

```text
M4.0   Matter / Workflow architecture lock        <- this document
M4.1   Service Types + master-data foundation
M4.2   Matter schema + authorization foundation
M4.3   Matter internal reference foundation
M4.4   Matter core management
M4.5   Matter <-> Party participation
M4.6   Workflow templates + stages
M4.7   Matter workflow instances + stage transitions
M4.8   M4 quality gate
```

**M4.1 — delivered** *(D-106)*. See section 12. One forward migration (23), no permission (173),
backend foundation only.

**M4.3 — delivered** *(D-108)*. See section 11. One forward migration (25), no permission (173),
backend foundation only; `matter_number` nullable until M4.4 integrates allocation.

**M4.4 — delivered** *(D-109)*. See "Delivered at M4.4" below. One forward migration (26), no
permission (173); the first Matter milestone with an HTTP and frontend surface.

**M4.5 — delivered** *(D-110)*. See "Delivered at M4.5" below. One forward migration (27) and the
**four permissions this document scheduled: 173 → 177**. Ten routes, five per domain, and a
participation section on the Matter detail page.

**M4.6 — delivered** *(D-111)*. See "Delivered at M4.6" below. One forward migration (28) creating
`workflow_templates` and `workflow_stages`, **no permission** (177), and `master.workflows.*`
narrowed to `OFFICE`/`ALL`. Backend foundation only, and **both tables ship empty**.

**M4.7 — delivered** *(D-112)*. See "Delivered at M4.7" below. One forward migration (29) creating
the three running tables, **no permission** (177), and `*.matters.change_stage` given routes and
un-deferred. Six routes, three per domain, plus a workflow section on the Matter detail page.

**M4.2 — delivered** *(D-107)*. The `matters` root, its two enums, model, visibility, Policy, the
`PermissionScopeRules` entry for the fourteen actionable codes, and a factory. One forward
migration (24), **no permission — the count stays at 173**, and **no HTTP or frontend surface**.

```text
matters (project_id, office_id)             -> projects (id, office_id)
matters (service_type_id, office_id, domain) -> service_types (id, office_id, domain)
UNIQUE (matters.id, office_id)               the support key M4.5 will reference
CHECK domain / status / priority
```

The Service Type key does **two jobs at once**: same Office *and* same domain, so a Notary Matter
classified with a PPAT service is unrepresentable rather than merely refused. That required adding
`UNIQUE (id, office_id, domain)` to `service_types` — M4.1 shipped only `(id, office_id)`, and a
composite foreign key needs a unique index on the exact referenced columns.

**Deferred and not stubbed:** `matter_number` to M4.3 with its allocator (D-095's rule), and
`current_stage_id` to M4.7 with the real stage-instance foreign key. Neither exists as a nullable
placeholder.

### Delivered at M4.3 *(D-108)*

The internal reference and the counter that allocates it. One forward migration (25 total), **no
permission — the count stays at 173**, and **no HTTP or frontend surface**.

```text
matter_reference_counters   PRIMARY KEY (office_id, reference_year, domain)
matters.matter_number       varchar(32), nullable in M4.3, NOT NULL from M4.4
UNIQUE (matters.office_id, matter_number)
CHECK  matter_number IS NULL OR (NOTARY -> 'N-%') OR (PPAT -> 'P-%')
```

**Three namespace dimensions**, because `N-` and `P-` are distinct prefixes and a shared counter
would make `N-2026-000001` and `P-2026-000001` compete for one value. A **dedicated** counter
table: the M3.2 allocator is reused as a *pattern*, never as a table, and the generic numbering
engine `03_DATABASE_ERD.md` section 27 sketches is deliberately not used.

**`domain` is absent from the Matter unique key** — the formatted string already carries the
prefix, so a Notary and a PPAT reference in one Office-year cannot collide. The domain belongs in
the counter's key, where it separates sequences.

**Nullable until M4.4**, exactly as `project_number` was until M3.3: no creation path allocates
yet, so `NOT NULL` would make Matter unwritable for a whole milestone. **Nothing was backfilled** —
the persistent development database has no `matters` table at all.

**Immutable once the row exists**, and stricter than the Project guard: `null → value`,
`value → other`, and `value → null` are all refused, because M4.4 stamps inside the creating
transaction rather than numbering a Matter afterwards.

**`deleted_at` exists as reserved schema capability and the model uses no `SoftDeletes`** — no
global scope filters any query, so "invisible because soft-deleted" cannot be confused with
"unreachable by scope" before the milestone that owns archiving exists to decide it.

**Authorization** is one `MatterPolicy` taking an **explicit `MatterDomain` supplied by the
caller**, never read from the row to choose a permission (D-101). Eight abilities, each answering
to its own code, none implying another. Creation additionally requires `projects.view` on the
parent, the parent in the actor's **own** Office — refused even at `ALL` — and a Project that is
not archived.

### Delivered at M4.4 *(D-109)*

Matter creation, listing, detail, ordinary editing, assignment, completion and cancellation —
backend and frontend. One forward migration (26 total) that adds nothing and tightens
`matters.matter_number` to `NOT NULL`, and **no permission — the count stays at 173**.

```text
GET    /api/v1/{domain}/matters                             index
POST   /api/v1/{domain}/matters                             store
GET    /api/v1/{domain}/matters/service-type-options        pickers
GET    /api/v1/{domain}/matters/{matter}                    show
PATCH  /api/v1/{domain}/matters/{matter}                    update
PATCH  /api/v1/{domain}/matters/{matter}/assignment         assign / unassign
GET    /api/v1/{domain}/matters/{matter}/assignment/options  eligible users
POST   /api/v1/{domain}/matters/{matter}/complete           complete
POST   /api/v1/{domain}/matters/{matter}/cancel             cancel
```

Nine routes per domain, eighteen in total, `{domain}` a **literal** `notary` or `ppat` segment in
every registered route. The domain travels as a route *default* and is read back by name from the
route, never accepted as a controller argument — Laravel binds non-model parameters positionally,
which during implementation handed `show()` the Matter id where the domain belonged. A Matter of
the other domain resolves to **404**, so the pair of endpoints cannot be used to discover that a
record exists on the far side of the Notary/PPAT boundary.

**The Office is inherited from the parent Project**, never from the actor, which is what keeps the
composite foreign key of section 11 satisfiable by construction. Allocation runs inside the
creating transaction. Wrong-Office, wrong-domain, retired and nonexistent Service Types are one
indistinguishable 422, as are all four ineligible-assignee cases.

**No status control exists, deliberately.** The registry gives Matter `complete` and `cancel` and
no `change_status`, so `OPEN → COMPLETED` and `OPEN → CANCELLED` are the only reachable
transitions and there is **no status dropdown anywhere in the interface**. `IN_PROGRESS`,
`WAITING`, `ON_HOLD` and `ARCHIVED` remain filterable vocabulary that nothing can set. Inventing a
`matters.change_status` code to fill the gap would be inventing an authorization surface; the gap
is recorded instead, and M4.7 is where intermediate states properly come from. `complete` stamps
`completed_at`; `cancel` records no reason, no timestamp and no history.

**Not built:** participation, workflow, stages, deeds, archive/restore. `matters.change_stage`
stays registered and **deferred**, and the Permission Matrix reports it as such.

**Frontend:** eight locale routes across the two domains, `Notary` and `PPAT` navigation groups
carrying Matters only, each gated on its own `*.matters.view`, and 75 message keys per locale
(810 per locale in total, at exact parity).

### Delivered at M4.5 *(D-110)*

Matter ↔ Party participation: read the list, add, correct, remove, and search candidates. One
forward migration (27 total) and **four permissions — the count moves 173 → 177**.

```text
matter_parties (matter_id, office_id) -> matters (id, office_id)
matter_parties (party_id,  office_id) -> parties (id, office_id)
NO UNIQUE (matter_id, party_id)        no cardinality rule is invented
role_code varchar(30) NULL, opaque     no enum, no CHECK, no dropdown
```

**This migration adds no support key**, unlike M3.4's: `parties_id_office_id_unique` has existed
since M2.1 and `matters_id_office_id_unique` since M4.2, which added it for exactly this table. It
therefore drops none on rollback either.

**No `UNIQUE (matter_id, party_id)`.** Such an index would assert one Party holds at most one role
in a Matter, and the same person may legitimately be a seller in their own right and another
party's authorized representative. That is a domain question with no canonical answer, and section
7's rule holds: no cardinality rule is invented.

**Column set transcribed, not designed:** `notes` and `updated_at` are present because the ERD
lists them; `is_primary` is absent because it does not, even though `project_parties` has one.
`deleted_at`, `effective_from` and `effective_until` are absent — participation is current working
state, and removal is a hard delete of the relationship row that touches neither endpoint.
`sequence_no` and `represented_by_party_id` are **refused by the Form Requests**, not silently
dropped, because they are deferred rather than optional.

**`ProjectParticipantVisibility` was generalized into `ParticipantVisibility`**, keyed on an Office
id, and M3.4's call sites moved to it. Section 7 keeps `matter_parties` independent of
`project_parties` **as data**; that is a statement about tables, not an instruction to write the
`parties.view` / `companies.view` rule twice. Nothing reads `project_parties`, no column points
either way, and the parent Project's participants are not offered as candidates.

**Managing participation is never authority to discover Parties.** The candidate query applies each
subtype's own Party permission at its own Data Scope, independently; an actor holding neither gets
an empty list, not the Office. Nonexistent, other-Office, archived and unseeable-subtype produce
one indistinguishable 422. `can_view_party` is computed in **bulk**.

**`view` and `manage` are independent in both directions**, and `*.matters.update` reaches neither.
No `*.matters.parties.view_all` exists.

### Delivered at M4.6 *(D-111)*

The workflow template container and its stages. One forward migration (28 total), **no permission —
the count stays at 177** — and **no HTTP or frontend surface**.

```text
workflow_templates (service_type_id, office_id) -> service_types (id, office_id)
UNIQUE (office_id, code)                one row per code; `version` is a counter on it
UNIQUE (id, office_id)                  the support key M4.7 will reference
workflow_stages -> workflow_templates   ON DELETE CASCADE
UNIQUE (workflow_template_id, code) / (workflow_template_id, sequence_no)
CHECK version >= 1 / target_days >= 0 / sequence_no >= 1
```

**Mechanism only, and the tables are empty** (D-104). Nothing seeds or infers a stage sequence, a
default template, an approval point, a required-before-stage rule, tax or deed gating, or a legal
completion condition. A configurable engine with no content is the correct outcome — the office's
real workflow is blocked on domain validation, not on engineering, and when it arrives it should be
configuration rather than a schema change.

**`version` is a counter, not a second row.** The specification asked for both `UNIQUE (office_id,
code)` and multiple versions, which are mutually exclusive. The ERD settles it by giving
`matter_workflows` both `workflow_template_id` *and* `workflow_version` — redundant under a
row-per-version reading. The old iteration is preserved by M4.7's snapshot, which is what section 18
of `CLAUDE.md` actually requires.

**`approval_permission` is refused on save unless it names a canonical permission**, so an
unresolvable string can never reach M4.7. Storing a code authorizes nothing; reading it still goes
through a Policy and the resolver (D-048).

**`is_default` carries no cardinality rule** — M4.7 must break a tie deterministically and say how.
**The stage CASCADE constrains M4.7**: `matter_stage_instances.workflow_stage_id` must be `RESTRICT`
or nullable, or deleting a template would damage the history of Matters that ran it.

### Delivered at M4.7 *(D-112)*

The running workflow: instantiation, the stage a Matter is on, movement, and append-only history.
One forward migration (29 total) and **no permission — the count stays at 177**.

```text
GET  /api/v1/{domain}/matters/{matter}/stages           the run: stages, current, history
GET  /api/v1/{domain}/matters/{matter}/stages/options    open stages, not the active one
POST /api/v1/{domain}/matters/{matter}/stages/move
```

Reading answers to `*.matters.view`; `options` and `move` to `*.matters.change_stage`, which
`MatterPolicy` has been able to decide since M4.2 and which loses its deferred badge here.

**Snapshotting is guaranteed by three things**, and the third is where it could have gone wrong:
`workflow_version` on the run, the copied `stage_code` and both names on every instance, and
**`matter_stage_instances.workflow_stage_id` being `RESTRICT`** — M4.6's stages cascade from their
template, so `CASCADE` here would chain a template deletion into running Matters' history.

**A move completes the stage you leave and touches nothing else.** Stages jumped over stay
`PENDING`: skipping is a decision somebody makes, not one inferred from a navigation. `SKIPPED` and
`BLOCKED` are therefore vocabulary nothing sets, recorded as a gap. **Still no transition matrix** —
a backward move is ordinary. Matter Status is never written.

**Completing the Matter closes its workflow**, because a stage completes by being moved away from
and the final stage never would. No new endpoint and no new capability.

**Instantiating nothing is the ordinary outcome**: no template is seeded (D-104), so on a fresh
deployment every Matter is created without a workflow rather than refused. Where templates exist,
the tie-break M4.6 required is Service Type first, then generic; `is_default` first, then oldest by
ULID.

**`matters.current_stage_id` is deliberately not built**, resolving the M4.2 and M4.3 deferrals: the
`ACTIVE` instance is the current stage, and a pointer would be a second source of truth.

**M4.1 precedes M4.2** because `matters.service_type_id` references it, even nullably, and a
foreign key cannot point at a table that does not exist.

**M4.2 is schema, Policy, predicates, constraints and architecture tests — not CRUD UI**,
following the M2.1 and M3.1 precedent exactly.

**M4.3 owns the allocator**, and the column and its allocator arrive together, following D-095.

**M4.5 moved the permission count 173 → 177** (section 7), as expected and in the milestone that
gave the four codes routes.

**M4.6 and M4.7 build mechanism only.** Neither may seed workflow content. **Both delivered on
that**: the template tables exist and are empty, the running tables exist and instantiate nothing
without configuration, and tests assert each alongside source scans that keep legal vocabulary out
of the fixtures and keep `SKIPPED` / `BLOCKED` unset anywhere in the product.

---

## 16. Unresolved items

| Question | Status | Blocks M4.1? |
|---|---|---|
| Notary and PPAT workflow content — stages, sequences, approvals, gating | **DOMAIN VALIDATION REQUIRED.** Both workflow documents are placeholders; nothing may be inferred from them | **No** — M4 builds mechanism, not content |
| Which service types the office actually handles | **DOMAIN VALIDATION REQUIRED.** The container ships empty and `service_type_id` is nullable | **No** |
| `represented_by_party_id` semantics | **DOMAIN VALIDATION REQUIRED.** Deferred; not built in M4 | **No** |
| `sequence_no` semantics | Deferred; four plausible meanings, no canonical answer | **No** |
| Participant role catalogue and any cardinality rule | **DOMAIN VALIDATION REQUIRED.** ERD codes are labelled examples | **No** |
| Matter status transition matrix | Not invented. M4 authorizes who may change status, never which changes are legal | **No** |
| Whether a stage transition carries legal state | **OPEN.** Treated as append-only from the outset, which is the safe direction | **No** |
| Matter archive / restore | Refused for M4; no canonical permission exists. Needs its own ruling and its own codes | **No** |
| `notary_matters` / `ppat_matters` extension tables | Deferred to M6 / M7 with their domain content | **No** |
| Whether stage workers need Matter visibility | A new predicate if ever needed; must not silently widen `ASSIGNED` | **No** |
| Project or Matter Office transfer | Refused for M4; requires its own architecture decision | **No** |

None blocks M4.1. Each is recorded rather than quietly assumed.

---

**Status:** `LOCKED — M4.0`
