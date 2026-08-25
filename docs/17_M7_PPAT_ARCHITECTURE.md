# M7 — PPAT Architecture

**Status:** `LOCKED — M7.0`

Sibling of `12_M2_PARTY_ARCHITECTURE.md` through `16_M6_NOTARY_ARCHITECTURE.md`. Where those locked
the Party, Project, Matter, Document and Notary aggregates, this one locks the **PPAT legal output**:
the land object, the PPAT Deed, and the Warkah that supports it.

It records what M7 may build, what it must not, and — as importantly — which of its statements are
transcribed from canonical sources, which are engineering decisions taken here, and which remain
questions **nobody in this repository has the authority to answer.**

**M7 is M6's problem, one degree worse.** `09_PPAT_WORKFLOW.md` carries **nine** open questions
where `08_NOTARY_WORKFLOW.md` carries seven, and the two extra ones — Warkah composition and the
monthly reporting obligation — sit on top of the same numbering, register and correction gaps that
shaped M6. Section 5 is where that bites.

Every ruling below was reviewed and accepted before this document was written. Nothing in it is
inference promoted to fact.

---

## 1. Scope

M7 implements the **PPAT domain's land object and legal output**: Property with its ownership
history, the Matter extension, the PPAT Deed, and the Warkah.

M7 does **not** implement Notary — that is M6, merged. Nor Billing or Reports, which
`01_ARCHITECTURE.md` section 28 places at **M8**.

The sentence this document exists to hold:

> **A Warkah is a completeness record, not a rule. M7 tracks what has been collected and refuses to
> say what must be.**

That is section 8's ruling, and it is the PPAT-specific half of D-104.

---

## 2. Terminology

Transcribed from `05_I18N_LEGAL_TERMINOLOGY.md` and fixed. These are never translated into
substitutes:

```text
PPAT              retains the Indonesian term
Warkah            Supporting Legal Documents
AJB               Akta Jual Beli               Deed of Sale and Purchase
APHT              Akta Pemberian Hak Tanggungan  Deed of Granting Mortgage
Hibah             (retains the Indonesian term)
Protokol PPAT     PPAT Protocol
```

Right types stay as machine codes and are **not translated in the database**
(`03_DATABASE_ERD.md` section 16 says so explicitly):

```text
HAK_MILIK  HGB  HGU  HAK_PAKAI  STRATA_TITLE  OTHER
```

**A Property is not a Warkah item, and a Warkah is not a Document.** The Property is the land
object; the Warkah is the office's record of *which supporting documents have been collected for a
deed*; each Warkah item points at Documents that live in private storage (M5.1). Three layers, three
tables, and collapsing any two would lose the question the middle one answers.

---

## 3. What already exists, and what M7 inherits

### 3.1 Permissions — the count stays at 177

**Every PPAT code M7 implements is already canonical**, registered since the catalogue was
transcribed at M1.2:

```text
ppat.matters.view      ppat.matters.view_all   ppat.matters.create
ppat.matters.update    ppat.matters.assign     ppat.matters.change_stage
ppat.matters.complete  ppat.matters.cancel
ppat.matters.parties.view   ppat.matters.parties.manage

ppat.deeds.view        ppat.deeds.create       ppat.deeds.update
ppat.deeds.review      ppat.deeds.approve      ppat.deeds.finalize
ppat.deeds.number

properties.view        properties.create       properties.update
properties.archive     properties.ownership.view   properties.ownership.update

ppat.warkah.view       ppat.warkah.upload      ppat.warkah.update
ppat.warkah.verify     ppat.warkah.finalize    ppat.warkah.archive

ppat.register.view     ppat.register.create    ppat.register.update
ppat.register.finalize ppat.register.export

ppat.reports.view      ppat.reports.generate   ppat.reports.review
ppat.reports.approve   ppat.reports.export
```

**M7 therefore registers no permission. The canonical count stays at 177** — as it has since M1.2,
through every milestone from M2 to M6.

### 3.2 What the catalogue does *not* contain, verified against the live registry

