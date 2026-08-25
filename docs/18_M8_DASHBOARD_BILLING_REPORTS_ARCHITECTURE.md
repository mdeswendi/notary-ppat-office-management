# M8 — Dashboard, Billing & Reports Architecture

**Status:** `LOCKED — M8.0`

Sibling of `12_M2_PARTY_ARCHITECTURE.md` through `17_M7_PPAT_ARCHITECTURE.md`. Where those locked the
Party, Project, Matter, Document, Notary and PPAT aggregates, this one locks the three surfaces that
sit **across** all of them: the Dashboard that summarises them, the Billing that charges for them,
and the Reports that count them.

It records what M8 may build, what it must not, and — as importantly — which of its statements are
transcribed from canonical sources, which are engineering decisions taken here, and which remain
questions **nobody in this repository has the authority to answer.**

**M8 is not M6's and M7's problem again. It is the mirror image of it.** M6 and M7 were milestones
with canonical *tables* and missing *capabilities*: `protocol_records` and `ppat_tax_records` are
defined field by field in the ERD, and the catalogue has no `notary.protocol.*` or `ppat.taxes.*`
code at all (O-036, O-040). Billing is the reverse. It has **seventeen canonical capabilities and no
canonical table whatsoever.** Section 5 is where that bites, and section 9 is where it is resolved.

**M8 is also the last milestone in the plan.** `CLAUDE.md` section 2 and `01_ARCHITECTURE.md`
section 28 both end at M8. There is no M9 to inherit what M8 declines, which makes section 11 and the
open-item ledger materially more consequential here than in any previous lock.

Every ruling below was reviewed and accepted before this document was written. Nothing in it is
inference promoted to fact.

---

## 1. Scope

M8 implements the three cross-cutting surfaces named by `01_ARCHITECTURE.md` section 28:

- the **Dashboard** — a composed answer to the six questions in `CLAUDE.md` section 68;
- **Billing** — Quotations, Invoices, Payments, Disbursements;
- **Reports** — operational, Notary, PPAT, financial, audit.

M8 additionally builds the **audit and activity foundation** that four earlier milestones deferred.
That is not scope creep: `03_DATABASE_ERD.md` section 32 places `activity` and `audit` in migration
batch **7**, which is *behind* the batches M5, M6 and M7 already built, and D-115 holds
`documents.sensitive.download` authorizing nothing until `audit_logs` exists. M8 is where the
backlog is paid, not where a new frontier is opened.

M8 does **not** implement Calendar, Registers, Protocol or Taxes. Section 11 says why, for each.

The sentence this document exists to hold:

> **The Dashboard invents no authority, the Reports invent no numbers, and the Billing invents no
> tax.** Each of the three is a projection of something already decided elsewhere.

---

## 2. Terminology

| Term | Meaning here |
|---|---|
| **Dashboard** | A composed page of panels. Not an entity, not a table, not a capability. |
| **Panel** | One summary on the Dashboard, drawing from exactly one resource the actor can already reach. |
| **Activity** | A system-written timeline row describing that something happened. Infrastructure. |
| **Audit log** | An append-only record of who changed what, per `CLAUDE.md` section 31. |
| **Quotation** | A priced offer to a client, before work is agreed. |
| **Invoice** | A demand for payment, issued to a client. |
| **Payment** | Money received against an Invoice. |
| **Disbursement** | Money the office spends on the client's behalf, recorded for re-billing. |
| **Report** | A read-only aggregation over records the actor can already reach. |

**Activity and Audit are different things and this lock keeps them apart.** An activity row says
*"a document was uploaded to this Matter"* and exists to be read by users on a timeline. An audit row
says *"this actor changed this field from A to B at this IP"* and exists to be read by an auditor.
They have different tables in the ERD (sections 24 and 25), different retention meaning, and
different authorization. Merging them would give the timeline audit's immutability burden and give
audit the timeline's presentation concerns.

---

## 3. What already exists, and what M8 inherits

### 3.1 Permissions — the count stays at 177

**M8 registers no permission.** Every capability the three surfaces need has been canonical since the
catalogue was transcribed at M1.2 and unimplemented ever since. Verified against the live
`PermissionRegistry.php`:

**Billing — seventeen codes** (`02_MENU_AND_PERMISSIONS.md` section 16):

```text
billing.view
billing.amount.view

quotations.view      quotations.create   quotations.update   quotations.approve
invoices.view        invoices.create     invoices.update     invoices.issue      invoices.cancel
payments.view        payments.create     payments.verify
disbursements.view   disbursements.create   disbursements.update
```

**Reports — six codes** (section 17):