```text
ppat.taxes.*             ABSENT — every one of view, manage, create, update
ppat.deeds.delete        ABSENT
ppat.deeds.void          ABSENT
ppat.deeds.lock          ABSENT
ppat.warkah.delete       ABSENT
ppat.register.delete     ABSENT
ppat.protocol.*          ABSENT
properties.delete        ABSENT
properties.ownership.create  ABSENT
```

**The tax family has no capability at all**, and that is the single most consequential finding of
this analysis. `ppat_tax_records` is a canonical table with **no canonical code that could authorize
a single operation on it** — the same shape `notary.protocol.*` had at M6.0 (O-036). Section 10
rules on it.

The three deed-correction codes are absent exactly as their Notary counterparts were: they are the
post-finalization mechanisms `CLAUDE.md` section 29 requires documented business rules for, and
`09_PPAT_WORKFLOW.md` section 6 asks about. **The catalogue's silence and the workflow document's
silence agree with each other**, for the second milestone running.

**M7 builds no act that has no canonical code.** Sections 9 and 10 record what that costs.

### 3.3 `properties.ownership.*` exists, and it matters

Ownership is **its own capability pair**, separate from `properties.view` and `properties.update`.
The catalogue drew that line before anything here implemented it, and M7 honours it: an actor who may
read a Property does not thereby read who owns it, and an actor who may correct an address does not
thereby rewrite a chain of title. Section 7.3.

### 3.4 Structural prerequisites

```text
parties_id_office_id_unique      M2.1
matters_id_office_id_unique      M4.2
documents_id_office_id_unique    M5.1
users_id_office_id_unique        M5.4
notary_deeds_id_office_id_unique M6.3
```

`properties` and `ppat_deeds` will need their own `UNIQUE (id, office_id)` support keys, created **in
the same migration as the table** where a later table references them — the M4.2 construction, not
the M6.3 afterthought. M6.3 needed a separate forward migration precisely because M6.1 had not
anticipated the reference; M7.1 creates the whole batch at once and should not repeat that.

### 3.5 What M2–M6 already give the PPAT domain

- **`matters` with a `domain` discriminator** (M4.2), and `/ppat/matters` already live as a
  route-derived permission namespace (D-101, M4.4).
- **Matter ↔ Party participation** (M4.5). The parties to a PPAT deed are the parties to its Matter.
- **Documents, versions, private storage and relation surfaces** (M5.1–M5.3) — what every Warkah item
  points at.
- **Tasks** (M5.4).
- **The workflow engine** (M4.6, M4.7) — running, and **empty**, per D-104.
- **The whole M6 shape as a worked precedent**: D-120's rulings on deed numbering, unreachable
  vocabulary, capability independence and Data Scope through the parent Matter transfer directly, and
  section 9 says where they do and do not.

### 3.6 One blocked junction becomes unblocked

D-118 recorded `ppat_deed_documents` as blocked because `ppat_deeds` **did not exist**.
`DocumentRelationType` names it as a blocked case so adding it later is *"adding a case and a
migration rather than redesigning the enum."*

**M7 does not build that junction.** It records that the obstacle is gone. The deed's
`final_document_id` and the Warkah's own document links cover what M7 needs, and a general
deed↔document junction is a surface somebody should want before it exists.

---

## 4. Canonical ordering, transcribed

`03_DATABASE_ERD.md` section 32:

```text
8.  properties, ownership, PPAT matter extensions     <- M7
9.  Notary deeds and Minuta                            (M6, merged)
10. PPAT deeds and Warkah                              <- M7
11. registers, protocol, taxes, billing, advanced reporting
```

**M7 is batches 8 and 10. It is not batch 11.**

That single line settles three questions the M7 brief left open, and it settles them the same way
M6.0 section 4 settled registers for Notary:

- **Taxes are batch 11**, not part of the PPAT deed batch.
- **Registers are batch 11**, for PPAT exactly as for Notary.
- **Protocol is batch 11**, and has no permission codes besides.

Building PPAT registers and taxes inside M7 while Notary's registers sit unbuilt outside M6 would
make one domain's milestone reach two batches further than the other's for no stated reason. Sections
10 and 11 hold the line.

The section closes with *"Do not create all future tables prematurely if the milestone does not
require them."*

---

## 5. The questions M7 may not answer

`09_PPAT_WORKFLOW.md` is stamped `DRAFT — DOMAIN VALIDATION REQUIRED` and
`DO NOT IMPLEMENT FROM THIS DOCUMENT YET`. Its section 2 is unusually pointed about why:

> *"PPAT carries statutory obligations around the deed register, monthly reporting, and the binding of
> deeds together with their supporting Warkah. Those obligations are precisely the kind of rule that
> must not be reconstructed from memory."*

Its section 6 lists **nine** open questions. Seven bear directly on what M7 would otherwise build:

| `09_PPAT_WORKFLOW.md` section 6 | M7 disposition |
|---|---|
| *"What is the mandatory Warkah composition per deed type?"* | **Blocked.** Completeness is counted, never judged. See 8.2 |
| *"Which tax obligations gate which stage, and in what order?"* | **Blocked**, and doubly — no capability exists either. See 10 |
| *"What are the deed numbering rules, and who assigns the number?"* | **Blocked.** Office-supplied, no format, no allocator. See 9.3 |
| *"What is the deed register format and its finalization period?"* | **Blocked.** No register table in M7. See 11 |
| *"What is the monthly reporting obligation, deadline, and recipient?"* | **Blocked.** M8, and `ppat.reports.*` stays unimplemented |
| *"What are the binding/archiving requirements for deeds and supporting Warkah?"* | **Blocked.** `archive_location` is free text; no archival lifecycle |
| *"What correction mechanisms are permitted after finalization?"* | **Blocked.** No void, lock or supersede path. See 9.4 |

The remaining two — which service types the office handles, and the stage sequence per deed type —
are D-104's territory and unchanged.

### 5.1 What "blocked" means here, precisely

It does **not** mean the column is absent. Where `03_DATABASE_ERD.md` names a column, M7 creates it,
because the field lists are canonical transcription and a schema matching the ERD is not a legal
claim. It means **no code path reaches it**, no endpoint offers it, and no interface control implies
it exists.

This is the D-109 pattern, applied for the third time — Matter's unreachable statuses, then
`notary_deeds.locked_at` and `notary_minuta.release_status`, now PPAT's equivalents. Section 12
records what lands in that category.

### 5.2 One tax warning is not from the workflow document at all

`03_DATABASE_ERD.md` section 20 closes its own field list with:

> *"Final legal/tax behavior must be validated before production."*

That is the **ERD itself** — the document M7 transcribes from — declining to stand behind tax
behaviour. Two independent canonical sources therefore refuse the same subject, before the missing
capability is even considered.

---

## 6. What M7 may build

Stated affirmatively, so the milestone is not defined only by its refusals:

- **`properties`** and **`property_owners`** — the land object and its chain of title (ERD section 16).
- **`matter_properties`** — the junction the ERD names and the M7 brief omitted.
- **`ppat_matters`** — the Matter extension (ERD section 10).
- **`ppat_deeds`** — ERD section 18, with the dispositions section 9 records.
- **`ppat_warkah`**, **`ppat_warkah_items`**, **`ppat_warkah_documents`** — ERD section 19.
- **The deed lifecycle ladder**, on `CLAUDE.md` section 29's authority — see 9.2.
- **Warkah completeness as an arithmetic fact** — see 8.2.
- **CRUD, Policy, Data Scope, Office boundary, and the frontend** — engineering throughout.

---

## 7. Property

### 7.1 Field list, transcribed

`03_DATABASE_ERD.md` section 16:

```text
id  office_id  property_number  property_type  right_type
certificate_number  certificate_date  land_area  building_area
measurement_letter_number  measurement_letter_date
address  village  district  city  province  postal_code
latitude  longitude  status
created_at  created_by  updated_at  updated_by  deleted_at
```

```text
property_type:  LAND  LAND_AND_BUILDING  APARTMENT_UNIT  OTHER
right_type:     HAK_MILIK  HGB  HGU  HAK_PAKAI  STRATA_TITLE  OTHER
```