```text
reports.operational.view
reports.notary.view
reports.ppat.view
reports.financial.view
reports.audit.view
reports.export
```

**Audit — two codes:**

```text
audit.view
audit.export
```

### 3.2 What the catalogue does *not* contain, verified against the live registry

This list is as load-bearing as the one above.

**There is no `dashboard.*` code of any kind.** Not `dashboard.view`, not a per-panel family. This is
not an oversight to be corrected — it is the design. Section 7.1 makes it a ruling.

**There is no `activity.*` code of any kind.** `activities` is a canonical table (ERD section 24)
with no canonical capability. That is precisely the shape O-036 and O-040 refused for protocol and
taxes — and section 8.3 explains why the same shape resolves differently here, rather than
pretending it is not the same shape.

**There is no `payments.update` and no `*.delete` anywhere in billing.** Fifteen of the seventeen
codes are the four surfaces' verbs, and the set is conspicuously narrower than CRUD:

| Surface | create | update | delete | lifecycle verbs |
|---|---|---|---|---|
| Quotations | yes | yes | **no** | `approve` |
| Invoices | yes | yes | **no** | `issue`, `cancel` |
| Payments | yes | **no** | **no** | `verify` |
| Disbursements | yes | yes | **no** | none |

Section 9.2 reads the lifecycles off this table rather than inventing them, and section 9.5 addresses
the one cell that leaves a genuine dead end.

**There is no `invoices.approve`**, though `quotations.approve` exists. Approval is a quotation act.
An invoice is issued, not approved.

**The `reports.*` family has no `generate` and no `create`.** Every one of its six codes is `.view`
plus one `reports.export`. Reports are read surfaces; nothing in that family authorizes creating a
stored report object. Section 10 holds M8 to that.

**But a second, near-identically named family exists and is not M8's.** The registry carries **both**:

```text
reports.ppat.view        <- M8's.  A cross-cutting read of PPAT activity.
ppat.reports.view        <- NOT M8's.  Part of a five-code PPAT domain workflow:
ppat.reports.generate         generate -> review -> approve -> export
ppat.reports.review
ppat.reports.approve
ppat.reports.export
```

`reports.ppat.view` and `ppat.reports.view` differ only in the order of two words. **They are
different capabilities and M8 implements exactly one of them.** `ppat.reports.*` carries a generate,
a review and an approve — a production-and-sign-off workflow, which is the PPAT **monthly reporting
obligation**: open question five in `09_PPAT_WORKFLOW.md`, whose deadline, recipient and format
nobody in this repository has authored (O-043, still open). Section 10 and section 11.2 keep it out.

### 3.3 What M0–M7 give M8

Everything the Dashboard and Reports count already exists and is already scoped:

```text
Party, Individual, Company        M2
Project, ProjectParty             M3
Matter, MatterWorkflow, stages    M4
Document, DocumentVersion, Task   M5
NotaryMatter, NotaryDeed, Minuta  M6
Property, PpatDeed, PpatWarkah    M7
```

Each carries `office_id`, each has a visibility class implementing its Data Scope predicates, and
each has a Policy. M8 adds no new reach — it composes existing reach. That is the whole of
section 7.1.

### 3.4 What does *not* exist, contrary to a common assumption

**There is no `Activity` model and no `activities` table.** There is no `audit_logs` table. There is
no `AuditLog` model. No `quotations`, `invoices`, `invoice_items`, `payments` or `disbursements`
table or model exists. `backend/app/Models/` holds 35 models and none of them is any of these.

This is stated explicitly because an activity feed is the most natural thing to assume is already
present by M8 — seven milestones of work have happened and none of it was recorded to a timeline.
It was not. **Any M8 plan that treats the activity feed as an existing data source to read from is
planning against a table that has never been created**, and section 8.4 records the consequence
that follows for what the feed shows on its first day.

---

## 4. Canonical ordering, transcribed

`03_DATABASE_ERD.md` section 32, verbatim on the two batches that matter:

```text
7.  tasks, calendar, activity, audit
11. registers, protocol, taxes, billing, advanced reporting
```

and the section's closing instruction:

> *"Do not create all future tables prematurely if the milestone does not require them."*

Three readings follow, and M8 takes all three.

**Batch 7 is overdue, not premature.** M5 built `tasks` and left `calendar`, `activity` and `audit`
where they were. M6 built batch 8 and M7 batches 8 and 10 — both *later* batches. So the ordering
argument that kept audit out of M1 (D-033) and out of M5 (D-115) has been satisfied for three
milestones running and is now the argument *for* building it. M8.1 catches batch 7 up.