**`right_type` is explicitly open-ended.** The ERD says *"Right type may use stable machine codes,
for example"* — *for example*, not *these are the values*. So M7 stores it as a **CHECK-free
`VARCHAR`**, unlike `property_type` whose four values are given as a closed list. Constraining
`right_type` to five codes would assert that Indonesian land law has five, which
`11_LEGAL_REFERENCES.md` is a register of statutes precisely because nobody here may decide.

**`status` has no vocabulary in the ERD.** Same treatment as `notary_minuta.release_status` at M6.3:
the column is created nullable with no default and no CHECK, and nothing writes it, because
inventing `ACTIVE / INACTIVE` would be inventing a lifecycle.

**`property_number` is an internal reference**, not a certificate number — `certificate_number` is
the legal identifier and they are different concepts, exactly as `matter_number` and `deed_number`
are (D-103, D-120). M7.1 decides whether it is allocated (the `PROP-000001` shape `CLAUDE.md`
section 38 names) or office-supplied; **section 14 records that as an open question for M7.1 rather
than settling it here**, because the ERD gives no format and D-103's allocator pattern is about
Office+year namespaces which `PROP-000001` does not obviously carry.

### 7.2 Ownership history

`property_owners`, transcribed:

```text
id  property_id  party_id  ownership_percentage
effective_from  effective_until  is_current  source_matter_id
created_at  updated_at
```

**`is_current` is kept, and D-116 does not apply to it.** M5.1 removed `is_current` from
`document_versions` and replaced it with a pointer, because *"a unique index is a business rule
wearing an index's clothing"* and exactly one version may be current. That reasoning **inverts here**:
a Property legitimately has **several** current owners at once, each with an `ownership_percentage`.
`is_current` on `property_owners` is a *"this row applies now"* flag on many rows, not a *"this is the
one"* pointer on one — a different construct that happens to share a name.

What it does share is the denormalization hazard: `is_current` is derivable from `effective_until`,
so the two can disagree. M7.1 keeps the column because the ERD names it and writes both together in
one transaction, the way M5.4 and M6.1 handled every other paired field.

**No percentage sum is enforced.** Whether shares must total 100 is a rule about Indonesian
co-ownership, and `CLAUDE.md` section 62 forbids inventing it. The column stores what the office
records.

**`source_matter_id` is the transfer that produced this row** — the audit trail the ownership history
exists for, and the reason `CLAUDE.md` section 63 gives for never overwriting history: a change of
ownership adds a row and closes the previous one.

### 7.3 Ownership is its own capability

`properties.ownership.view` and `properties.ownership.update` are separate canonical codes from
`properties.view` and `properties.update` (section 3.3). M7 honours the split: reading a Property
does not read its chain of title, and correcting an address does not rewrite ownership. The D-091
discipline, and here it protects something a land office would genuinely separate.

**There is no `properties.ownership.create`** — verified absent. Adding an owner is an `update` to
the chain, which is the reading the catalogue's two codes support.

### 7.4 `matter_properties`

The ERD names it in section 16 and the M7 brief omitted it:

```text
id  matter_id  property_id  role_code  created_at
```

```text
role_code:  TRANSACTION_OBJECT  COLLATERAL  RELATED_PROPERTY   (examples)
```

**`role_code` is `VARCHAR` with no CHECK**, for the reason `right_type` is: the ERD says *"Example
role codes"*.

This junction is what makes a Property reachable from a Matter, and it carries `office_id` as a
constraint carrier so a Matter cannot name a Property in another Office — the construction every
junction since D-080 uses.

---

## 8. Warkah

### 8.1 Field lists, transcribed

`03_DATABASE_ERD.md` section 19:

```text
ppat_warkah
  id  ppat_deed_id  status  completeness_percentage
  verified_at  verified_by  finalized_at  finalized_by
  archive_location  notes  created_at  updated_at

ppat_warkah_items
  id  warkah_id  requirement_code  title_id  title_en
  party_id  status  sequence_no  notes  created_at  updated_at

ppat_warkah_documents
  warkah_item_id  document_id  attached_at  attached_by
```

```text
ppat_warkah.status:  INCOMPLETE  UNDER_REVIEW  COMPLETE  FINALIZED  ARCHIVED
```

**Warkah status has a canonical vocabulary**, unlike `notary_minuta.release_status` which had none.
That is a real difference and M7 uses it: the five values are storable and CHECK-constrained.

**`ppat_warkah_documents` has no `id`** — a composite primary key `(warkah_item_id, document_id)`,
the shape every document junction has used since M5.1.

**None of the three tables carries `office_id`.** All three need one as a composite-key carrier, added
as a recorded extension exactly as `notary_matters` and `notary_minuta` did at M6.

**`title_id` and `title_en` are bilingual database fields**, which `CLAUDE.md` section 10 permits for
business data — the same pattern `service_types` uses. They are *not* UI strings and must not move to
the message files.

### 8.2 Completeness is counted, never judged

This is the ruling section 1 names, and it is where M7 differs most from what a brief would assume.

**`completeness_percentage` is a stored column, and M7 stores it.** The M7 brief proposed making
completeness a computed property instead. The ERD names the column, so transcription keeps it — but
what M7 refuses is different and more important than where the number lives.

**M7 counts items. It does not decide which items must exist.**

> *"What is the mandatory Warkah composition per deed type?"* — `09_PPAT_WORKFLOW.md` section 6

A percentage is only meaningful against a denominator, and the denominator is the mandatory
composition **per deed type** that nobody has authored. So:

- `completeness_percentage` is **derived from the items the office actually created** — collected over
  total, both of them rows in `ppat_warkah_items` — and recomputed whenever items change.
- **No requirement template drives it.** `requirement_code` is stored and matched against nothing:
  `service_document_requirements` and `matter_requirements` are unbuilt (D-104, M5 lock section 9),
  and `ppat_warkah_items.requirement_code` would be the third place to invent a catalogue.
- **100% does not mean complete in law.** It means every item this office listed has a document. The
  interface must say so rather than implying legal sufficiency, because a Warkah that is arithmetically
  full and legally short is exactly the failure this refusal prevents.

**Status is settable and not gated.** `INCOMPLETE → UNDER_REVIEW → COMPLETE` answer to
`ppat.warkah.update` and `ppat.warkah.verify`; **`FINALIZED` and `ARCHIVED` are not built in M7** —
`ppat.warkah.finalize` and `ppat.warkah.archive` stay registered and unimplemented, because *"what are
the binding/archiving requirements for deeds and supporting Warkah?"* is open question eight. The two
codes sit exactly where `notary.minuta.archive` and `notary.minuta.release` sit (D-064).

**No completeness percentage gates any deed act.** Finalizing a PPAT deed with an empty Warkah is
permitted by the software, because *which* Warkah must be complete *before what* is open questions
three and eight together. An office that requires it enforces it as practice until somebody writes it
down.

### 8.3 One Warkah per Deed

`UNIQUE (ppat_deed_id)`, the M6.3 ruling for Minuta applied to the same shape of record: a Warkah is
the supporting bundle *of one deed*. The ERD states no cardinality; this is the conservative
engineering choice, and a second bundle is a rule to state rather than an index to drop.

---

## 9. `ppat_deeds`

### 9.1 Field list, transcribed

`03_DATABASE_ERD.md` section 18:

```text
id  office_id  matter_id  deed_number  deed_date  deed_type_code  title  status
final_document_id
reviewed_at  reviewed_by  approved_at  approved_by
finalized_at  finalized_by  locked_at
created_at  updated_at
```

```text
deed_type_code (possible):  AJB  APHT  HIBAH  TUKAR_MENUKAR
                            PEMBAGIAN_HAK_BERSAMA  OTHER
```

**One document pointer, not three.** `notary_deeds` carries `draft_document_id`,
`final_document_id` and `minuta_document_id`; `ppat_deeds` carries **only `final_document_id`**. That
is not an omission to correct — PPAT's supporting material is the Warkah, which is its own table with
its own document links. Adding a `draft_document_id` here by analogy with Notary would be extending
the canonical field list on this milestone's authority.

**`deed_type_code` is `VARCHAR` with no CHECK**, and the six codes above are *"possible"* — the ERD's
word. AJB and APHT are fixed legal terminology (`05_I18N_LEGAL_TERMINOLOGY.md`) and will be what an
office types, but constraining the column to six would assert PPAT has six deed types.