**Batch 11 is where M8's own subject lives.** Billing and "advanced reporting" share batch 11 with
registers, protocol and taxes — the three M6 and M7 both declined (O-036, O-040, O-042). Sharing a
batch is not sharing a disposition. Those three were declined because **their domain rules are not
authored anywhere**: what a protocol period is and how it closes, which taxes gate which stage, what
a register's format and period are. Billing has no such gap — an office invoicing its own client is
commerce, not Indonesian notarial procedure, and `CLAUDE.md` section 62's list of things not to
invent (PPAT procedures, Notary approval requirements, required Warkah, deed numbering, **tax rules**,
registration deadlines, legal document requirements) does not include it. Section 9.4 holds the line
between the two.

**"Advanced reporting" is not what M8.3 builds.** M8.3 builds read-only aggregations over tables that
already exist. Whatever "advanced" names — a reporting engine, a warehouse, scheduled generation —
is not in the six canonical report codes, all of which are `.view` plus one `.export`.

---

## 5. The inversion — M8's gap is schema, not domain rules

Every previous lock in this series had the same shape of problem: the tables were canonical and
something else was missing. M8 has the opposite problem, and only for Billing.

| Surface | ERD table | Catalogue codes | What M8 must supply |
|---|---|---|---|
| Dashboard | none needed | **none, by design** | nothing — it composes |
| `activities` | section 24, complete | **none** | a rule for reading it without a code |
| `audit_logs` | section 25, complete | `audit.view`, `audit.export` | nothing — build it |
| `calendar_events` | section 23, complete | five codes | **an owner** — see section 11.1 |
| Quotations | **absent** | four codes | **the entire schema** |
| Invoices | **absent** | five codes | **the entire schema** |
| Payments | **absent** | three codes | **the entire schema** |
| Disbursements | **absent** | three codes | **the entire schema** |
| Reports | none needed | six codes | nothing — it aggregates |

`03_DATABASE_ERD.md` contains no `quotations`, `invoices`, `invoice_items`, `payments` or
`disbursements` section. The only occurrences of the word "payment" in the entire ERD are
`payment_reference` and `payment_date` — two columns inside `ppat_tax_records`, which belongs to the
tax surface O-040 refused and has nothing to do with billing a client. `03_DATABASE_ERD.md`
section 27's numbering table names five sequence codes — `PROJECT`, `NOTARY_MATTER`, `PPAT_MATTER`,
`PROPERTY`, `DOCUMENT` — and no `INVOICE` or `QUOTATION`.

**So M8.2 designs database schema, which no milestone in this project has previously done.** M1
through M7 transcribed field lists from the ERD; where the ERD was silent about a table those
milestones needed, they did not build the table (D-115's four deferred junctions,
`service_document_requirements`, `protocol_records`). M8.2 departs from that, deliberately and once,
and D-124 records the departure with its reasons and its bounds. O-049 asks for the ERD to be
extended to match what M8.2 builds, so that the ERD returns to being the source of truth rather than
staying behind the code.

**The bound that makes the departure safe is that the catalogue is not silent.** It names the four
surfaces, and it names their verbs. Section 9.2 derives every lifecycle from those verbs instead of
from an imagination. What M8.2 designs is the *storage* for behaviour the catalogue already
describes — not the behaviour.

---

## 6. What M8 may build

```text
M8.1  Dashboard + Audit & Activity foundation
M8.2  Billing — Quotations, Invoices, Payments, Disbursements
M8.3  Reports
```

The ordering is forced, not chosen. `reports.audit.view` cannot be built before `audit_logs` exists,
and `reports.financial.view` cannot be built before billing exists. Reports come last because both
of their prerequisites are earlier sub-milestones.

---

## 7. Dashboard

### 7.1 The Dashboard invents no authority

**Ruling.** The Dashboard is not an authorization surface. There is no `dashboard.view` code, none is
registered, and none is needed. Every panel is gated by the capability of the resource it summarises,
resolved through the same `EffectiveAccessResolver` path as that resource's own page:

```text
Panel "Matters in progress"      requires  notary.matters.view or ppat.matters.view
Panel "Documents awaiting"       requires  documents.view
Panel "My tasks"                 requires  tasks.view
Panel "Outstanding invoices"     requires  invoices.view
Panel "Recent activity"          per row, by the subject's own visibility
```

An actor holding none of these sees a Dashboard with no panels. That is correct behaviour, not an
error state, and the page must render it without implying something has gone wrong.

This follows D-091 — every act is its own capability and none implies another — applied to reading.
A summary of Matters is a reading of Matters. Composing several readings onto one page does not
create a new thing to authorize, and it must not become a way to read something the actor could not
read on its own page.

### 7.2 A count is a disclosure, and obeys Data Scope

**Ruling.** Every number on the Dashboard is computed through the same Data Scope predicate as the
list it summarises. An actor whose `notary.matters.view` scope is `ASSIGNED` sees a count of the
Matters assigned to them — never the Office's total.

This is the rule most easily got wrong, because a count feels like less disclosure than a list. It is
not. *"There are 47 Matters in this Office"* is information about 47 records the actor may not
read, and on a small Office a count plus a filter is a reconstruction of the list. Data Scopes are
predicates that union, never a ladder (D-028); a Dashboard aggregate that ignores them would be the
first place in the system where reach was granted by page rather than by predicate.

The same applies to every derived figure — totals, averages, "oldest open item", chart series. If the
underlying query would exclude a row, the aggregate excludes it too.

### 7.3 The Dashboard answers section 68's questions, and adds nothing decorative

`CLAUDE.md` section 68 states the six questions the product exists to answer, and section 57 forbids
*"fake analytics charts simply to fill the dashboard."* The panels exist to answer the six:

```text
Siapa kliennya?                      recent Parties / Projects the actor can reach
Urusannya apa?                       Matters by status
Dokumennya sudah lengkap?            requirement and Warkah completeness
Sekarang prosesnya sampai mana?      Matters by workflow stage
Siapa yang harus mengerjakan?        tasks assigned to the actor
Kapan target atau deadline-nya?      tasks and Matters by due date
```

No panel ships without a question it answers. A chart with no decision attached to it is the
decoration section 57 names.

---

## 8. Activity and Audit

### 8.1 Field lists, transcribed

Both are transcribed verbatim from `03_DATABASE_ERD.md`. Neither is designed here.

**`activities`** — ERD section 24:

```text
id
office_id
actor_user_id
activity_type
subject_type
subject_id
project_id
matter_id
description_key
metadata JSONB
created_at
```

Canonical `activity_type` examples, transcribed:

```text
DOCUMENT_UPLOADED
MATTER_STAGE_CHANGED
TASK_COMPLETED
DEED_APPROVED
```

`description_key` is a translation key, not a rendered sentence — the bilingual rule in `CLAUDE.md`
section 6 applies to the timeline exactly as it applies to everything else. `metadata` carries the
key's interpolation values, and section 8.5 bounds what may go in it.

**`audit_logs`** — ERD section 25:

```text
id
office_id
actor_user_id
event
auditable_type
auditable_id
old_values JSONB
new_values JSONB
ip_address
user_agent
reason
created_at
```

with the ERD's own note, transcribed:

> No: `updated_at`, `deleted_at`. Audit logs are append-only.

**Ruling.** Append-only is enforced structurally, not by convention. The model has no `update` or
`delete` path, no `updated_at`, no soft delete, and `CLAUDE.md` section 31's prohibition on
`audit.update` / `audit.delete` extends to there being no internal method that could perform one.

### 8.2 M8.1 closes D-115

D-115 made three rulings and M8.1 satisfies all three:

- **No half-measure ships.** `audit_logs` is a queryable table with the canonical shape, not an
  application log file.
- **No sensitive-download surface lands before audit exists.** With `audit_logs` built,
  `documents.sensitive.download` may finally be implemented — and it writes an audit row.
- **It never logs the document's contents nor the identifier it is about.** Section 8.5.

`audit.view` and `audit.export` become reachable at M8.1. `reports.audit.view` becomes buildable at
M8.3.

### 8.3 `activities` has no capability, and is read through its subject

`activities` is a canonical table with no canonical capability — structurally the same shape as
`protocol_records` (O-036) and `ppat_tax_records` (O-040), both of which were refused. It resolves
differently here, and the difference must be stated rather than assumed.

**What made protocol and taxes unbuildable was not the missing code by itself.** It was that each
needed a *destination of its own* — a menu entry, a lifecycle, a set of acts — and the catalogue
authorized none of them, so building either meant inventing both a schema and a capability family.
`activities` needs neither. It is infrastructure: written by the system, never by a user, and read
only as a component embedded in a page the actor already reached.

**Ruling.** The activity timeline is authorized per row, by the visibility of its subject. A row
whose `subject_type` is a Matter is visible exactly when that Matter is visible to the actor. A row
whose subject the actor cannot reach does not appear — it is not shown redacted, not shown as
"restricted", simply absent, consistent with D-098's treatment of unreachable records.

**No user act writes an activity row directly, and no endpoint creates one.** There is no
`POST /api/v1/activities`. If that changes — if a user-authored note on a timeline is ever wanted —
that is a new capability and O-047 is where the question is recorded.

### 8.4 Neither table is backfilled