**`deleted_at` is absent from the canonical list and M7 adds none** — the M6.1 ruling, on the same
four agreeing sources: the ERD omits it, section 33 prefers states over destructive deletion for
finalized legal records, `CLAUDE.md` section 30 forbids user-facing hard delete of Deeds, and no
`ppat.deeds.delete` capability exists.

**`locked_by` is absent and M7 adds none**, exactly as M6.1 ruled for `notary_deeds`.

### 9.2 The lifecycle — a decision, not a transcription

**`ppat_deeds` has no status vocabulary in the ERD.** `notary_deeds` lists six values;
`ppat_deeds` lists none. This must be stated plainly because it changes what kind of statement M7 is
making.

M7 adopts the same six-value vocabulary and the same reachable ladder:

```text
create    ->  DRAFT
review    DRAFT         ->  UNDER_REVIEW    ppat.deeds.review
approve   UNDER_REVIEW  ->  APPROVED        ppat.deeds.approve
finalize  APPROVED      ->  FINALIZED       ppat.deeds.finalize

VOID        no path, no capability
SUPERSEDED  no path, no capability
```

**The ladder is not invented, but adopting it here is a decision rather than transcription.**
`CLAUDE.md` section 29 states `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED → LOCKED` as the
legal-record lifecycle generally, and section 64 states its consequence. That constitution-level
statement is what authorizes the four reachable transitions — the same authority M6 used. What is
*additionally* decided here is that PPAT uses **the same six-value vocabulary as Notary** rather than a
shorter one, so the two domains' deed records answer the same question the same way. A future
milestone that finds a canonical PPAT status list must reconcile with this rather than assume it was
transcribed.

**`FINALIZED` is read-only**, per sections 29 and 64.

### 9.3 Deed numbering — the shape, without the rule

**Identical to D-120's ruling for Notary, and for identical reasons.** `deed_number` is nullable,
unique per Office where present, supplied by the office, validated against **no format**, and written
through **`ppat.deeds.number`** — its own canonical capability — on its own endpoint.

*"What are the deed numbering rules, and who assigns the number?"* is open question five, and
`CLAUDE.md` section 62 names deed numbering rules explicitly. D-103 separately ruled that
`P-YYYY-NNNNNN` is *"an operational identifier, never a legal deed number"*, so the M4 allocator is
not reused.

**Finalizing assigns no number and creates no register entry**, for the reasons M6.2 gave and section
11 restates.

### 9.4 Data Scope

```text
OWN       the parent Matter's created_by  = actor id
ASSIGNED  the parent Matter's pic_user_id = actor id
OFFICE    ppat_deeds.office_id            = actor office
ALL       cross-office reach
TEAM      no grant (D-042)
```

**Identical to D-120's ruling for `notary_deeds`**, including the argument for why resolving through
the parent is *not* the D-100 hazard: the Matter supplies the **predicate**, never the **grant**.
Holding `ppat.matters.view` at any scope reaches no deed, and holding every deed code reaches no
Matter.

**Property has its own predicates and does not inherit the Matter's.** A Property is a shared
office-owned record like a Party or a Service Type — it exists before any Matter references it and
outlives every one of them. So `properties.*` gets `OFFICE` and `ALL` only, following the Party
(D-080) and Service Type (D-106) answer rather than the Project (D-088) one: `OWN` would have to mean
`created_by`, and the colleague who typed in a land parcel has no claim on it; `ASSIGNED` has no
assignment entity.

---

## 10. Taxes — not built in M7

`03_DATABASE_ERD.md` section 20 defines `ppat_tax_records`. **M7 does not build it**, on four
independent grounds, any one of which would be sufficient:

1. **No capability exists.** `ppat.taxes.view`, `.manage`, `.create` and `.update` are all **absent**
   from the canonical catalogue — verified against the live registry. There is no code that could
   authorize reading or writing a tax record, and M7 registers none. This is `notary.protocol.*` again
   (O-036), and the answer is the same.
2. **Taxes are batch 11**, not batch 10 (section 4).
3. **The ERD itself declines to stand behind the behaviour**: *"Final legal/tax behavior must be
   validated before production."*