**Ruling.** M8.1 does not manufacture history. Seven milestones of work happened before `activities`
existed and those events were not recorded; inventing rows for them would put fabricated timestamps
and inferred actors into a table whose whole value is that it is a factual record.

The activity feed therefore starts **empty** and fills going forward. The Dashboard's recent-activity
panel shows an empty state on the day M8.1 ships. **This is expected behaviour and must not be read
as a defect**, nor patched by seeding.

The same applies to `audit_logs` with more force: an audit trail containing reconstructed entries is
worse than one that begins on a known date.

### 8.5 What may never be written to either table

`CLAUDE.md` section 32 and D-105's leak-surface rule apply, and D-115 restates them with more force
for audit specifically. Neither table may ever carry:

```text
passwords, session cookies, CSRF tokens, API secrets, authorization headers
full NIK, full NPWP, or any Party sensitive identity value
document file contents, or any extract of them
the identifier a sensitive-document audit row is about
```

`old_values` and `new_values` on an audited change to a masked field record **that the field
changed**, not what it changed from and to. An audit row for a sensitive-document download records
the document's primary key and the actor — never the subject's NIK, never the filename if the
filename carries an identity number.

`metadata` on an activity row carries interpolation values for `description_key` only, and is subject
to the same list.

---

## 9. Billing

### 9.1 A catalogue without a schema

Section 5 established the position: seventeen canonical capabilities, four canonically named menu
destinations (`02_MENU_AND_PERMISSIONS.md` lines 70–74), and no table definition anywhere.

**Ruling (D-124).** M8.2 builds the four surfaces and designs their schema, bounded by three
constraints:

1. **The lifecycle comes from the catalogue's verbs, not from invention** — section 9.2.
2. **Nothing in billing computes, derives, or gates on tax** — section 9.4.
3. **The schema is recorded as designed rather than transcribed, and O-049 asks the ERD to adopt it.**

### 9.2 Lifecycles, read off the verbs

The catalogue's verb set (section 3.2) determines each surface's states. Where there is no verb,
there is no transition — this is derivation, not design.

**Quotation** — verbs `create`, `update`, `approve`:

```text
DRAFT ──approve──> APPROVED
```

There is no `quotations.reject`, no `quotations.send`, no `quotations.cancel`, so there is no
`REJECTED`, `SENT` or `CANCELLED` state. A quotation that comes to nothing stays `DRAFT`.

**Invoice** — verbs `create`, `update`, `issue`, `cancel`:

```text
DRAFT ──issue──> ISSUED ──cancel──> CANCELLED
```

**Payment** — verbs `create`, `verify`:

```text
PENDING ──verify──> VERIFIED
```

**Disbursement** — verbs `create`, `update`, and no lifecycle verb at all. **It therefore has no
`status` column.** Adding one would create vocabulary nothing can reach — the D-109 pattern this
project has repeatedly recorded as a cost rather than repeated as a design.

**Ruling on mutability.** `invoices.update` applies to a `DRAFT` invoice only. Issuing is the
finalization act: an issued invoice has been sent to a client, and `CLAUDE.md` section 64's
discipline for finalized records applies to it — it displays read-only, its values are preserved, and
the only remaining act is `cancel`. The same reasoning makes `quotations.update` a `DRAFT`-only act.

### 9.3 Field lists — designed, not transcribed

**Everything in this section is an engineering decision taken here.** No part of it is transcribed,
because there is nothing to transcribe. Marked as such so that a future reader never mistakes it for
canon the way canonical field lists elsewhere in these locks may be trusted.

Each table carries `office_id` and participates in the composite-key structural invariant used since
M3 — an `office_id` carrier plus `UNIQUE (id, office_id)` support keys — so a billing record can
never reference a Project or Matter from another Office.

```text
quotations
  id (ULID), office_id, project_id, matter_id (nullable), client_party_id,
  quotation_number, status, currency, subtotal_amount, total_amount,
  valid_until, notes,
  approved_by, approved_at,
  created_by, updated_by, created_at, updated_at, deleted_at

quotation_items
  id (ULID), quotation_id, line_number, description, quantity,
  unit_amount, line_amount, created_at, updated_at

invoices
  id (ULID), office_id, project_id, matter_id (nullable), client_party_id,
  quotation_id (nullable), invoice_number, status, currency,
  subtotal_amount, total_amount, due_date, notes,
  issued_by, issued_at, cancelled_by, cancelled_at, cancellation_reason,
  created_by, updated_by, created_at, updated_at, deleted_at

invoice_items
  id (ULID), invoice_id, line_number, description, quantity,
  unit_amount, line_amount, created_at, updated_at

payments
  id (ULID), office_id, invoice_id, status, currency, amount,
  paid_at, method_code, reference, notes,
  verified_by, verified_at,
  created_by, created_at, updated_at

disbursements
  id (ULID), office_id, project_id, matter_id (nullable), currency, amount,
  description, incurred_on, invoice_id (nullable),
  created_by, updated_by, created_at, updated_at, deleted_at
```

Three notes on shape:

**Amounts are integer minor units, never floats.** Indonesian Rupiah has no minor unit in practice,
but the column must not be a binary float in any currency; monetary values use an exact type.

**`client_party_id` points at `parties`,** reusing M2's unified Party rather than duplicating client
identity into billing. No NIK, NPWP or other sensitive identity value is copied into any billing
table — the standing rule across every milestone.

**`payments` has no `deleted_at` and no `updated_by`,** because the catalogue gives it neither a
delete nor an update verb. Section 9.5 addresses what that costs.

### 9.4 The tax boundary — the line that keeps O-040 intact

**Ruling.** Nothing in M8.2 computes, stores as a computed value, or gates on a tax obligation.
There is no `tax_amount` column, no `ppn_rate`, no BPHTB, PPh or PNBP field, and no calculation
anywhere that derives one figure from another by a rate.

An office that must show a tax on an invoice enters it as a line item it names and prices itself.
A typed line is a fact the office asserted; a computed line is a tax rule the software encoded — and
tax rules are named explicitly in `CLAUDE.md` section 62 among the things not to invent, are open
question four in `09_PPAT_WORKFLOW.md`, and are the subject of O-040, which remains open.

**Disbursements are records, not tax.** A disbursement records that the office spent money on the
client's behalf. It does not know whether that money was a tax, a fee or a courier charge, and it
does not gate anything. `ppat_tax_records` remains unbuilt and out of scope; a disbursement is not a
back door to it.

### 9.5 Corrections are additive, and one has no path at all

No billing surface has a delete capability. Corrections are therefore made by adding records, not by
removing them — an invoice is `cancel`led and a new one issued, never edited after issue and never
deleted. This matches the legal-record discipline in `CLAUDE.md` sections 29, 30 and 64, and it is
what a financial record ought to do regardless.

**One case has no remedy in the product, and this lock does not invent one.** A payment recorded with
the wrong amount cannot be updated (`payments.update` does not exist), cannot be deleted (no delete
code exists), and has no reversal verb. Three responses, all of them chosen rather than assumed:

- **The verify gate is the control.** A payment is `PENDING` until an actor holding `payments.verify`
  confirms it, and **only `VERIFIED` payments count toward an invoice's settled total.** A mis-entered
  payment caught before verification affects no figure anywhere.
- **A `PENDING` payment that is wrong stays visible and uncounted.** It is not hidden and not
  silently ignored; the invoice view shows it as unverified.
- **A `VERIFIED` payment recorded wrongly has no product remedy.** M8.2 ships this honestly rather
  than adding an uncatalogued verb, exactly as M7.3 shipped one-way property archiving (O-045).
  O-050 records the gap and what closing it would require.

### 9.6 `billing.amount.view` masks, and is a second gate

`billing.amount.view` is a separate code from `billing.view`. The catalogue does not explain it, but
its shape matches the sensitive-field pattern in `CLAUDE.md` section 22, where reading a record and
reading its protected values are distinct capabilities.

**Ruling.** `billing.view` authorizes seeing that a billing record exists, its number, its status,
its client and its dates. `billing.amount.view` authorizes seeing **every monetary figure** —
`subtotal_amount`, `total_amount`, line `unit_amount` and `line_amount`, payment `amount`,
disbursement `amount`, and every aggregate derived from them, including the Dashboard's outstanding
figures and the financial report's totals.

Masking is applied **server-side in the Resource**, never by hiding a value the API already sent.
A masked amount is absent from the payload, not present-and-hidden — the same rule that governs NIK
and NPWP. An actor without `billing.amount.view` who reaches the financial report sees the report's
counts and not its sums.

### 9.7 Numbering

`quotation_number` and `invoice_number` are internal application references, and
`03_DATABASE_ERD.md` section 27's rules apply: never `MAX + 1`, allocated through the atomic counter
table pattern D-103 and D-108 established.

**Section 27's table names five sequence codes and neither of these is among them,** so the two codes
are added by M8.2 and O-049 asks for section 27 to adopt them. Namespacing follows D-103 — per Office
and per calendar year:

```text
QUOTATION   QUO-{YYYY}-{SEQ:6}   QUO-2026-000001
INVOICE     INV-{YYYY}-{SEQ:6}   INV-2026-000001
```