4. ***"Which tax obligations gate which stage, and in what order?"*** is open question four.

**`CLAUDE.md` section 62 names tax rules explicitly** among the things not to invent.

One transcription note for whoever does build it: **`ppat_tax_records.matter_id`, not
`ppat_deed_id`.** Tax obligations attach to the transaction, not to the instrument recording it — a
distinction the M7 brief inverted, and one that would have put the table under the wrong parent.

---

## 11. Registers and protocol — not built in M7

`ppat_register_entries` (ERD section 21) and `protocol_records` (section 22) are **batch 11**, and M7
is batches 8 and 10.

Three reasons, unchanged from M6.0 sections 9 and 10:

1. **The canonical ordering puts them a batch later.**
2. ***"What is the deed register format and its finalization period?"*** is open question six. A
   register is not a list of rows; it is a legally-prescribed book with rules about what enters it, in
   what order, and when it closes.
3. **`ppat.register.delete` does not exist**, and `ppat.protocol.*` does not exist at all.

**Nothing in M7 writes a register entry, and no deed action creates one.** The M7 brief proposed that
finalizing a deed create a register entry when `requires_register_entry` is true — the same proposal
M6 refused for Notary, and refused again at M6.3. `ppat_matters.requires_register_entry` is stored and
branches on nothing, exactly as its Notary counterpart does.

**`protocol_records` remains one table with a `NOTARY | PPAT` discriminator and no junction to
deeds** (O-036). M7 does not make it Notary-specific or PPAT-specific by building half of it.

---

## 12. Stored vocabulary with no code path

The section 5.1 category, enumerated so a test can assert it:

```text
ppat_deeds.status        VOID, SUPERSEDED     no capability, no path
ppat_deeds.locked_at     column present       nothing writes it
ppat_warkah.status       FINALIZED, ARCHIVED  codes exist, unimplemented
ppat_warkah.finalized_at / finalized_by       nothing writes the pair
properties.status        no vocabulary at all nothing writes it
ppat_matters.requires_register_entry          stored, branches on nothing
ppat_matters.tax_processing_required          stored, branches on nothing
ppat_matters.registration_required            stored, branches on nothing
```

`ppat_matters`' three flags are `03_DATABASE_ERD.md` section 10's field list, and the ERD's own note
at line 770 calls them *"domain-semantic and unvalidated"* — the same words that kept them out of M4
and let M6 persist Notary's equivalents at M6.1. **Persisting a flag is not the same act as branching
on it.**

---

## 13. Authorization shape, inherited unchanged

```text
Controller::authorize(...)  ->  Policy  ->  EffectiveAccessResolver  ->  Data Scope
```

No permission-code authorization as backend authority, no role-name checks, no `SUPER_ADMIN` bypass
(D-048, D-032, D-041). Data Scopes are **predicates, never a ladder**; multiple grants **union**
(D-028); unknown or missing scope metadata **fails closed** (D-039).

**The route decides the permission namespace** (D-101). PPAT deeds live under `/ppat/deeds`, following
`/notary/deeds`.

**Every act gets its own capability, and none implies another** (D-091). `ppat.warkah.upload` does not
reach `verify`; `verify` does not reach `finalize`; `ppat.deeds.approve` does not reach `finalize`.

**Frontend gates are presentation only** (D-113).

**Sensitive identity stays masked.** NIK and NPWP are never copied into Property, deed, Warkah or
junction tables, into query keys, URLs, or logs — the standing rule since M2. This matters more in
PPAT than anywhere: a land transaction payload is exactly where identity would leak by accident.

**Sections, not tabs.** The repository has no `Tabs` primitive and M7 does not add one — the ruling
M5.2, M5.3, M5.4, M6.2 and M6.3 each followed.

---

## 14. Milestone decomposition

```text
M7.0   PPAT architecture lock                          <- this document
M7.1   Property + PPAT schema + Policy                   (no routes, like M5.1 and M6.1)   <- done
M7.2   PPAT Deed surface + deed frontend                 (nine routes, no DELETE)          <- done
M7.3   Property surface + ownership history + frontend
M7.4   Warkah surface + completeness + frontend
```