Section 27's closing warning applies with full force: these are internal references. **Neither is a
legal document number**, neither is a deed number, and neither may be presented as one.

---

## 10. Reports

**Ruling.** Every M8.3 report is a **read-only aggregation over records the requesting actor can
already reach**, computed at request time. No report object is stored, because **no capability in the
`reports.*` family authorizes creating one** — all six codes are `.view` plus one `.export`.

**M8.3 implements the `reports.*` family and not the `ppat.reports.*` family.** Section 3.2 sets out
why the two are easy to confuse and why only one is M8's. Restated because getting it wrong would be
the most consequential mistake available in this milestone: `ppat.reports.generate`, `.review` and
`.approve` describe producing a document and signing it off, which is the PPAT monthly reporting
obligation — O-043, unspecified, and named in `09_PPAT_WORKFLOW.md` §6. **M8.3 builds no endpoint for
any of those five codes.**

The five `reports.*` view codes map to five report families:

```text
reports.operational.view    Matters by status and stage, task load, document completeness
reports.notary.view         Notary deeds and Minuta, over a period
reports.ppat.view           PPAT deeds, properties, Warkah completeness
reports.financial.view      quotations, invoices, payments, disbursements   (M8.2 prerequisite)
reports.audit.view          audit_logs, filtered and paginated               (M8.1 prerequisite)
```

Four rulings bound them:

**Data Scope applies to every row and every total,** exactly as section 7.2 requires of the
Dashboard. A report is a list with arithmetic on it; the arithmetic does not widen the list.

**`reports.export` is a separate capability, and a second gate.** An actor may hold
`reports.financial.view` and not `reports.export`. Export produces the same rows the actor just saw —
never more, never an unscoped variant — and an export of a financial report by an actor without
`billing.amount.view` carries no amounts, per section 9.6.

**A report is one question on one surface** (D-118). Filters, not a tree of nested report routes.

**No report invents a legal figure.** A count of deeds is a count of rows in `notary_deeds`. Nothing
in M8.3 produces a Repertorium, a PPAT monthly report, or a register extract — those have canonical
legal formats nobody in this repository has authored, they are open questions in both workflow
documents, and O-036, O-040 and O-042 all remain open. **A report that looks like a statutory return
is worse than no report**, because it invites being filed as one.

---

## 11. What M8 does not build

### 11.1 Calendar — canonical, complete, and owned by no milestone

`calendar_events` is defined field by field in `03_DATABASE_ERD.md` section 23 with six canonical
event types (`APPOINTMENT`, `SIGNING`, `DEADLINE`, `REMINDER`, `INTERNAL_MEETING`, `OTHER`). Five
capabilities are registered: `calendar.view`, `calendar.view_all`, `calendar.create`,
`calendar.update`, `calendar.delete`. It has a menu destination. It is batch 7, alongside the audit
and activity work M8.1 does build.

**It is nonetheless outside M8**, because M8's subject is Dashboard, Billing and Reports, and
`CLAUDE.md` section 60 forbids implementing a module merely because it appears in the specification.

**But it is owned by no milestone at all.** M5 was "Documents & Tasks" and built tasks. No milestone
between M0 and M8 names Calendar, and M8 is the last. Unlike protocol, taxes and registers — which
are blocked on domain rules nobody has authored — **calendar is blocked on nothing**. It has a
table, a vocabulary, a full capability family and a menu entry, and it has simply never been
assigned. O-048 records this, and it is the cheapest open item in the ledger to close.

### 11.2 Registers, Protocol and Taxes stay outside, for their own reasons

Batch 11 contains five things. M8 owns billing and reporting; the other three keep the disposition
M6 and M7 gave them:

| | Why it stays out |
|---|---|
| Notary / PPAT registers | Format and period unauthored; `ppat.register.delete` absent (O-042) |
| Protocol | `protocol_records` has no status vocabulary and no `notary.protocol.*` code at all (O-036) |
| Taxes | No `ppat.taxes.*` code; tax rules named in `CLAUDE.md` section 62; ERD section 20 requires validation before production (O-040) |
| **`ppat.reports.*`** | A five-code generate → review → approve → export workflow. It is the PPAT **monthly reporting obligation** — deadline, recipient and format all unauthored (O-043). Distinct from `reports.ppat.view`, which M8.3 does build (section 3.2). |

Each needs **both** a domain source **and** a decision about extending a permission catalogue
unchanged since M1.2. M8 makes neither, and section 9.4 keeps billing from becoming a route around
the tax refusal.

### 11.3 What being the last milestone means

M8 is where the plan ends. Everything section 11 declines, and every open item still in the ledger
when M8 closes, has **no later milestone to fall into**. That does not change any ruling above —
scope discipline is not suspended because the calendar is running out — but it changes what the
ledger is for. At M6 and M7 an open item was a deferral. At M8 it is a statement about what the
delivered product does not do, and section 14 should be read that way.

---

## 12. Authorization shape, inherited unchanged

M8 introduces no new authorization mechanism. The chain is what it has been since M1:

```text
Controller::authorize(ability, Model)
    -> Policy
        -> EffectiveAccessResolver->resolve(user, 'code')
            -> grant + effective Data Scope set
                -> visibility predicate on the query
```

Never `$user->can('billing.view')`, never `Gate::allows()`, never `hasPermissionTo()`, never
`getAllPermissions()`, never a role-name check (D-048, D-032). The scan over `app/` enforces it and
M8's code is subject to it.

**Billing records are reached by their own capability, at Office scope.** Two consequences worth
stating because both are easy to get wrong:

- **Reaching a Project confers no authority over its invoices**, and reaching an invoice confers none
  over the Project's Matters. D-100 established that reaching a Project confers no Matter authority;
  the same independence holds across the billing boundary in both directions.
- **404 and 403 keep their D-098 meanings.** A billing record outside the actor's reach is a 404,
  indistinguishable from one that does not exist. A record the actor can reach but may not act on is
  a 403.

Frontend `PermissionGuard` and navigation gating remain UX only. The backend is the boundary, and a
Dashboard panel rendered by mistake must still meet a 403 from the API behind it.

---

## 13. Milestone decomposition

```text
M8.0  Architecture lock                                          <- this document
M8.1  Dashboard + audit & activity foundation
      - activities + audit_logs migrations (transcribed, batch 7)
      - append-only enforcement, no update or delete path
      - activity writer, invoked from existing Actions
      - dashboard panels, each gated by its resource's capability
      - closes D-115; unblocks documents.sensitive.download
M8.2  Billing
      - quotations, quotation_items, invoices, invoice_items,
        payments, disbursements  (designed — section 9.3)
      - QUOTATION and INVOICE sequence codes via the D-103 allocator
      - lifecycles per section 9.2; no tax anywhere; amount masking
M8.3  Reports
      - five report families, read-only, scoped
      - reports.export as a second gate
M8.4  M8 quality gate
```

**M8.1 touches shipped milestones and this is the one place M8 does.** The activity writer must be
invoked from Actions built in M4 through M7. That is bounded: the call is added, no existing
behaviour changes, no existing test expectation changes, and no historical row is manufactured
(section 8.4).

---

## 14. Unresolved items

Carried into M8 and still open: **O-035, O-036, O-038, O-039, O-040, O-041, O-042, O-043, O-045,
O-046.**

Closed by M8: **D-115**, at M8.1, once `audit_logs` exists.

Opened by this lock:

| | |
|---|---|
| **O-047** | `activities` is a canonical table with no canonical capability — the O-036 / O-040 shape. Resolved for M8 by section 8.3 (infrastructure, read through its subject, no user-facing write). Reopens the moment anyone wants a user-authored timeline entry or a standalone activity page, either of which needs an `activity.*` family and therefore the first catalogue extension since M1.2. |
| **O-048** | **Calendar is fully canonical and owned by no milestone.** Table, six event types, five permission codes, menu destination, batch 7 — and no milestone from M0 to M8 names it. Blocked on nothing but assignment. M8 is the last planned milestone, so absent a decision it ships unbuilt. |
| **O-049** | **Billing schema is designed, not transcribed.** `03_DATABASE_ERD.md` defines no billing table, and section 27 names no `INVOICE` or `QUOTATION` sequence code. M8.2 supplies both (sections 9.3, 9.7) under D-124. Closing this means adopting the shipped shape into the ERD so it is canon rather than precedent, or amending it before M8.2 builds. |
| **O-050** | **A verified payment has no correction path.** No `payments.update`, no delete, no reversal verb (section 9.5). Mitigated by the verify gate — unverified payments count toward nothing — but a wrongly verified payment cannot be fixed through the product. Closing this needs a decision about whether the catalogue gains `payments.update` or a reversal capability; the same catalogue-extension question O-036, O-040, O-045 and O-047 all wait on. |

---

## 15. The three sentences

> **The Dashboard invents no authority.** Every panel is gated by the capability of what it
> summarises, and every count obeys the Data Scope of the list beneath it.

> **The Billing invents no tax.** It has seventeen capabilities and no canonical schema, so M8.2
> designs storage for behaviour the catalogue already describes — and stops at the line where
> commerce becomes tax law.

> **The Reports invent no numbers.** They aggregate rows the actor could already read, and nothing
> M8.3 produces may resemble a statutory return.