**M7.1 is schema, Policy and Data Scope — not CRUD UI**, following M2.1, M3.1, M4.1, M4.2, M5.1 and
M6.1. It creates, in one batch: `properties`, `property_owners`, `matter_properties`, `ppat_matters`,
`ppat_deeds`, `ppat_warkah`, `ppat_warkah_items`, `ppat_warkah_documents` — **eight tables**, plus the
`(id, office_id)` support keys on `properties` and `ppat_deeds` **in the same migrations that create
them**, so M7 does not repeat M6.3's separate-migration correction.

**M7.2 ships the frontend with the endpoints**, following M5.2, M5.4 and M6.2. It landed **nine
routes** — index, store, show, update, options, review, approve, finalize, number — one for each of
the seven canonical `ppat.deeds.*` capabilities plus `options`. **No `DELETE`, no `/void`, no
`/lock`**: those three codes are absent from the catalogue, `ppat_deeds` has no `deleted_at`, and a
deed recorded in error is a correction mechanism, which is open question nine (§5, O-039).

It also added the **Deeds** navigation entry and no other. Property and Warkah have capabilities and
tables from M7.1 and no routes, so a placeholder entry for either would link to a 404 — D-064, the
ruling `notary.deeds` followed when it stayed absent through M6.1. M7.3 and M7.4 add theirs (O-044).

**M7.3 before M7.4** deliberately: a Warkah item may name a `party_id` and a deed names a Property
through `matter_properties`, so the Property surface should exist before the Warkah surface leans on
it.

**There is no M7.5.** Taxes, registers, protocol and reports are outside M7 (sections 10 and 11), not
deferred within it.

**Project detail gains a PPAT Deeds section at M7.2**, following the O-037 pattern exactly: a
`project_id` filter on `GET /ppat/deeds` correlated through the Matter, **not** a nested
`/projects/{project}/ppat-deeds` route — the shape D-118 refused and O-037 confirmed.

**No milestone in M7 seeds content.** No service types, no deed type catalogue, no Warkah requirement
templates, no workflow stages, no right-type catalogue.

---

## 15. Unresolved items

| Question | Status | Blocks M7.1? |
|---|---|---|
| Mandatory Warkah composition per deed type | **OPEN — §6.** Completeness counts what the office listed; no template drives it (O-041) | **No** |
| Tax obligations, gating and calculation | **OPEN — §6, and no capability exists.** `ppat_tax_records` unbuilt (O-040) | **No** |
| Deed numbering rules, and who assigns the number | **OPEN — §6.** Office-supplied through `ppat.deeds.number`, no format validated | **No** |
| Deed register format and finalization period | **OPEN — §6.** Batch 11; nothing in M7 writes an entry (O-042) | **No** |
| Monthly reporting obligation, deadline, recipient | **OPEN — §6.** M8; `ppat.reports.*` stays unimplemented (O-043) | **No** |
| Binding and archiving of deeds with their Warkah | **OPEN — §6.** `ppat.warkah.finalize` and `.archive` stay unimplemented (O-041) | **No** |
| Correction mechanisms after finalization | **OPEN — §6 and `CLAUDE.md` §29.** `VOID`, `SUPERSEDED`, `locked_at` are stored vocabulary with no path | **No** |
| Whether `property_number` is allocated or office-supplied | **OPEN, and M7.1 must resolve it explicitly.** The ERD gives no format; `CLAUDE.md` §38 names `PROP-000001` as an example internal reference, but D-103's allocator is namespaced by Office **and year** and a Property is not a yearly thing. Recorded so M7.1 meets it as a decision rather than a surprise | **No** — it is M7.1's own first question |
| Whether ownership percentages must total 100 | **OPEN.** A rule about Indonesian co-ownership; the column stores what the office records | **No** |
| `ppat_deed_documents` junction | **UNBLOCKED but not built** (§3.6) | **No** |
| Protocol: table shape, lifecycle, four missing codes | **OPEN, and outside M7** (§11, O-036) | **No** |
| Audit | **OPEN since M5** (D-115). M7 adds no sensitive-download surface and does not lift the gate | **No** |

---

**Status:** `LOCKED — M7.0`
