# Notary & PPAT Office Management System
## Documentation Changelog

Records specification changes and milestone results.

---

## 2026-08-25 — M7.3 Property, ownership history and frontend

Branch `feat/m7-ppat`, from `55b9655`. **Twelve routes, no migration, no permission — the count
stays at 177.** Implements D-121; no new decision. Two new open items, **O-045** and **O-046**, and
half of **O-044** closes.

The land object becomes reachable, and with it the chain of title: five Actions plus two junction
Actions, one Exception, five Form Requests, two Resources, three Controllers, twelve routes, and the
frontend that reads them — types, service, eight components, four pages, a navigation entry and both
locales.

### `properties.archive` is the soft delete, and that answers M7.1's open question

M7.1 left it explicitly: *"`properties.archive` is the canonical capability; what it does is M7.3's
question."* Two canonical facts constrain the answer and only one reading satisfies both.

`03_DATABASE_ERD.md` §16 gives `properties` a **`deleted_at`**, unlike either deed table. The
catalogue gives `properties.archive` and **withholds `properties.delete`** — checked against the live
registry, the same check that ruled out `ppat.deeds.delete` at M7.2.

Read separately each is dead: a soft-delete column no capability reaches, and a capability with
nothing to do. Read together they are one mechanism. So `PATCH /{property}/archive` writes
`deleted_at` and **never `status`** — that column has no vocabulary in the ERD at all, and
`ACTIVE`/`ARCHIVED` would be a lifecycle nobody defined (D-121 §12). There is no `DELETE` route.

It destroys nothing: every junction row and every link in the chain survives, and the parcel stays
readable through `?archived=1`. It is refused while a Matter that has not finished names the parcel —
a product guard about data hygiene, stated as such, which clears by itself. **It cannot be undone**:
there is no `properties.restore` (O-045).

### Co-ownership: the brief and the M7 lock disagreed, and the lock won

The brief specified *"`addOwner` — set `is_current` = true, update yang lama"* and, in its
constraints, *"hanya satu owner yang bisa `is_current` = true per property."* The M7 lock §7.2 rules
that out **by name**:

> *"a Property legitimately has **several** current owners at once, each with an
> `ownership_percentage`. `is_current` on `property_owners` is a 'this row applies now' flag on many
> rows, not a 'this is the one' pointer on one."*

The migration says it, the model says it, and an M7.1 test asserts two current owners at 50% each.
Closing the previous holders on every insert would make co-ownership unrepresentable — and
co-ownership is ordinary for Indonesian land.

So there are two acts and the caller says which: **`supersedes_current`**, defaulting to `false`
because that is the choice which ends nobody's recorded ownership. The form offers the radio pair
only when there are current holders to supersede. The smoke asserts both paths.

**No sum is validated.** 0–100 per link is arithmetic; whether shares must total 100 is a rule about
Indonesian co-ownership `CLAUDE.md` §62 forbids inventing. A total of 160% is stored, displayed and
not judged — the interface says so in words rather than leaving it implied.

### There is no way to delete a link in a chain of title

The brief asked for `DELETE /properties/{property}/owners/{owner}`, described as a *"soft delete
ownership"*. **`property_owners` has no `deleted_at`** — the ERD's field list gives it nine columns
and none is one, so M7.1 added no `SoftDeletes`. A `DELETE` could only be hard, and hard-deleting a
link destroys exactly the history the table exists to keep (§§30 and 63).

Ending an ownership is **closing the link**: `PATCH` with an `effective_until`, which clears
`is_current` in the same save so the flag and the date cannot disagree. The control says "close", not
"remove". A row entered by mistake is a correction mechanism — the same open question with no answer
for deeds either (O-039).

### `property_number` is office-supplied, which settles the M7 lock's last M7.1 question

The lock recorded *"whether `property_number` is allocated or office-supplied"* as a question
somebody had to settle explicitly. **The office supplies it**, on three grounds: the ERD gives the
column no format; `CLAUDE.md` §38 shows `PROP-000001` **without a year**, alone among the internal
references it lists, so D-108's Office+year counter does not fit; and an allocator needs a counter
table, which is a migration this milestone was scoped without.

Required at creation, unique **within the Office** (D-103), immutable afterwards, and **no format
validated** — the shape `ppat.deeds.number` has. The smoke records `kavling blok C/7` successfully.

### Two vocabularies, two controls, and the ERD's own wording is the difference

`property_type` is a `<select>` and a database CHECK: four values given flat, no hedging word.
`right_type` is a **text input with a `datalist`**: the ERD says *"Right type **may** use stable
machine codes, **for example**"*, so the six codes are typeahead suggestions and `HAK_ULAYAT` is
accepted. A `<select>` would assert that Indonesian land law has six kinds of right.
`matter_properties.role_code` gets the same treatment for the same reason.

Neither is translated. `PropertyTypeBadge` uses message keys because a closed list of stable codes is
what those are for; `RightTypeBadge` renders verbatim, like `deed_type_code` on both deed surfaces.

### Ownership is its own surface because it is its own capability

`properties.ownership.view` and `.update` are separate canonical codes, so the chain lives on
`/properties/{property}/owners` rather than as fields on the parcel. Reading a Property does not read
who owns it: `current_owners` arrives **`null`** for a caller without the code — not `[]`, because
"not shown to you" and "nobody owns it" are different statements. A clerk maintaining addresses is
not the person who records a transfer, and the catalogue drew that line before anything implemented
it.

There is no `properties.ownership.create` — adding a link is an `update` to the chain, which is what
the two codes support.

### Two things the brief asked for that do not exist to be built

**`document_count` and a Documents section.** `property_documents` does not exist —
`DocumentRelationType` carries `party`, `project` and `matter` only and names it *"blocked — batch 8,
M7"*. Building it is *"adding a case and a migration"*, and M7.3 was scoped without one. A count of
zero would be a lie about a table with no rows because it has none (O-046).

**A Timeline section and an activity log.** No audit store exists; D-115 rules it required, absent,
and not to be improvised, and five milestones have now declined to invent one. What the record
preserves — creator, last editor, and the whole chain of title — is shown.

### Structure notes

Three departures from the brief's proposed shapes, each following established repository convention:
controllers live in `app/Http/Controllers/Api/V1/` and Form Requests in `app/Http/Requests/Ppat/`,
not a new `app/Domains/PPAT/Controllers/`; **`PATCH`, not `PUT`**, which the repository reserves for
full replacement; and **sections, not tabs**, since no `Tabs` primitive exists — the ruling every
milestone since M5.2 has made.

**The API root is `/api/v1/properties` and the page is `/ppat/properties`**, deliberately. D-101 says
the route decides the permission namespace and the canonical family is `properties.*` with no `ppat.`
prefix, so a `/ppat/properties` endpoint would name a namespace that does not exist; `CLAUDE.md` §16
lists Property among the PPAT-specific concepts, which is where the page and the menu entry belong. A
page path is not a permission namespace. Recorded in three places so nobody "fixes" one to match the
other.

### Two capabilities for the Matter junction, because there is no third

**No `*.matters.properties.*` code and no `properties.matters.*` code exist.** So attaching is
`ppat.matters.update` — the junction row is Matter composition, saying which parcel this work is
about — while the target is resolved through canonical `properties.view` visibility first, so
composing a Matter never becomes a way to discover which Properties exist. **PPAT only**: there is no
`/notary/matters/{matter}/properties`, and the smoke confirms it 404s.

### Five guard tests narrowed, not deleted

`MatterAuthorizationTest` forbade any `DELETE` on a URI containing `matters`; the junction detach is
the same shape M4.5's participation delete already was, so `/properties/` joins `/parties/` in the
exclusion and the surviving claim — no address deletes a Matter — is unchanged.
`MatterManagementTest`'s route inventory excludes the junction, which has its own suite.
`CompanyRegistryStatusTest`, `CompanyRelationshipRegistryTest` and `ProjectLifecycleTest` each
forbade the bare segment `properties`; each now forbids the **rooted direction** instead
(`companies/{company}/properties`, `projects/{project}/properties`), which is the boundary all three
were always about. `warkah` stays forbidden outright in all three.

One production change came out of the same sweep: **`PropertyVisibility::permits()` now uses
`withTrashed()`**. Reach is a question about Office and archived is a question about record state;
folding them together made an archived parcel answer 403 instead of opening read-only, which would
have made archiving equal to hiding.

### Verification

Backend `pint --test` clean; full suite green — **2765 passing, 8 skipped**, of which **66 are new
Property tests**. Frontend `format:check`, `lint` (0 errors, 3 pre-existing warnings), `typecheck`,
`test` (**171, 22 new**) and `build` all pass.

PostgreSQL HTTP smoke on a disposable `m73_probe` at 50 migrations, real Sanctum cookie sessions with
CSRF cookie, `X-XSRF-TOKEN`, `Origin` and `Referer` and no Bearer authentication anywhere:
**74/74**. The serving process proved its own database with `SELECT current_database()` before the
first functional request (O-034). Three actors: fully capable, view-only, and one holding the parcel
capabilities **without** the ownership pair — which is how the catalogue's split was measured rather
than assumed.

Confirmed end to end: two owners current at once with a 100% total; a 160% total stored and reported;
a transfer closing the previous holders while leaving their party and share intact; the property
number free-format and unique per Office; `status` and `office_id` refused on presence; `APARTMENT`
refused where `APARTMENT_UNIT` is accepted; archive blocked by a running Matter and permitted after
detaching; an archived parcel readable, filterable and refusing every write with 403; `DELETE` on a
parcel and on a link both 405; `/restore`, `/ppat/warkah`, `/properties/{id}/documents`,
`/notary/matters/{id}/properties` and `/projects/{id}/properties` all 404; no NIK or NPWP anywhere in
the payload; anonymous 401.

The persistent development database was not touched: 42 migrations before and after, with
`properties` confirmed absent from it. Probe dropped afterwards.

---

## 2026-08-25 — M7.2 PPAT Deed surface and frontend

Branch `feat/m7-ppat`, from `0d04c07`. **Nine routes, no migration, no permission — the count stays
at 177.** Implements D-121; no new decision. One new open item, **O-044**.

The PPAT deed becomes reachable: six Actions, one Exception, three Form Requests, one Resource, one
Controller, nine routes, and the frontend that reads them — types, service, seven components, three
pages, a navigation entry and both locales.

### The `destroy` endpoint the brief asked for does not exist, by the brief's own condition

The brief said *"`destroy` hanya jika permission `ppat.deeds.delete` ada di registry. Jika tidak,
jangan buat."* It is not there. The canonical catalogue of 177 codes has no `ppat.deeds.delete`, no
`.void` and no `.lock` — checked directly against the registry, not inferred.

Three further sources agree separately: `ppat_deeds` has no `deleted_at` column (M7.1, matching the
ERD), `03_DATABASE_ERD.md` §33 prefers states over destructive deletion for finalized legal records,
and `CLAUDE.md` §30 forbids user-facing hard delete of Deeds. A deed recorded in error is a
**correction mechanism**, which is open question nine (O-039).

`DELETE /api/v1/ppat/deeds/{id}` answers **405**; `/void` and `/lock` answer **404**. All three are
pinned by tests and by the HTTP smoke.

### `approve` is a capability, never a role name

The brief specified *"approve hanya untuk PRINCIPAL/SUPER_ADMIN"* and, in the same sentence, *"melalui
permission `ppat.deeds.approve`, bukan role-name check (D-032)"*. The second half is what shipped: no
role name appears anywhere in the domain, the controller, the Policy or the frontend. Restricting
approval to the Principal is an office's grant of that one capability through the Permission Matrix —
configuration, not code.

### Finalize does four things it was asked to do and refuses three

It sets `FINALIZED` and stamps `finalized_at` / `finalized_by`, inside a transaction (`CLAUDE.md`
§37) even though it touches one row, so the milestone that adds register allocation inherits the
boundary rather than introducing one.

It does **not** assign a deed number — that is `ppat.deeds.number` on its own endpoint, and folding
it in would answer *"who assigns the number, and when?"* (open question five). It does **not** create
a register entry: `ppat_register_entries` does not exist and the register format is open question six
(O-042). It does **not** touch taxes: no table, and **no capability at all** (O-040). It does **not**
write `locked_at`, which stays canonical vocabulary nothing reaches.

The smoke asserts both refusals directly — a finalized deed comes back with `deed_number: null` and
`locked_at: null`.

### `project_id` is a filter, correlated through the Matter

The O-037 shape, second application. A deed has no `project_id`; the filter reaches it through
`whereHas('matter')`. **Not** `GET /projects/{project}/ppat-deeds` — D-118 refused that shape for
this exact question, and Documents, Tasks and Notarial Deeds all answer the Project page the same
way. A test and the smoke both confirm the nested address 404s.

It needs no extra authorization because a filter only narrows: every row is already bounded by
`ppat.deeds.view` and its Data Scope before the filter runs.

### One document pointer, not three

`ppat_deeds` carries `final_document_id` alone. `draft_document_id` and `minuta_document_id` are
`prohibited` in both Form Requests and absent from the Resource — a PPAT deed's supporting material
is the **Warkah**, a separate aggregate answering to its own family of six `ppat.warkah.*` codes.
M7.4 builds that surface. Nothing here implies it exists.

### No Property and no Warkah navigation entry — D-064 over the brief

The brief asked for both as placeholders pointing at `/ppat/properties` and `/ppat/warkah`. Neither
route exists, and an entry whose route does not exist offers somebody a link to a 404. Every
milestone since M5.2 has waited for the routes, including `notary.deeds`, which stayed absent through
M6.1 and appeared at M6.2. The **Deeds** entry is the only one added; M7.3 and M7.4 add theirs
(O-044).

### Three guard tests narrowed, not deleted

`NotaryDeedManagementTest` asserted `/api/v1/ppat/deeds` answered 404 because *"PPAT deeds are a
different table in a different milestone"* — a claim about the calendar. It now asserts the boundary
it was really for: a caller holding `notary.deeds.view` gets **403** from the PPAT surface, and the
PPAT file carries the mirror.

`navigation.test.ts` had two: *"carries Matters and nothing else in the PPAT group"* (narrowed once
at M6.2, again here) and *"offers no PPAT counterpart"*, whose stated reason — that the catalogue had
no `ppat.deeds.view` — was wrong even when written. Seven `ppat.deeds.*` codes have been canonical
since M1.2; what was missing was the route.

### Verification

Backend `pint --test` clean; full suite green. Frontend `format:check`, `lint` (0 errors, 3
pre-existing warnings), `typecheck`, `test` and `build` all pass — **149 tests, 22 new**.

PostgreSQL HTTP smoke on a disposable `m72_probe` at 50 migrations, real Sanctum cookie sessions with
CSRF cookie, `X-XSRF-TOKEN`, `Origin` and `Referer` and no Bearer authentication anywhere: **37/37**.
The serving process proved its own database with `SELECT current_database()` before the first
functional request (O-034). Two actors: fully capable and view-only. The full lifecycle walks
`DRAFT → UNDER_REVIEW → APPROVED → FINALIZED`; out-of-order acts answer 422 and uncapable ones 403;
a Notary Matter and a nonexistent one are refused identically; numbering works in every status
including finalized, refuses a number another deed holds, and permits re-recording a deed's own;
`/ppat/warkah`, `/ppat/properties` and `/ppat/deeds/{id}/minuta` all 404; the payload carries no NIK
or NPWP; anonymous gets 401.

**One expectation in the smoke was wrong and the code was right**: editing a finalized deed answers
**403, not 422**, because `PpatDeedPolicy::update()` checks `isReadOnly()` before the capability, so
authorization denies before the Action's guard is reached. `NotaryDeedPolicy` is identical and the
Pest suite already asserted the same 403.

The persistent development database was not touched: 42 migrations before and after, and
`ppat_deeds` confirmed absent from it. Probe dropped afterwards.

---

## 2026-08-25 — M7.1 PPAT schema and Policy

Branch `feat/m7-ppat`, from `aa0c251`. **Eight migrations (42 → 50), no route, no permission — the
count stays at 177.** Implements D-121; no new decision.

Schema, Policy and Data Scope only — no CRUD UI, following M2.1, M3.1, M4.1, M4.2, M5.1 and M6.1.

### What landed

| | |
|---|---|
| `properties` | 25 columns, the land object |
| `property_owners` | the chain of title |
| `matter_properties` | the junction the M7 brief omitted and the lock restored |
| `ppat_matters` | the Matter extension |
| `ppat_deeds` | 18 columns, **one** document pointer |
| `ppat_warkah` | the supporting bundle |
| `ppat_warkah_items` | its lines |
| `ppat_warkah_documents` | composite PK, no surrogate `id` |

Plus seven models, **three** enums, two visibility classes, two Policies, five factories, and 95
tests. Every `(id, office_id)` support key is created **in the migration that creates its table**,
so M7 does not repeat M6.3's separate-migration correction.

### Two brief items would have failed at runtime

**`$table->check()` does not exist** in Laravel's Blueprint — verified against the vendor source. The
CHECK constraints are raw `ALTER TABLE` statements guarded on the `pgsql` driver, as M5.4 and M6.1
do.

**`nullOnDelete()` on a composite foreign key nulls `office_id`**, which is `NOT NULL` — the M5.4
finding. The brief used it on eleven composite keys. Every foreign key here is `RESTRICT`, except two
deliberate `CASCADE`s where a row has no meaning apart from its parent.

### Three enums, not the seven the brief specified

The other four have **no canonical vocabulary**, and inventing one is what `CLAUDE.md` §62 forbids:

| Proposed | Reality |
|---|---|
| `RightType` | ERD says *"**may** use stable machine codes, **for example**"* → plain `VARCHAR`, no CHECK |
| `PropertyStatus` | ERD names the column and gives it **no values** → nullable, no default, nothing writes it |
| `PpatDeedType` | ERD says *"**Possible** deed codes"* → no CHECK |
| `PpatWarkahItemStatus` | ERD gives the column **no values** → and an item-status vocabulary *is* the verification rule (open question three) |

`PropertyType` **is** constrained, because its four values are given as a closed list — the
difference is in the ERD's own wording. It is also `APARTMENT_UNIT`, not the brief's `APARTMENT`: a
stable machine code is only stable if copied exactly.

### Completeness counts documents, never statuses

The consequence of having no item-status vocabulary, and the ruling the M7 lock exists to hold.
`PpatWarkah::computeCompleteness()` counts **items with at least one document attached, over items
the office created** — observable, needing no vocabulary. An empty Warkah is **0%, not 100%**.

`recalculateCompleteness()` writes only the percentage, **never the status**: 100% does not imply
`COMPLETE` and `COMPLETE` does not require 100%, because which governs legal sufficiency is open
question three. A test pins it by setting item statuses to `REJECTED` and `VERIFIED` and showing the
count does not move.

### `is_current` is kept, and D-116 does not apply

A Property legitimately has several current owners at once, so it is a *"this row applies now"* flag
on many rows rather than the *"this is the one"* pointer D-116 removed from `document_versions`.
**There is deliberately no unique index on `(property_id, is_current)`** — one would break
co-ownership, and a test asserts two current owners at 50% each.

**No percentage sum is enforced** (a rule about Indonesian co-ownership), but the arithmetic is: 0–100
per row, periods run forwards, and a row that has ended cannot also be current. All three are
PostgreSQL CHECKs **and** model guards, because the suite runs SQLite.

### Property gets two scopes, not four

`OFFICE` and `ALL` only — the Party (D-080) and Service Type (D-106) answer, not Project's (D-088). A
Property is office-owned reference data: it predates every Matter that names it. `OWN` would have to
mean `created_by`, and the colleague who typed in a parcel has no claim on it.

`PropertyVisibility` carries an explicit warning against the tempting addition: a `whereHas('matters')`
branch would make `ppat.matters.view` a silent superset of `properties.view`. A test asserts a
`ppat.matters.view` holder at `ALL` still reaches no Property.

**Ownership is its own capability pair.** `properties.ownership.view` / `.update` are separate
canonical codes, so reading a Property does not read its chain of title.

### Four guard tests narrowed, not deleted

`MatterSchemaTest`, `PartySchemaTest`, `ProjectPartySchemaTest` and `ProjectSchemaTest` each asserted
that an M7 table did not exist. Each keeps the claim that outlives this milestone: **no column on
`matters`, `parties`, `projects` or `project_parties` stands in for a later-milestone table.** Three
of the four lists are now empty and gone — every table they named exists and carries its own schema
test.

### Verification

Backend `pint --test` clean, **95 PPAT tests**, full suite green.

PostgreSQL probe on a disposable `m71_probe` at 50 migrations: **32/32**. All eight tables migrate; 13
CHECK constraints present and **correctly none** on `right_type`, `properties.status`,
`deed_type_code` or `warkah_items.status`; raw SQL past the enum cast confirms `ppat_deeds.status`
stores all six canonical values and refuses `PENDING`, `LOCKED` and `draft`; `property_type` refuses
both an unlisted code and the brief's `APARTMENT` spelling; the four `property_owners` CHECKs refuse
what they should while permitting several current owners and shares over 100; all four support keys
present. Five tables confirmed absent: `ppat_tax_records`, `ppat_register_entries`,
`protocol_records`, `ppat_deed_documents`, `audit_logs`.

Probe dropped afterwards.

---

## 2026-08-25 — M7.0 PPAT architecture lock

Branch `feat/m7-ppat`, from `1f1dab5`. **No code, no migration, no permission — the count stays at
177.** One new decision, **D-121**, and five new open items, **O-039** … **O-043**.

`docs/17_M7_PPAT_ARCHITECTURE.md` is the sixth `.0` lock and the second in a row whose subject is a
specification that is deliberately empty.

### M7 is M6's problem one degree worse

`09_PPAT_WORKFLOW.md` section 6 carries **nine** open questions where `08_NOTARY_WORKFLOW.md` carries
seven, and **seven of the nine** bear on what M7 would otherwise build. Its section 2 says why in its
own words: PPAT's statutory obligations around the register, monthly reporting and the binding of
deeds with their Warkah are *"precisely the kind of rule that must not be reconstructed from memory."*

### The finding that changed the scope

**`ppat.taxes.*` does not exist — not one code of it.** Verified against the live registry:
`view`, `manage`, `create` and `update` are all absent, while every other PPAT family has been
catalogued since M1.2. `ppat_tax_records` is a canonical table with **no canonical capability that
could authorize a single operation on it** — the shape `notary.protocol.*` had at M6.0.

Three further grounds agree: taxes are **batch 11** (M7 is batches 8 and 10), tax gating is open
question four, and `03_DATABASE_ERD.md` §20 closes its own field list with *"Final legal/tax behavior
must be validated before production."* **The tax surface is outside M7** (O-040).

One transcription note recorded for whoever does build it: `ppat_tax_records` hangs off **`matter_id`,
not `ppat_deed_id`** — the brief inverted this, which would have put the table under the wrong parent.

### One decision, not the seven the brief pre-wrote

D-121 consolidates, following D-120's precedent — and pointedly, because **three of the seven
proposed decisions state things that turned out not to be true.** Recording them separately would have
enshrined the errors:

* **D-121 as briefed** said the PPAT deed ladder *"follows the Notary pattern."* But `ppat_deeds` has
  **no status vocabulary in the ERD** while `notary_deeds` has six. M7 adopts the same six — on
  `CLAUDE.md` §29's authority — but that is a **decision, not a transcription**, and the lock says so.
* **D-122 as briefed** would have made completeness computed and dropped `completeness_percentage`, a
  canonical column. The real issue is the denominator: see below.
* **D-127 as briefed** — a register entry created on finalization — is exactly what M6 refused twice,
  and directly answers open question six.

### Completeness is counted, never judged

The ruling the lock exists to hold. `ppat_warkah.completeness_percentage` is stored, but **a
percentage is meaningless without a denominator, and the denominator is the mandatory Warkah
composition per deed type that nobody has authored** (open question three).

So the number counts the items the office itself created; no requirement template drives it;
`requirement_code` is stored and matched against nothing; and **100% means every item this office
listed has a document, not that the Warkah is legally sufficient.** No completeness figure gates any
deed act (O-041).

### `is_current` is kept, and D-116 does not apply

M5.1 removed `is_current` from `document_versions` because exactly one version may be current. That
**inverts** for `property_owners`: a Property legitimately has several current owners at once, each
with a percentage. It is a *"this row applies now"* flag on many rows, not a *"this is the one"*
pointer on one — a different construct sharing a name. Worth stating so nobody "fixes" it later.

**No percentage sum is enforced** — whether shares must total 100 is a rule about Indonesian
co-ownership.

### Also settled

`matter_properties` is built (the ERD names it; the brief omitted it). `ppat_deeds` carries **one**
document pointer, not Notary's three — PPAT's supporting material is the Warkah. `properties.right_type`
and `matter_properties.role_code` stay CHECK-free because the ERD says *"may use"* and *"example"*,
while `property_type` is constrained because its four values are a closed list. Property gets
`OFFICE` and `ALL` only — the Party and Service Type answer, not Project's. Deed reach resolves
through the parent Matter, as D-120 ruled for Notary.

### Decomposition

```text
M7.0  PPAT architecture lock                      <- this
M7.1  Property + PPAT schema + Policy   (eight tables, no routes)
M7.2  PPAT Deed surface + deed frontend
M7.3  Property surface + ownership history + frontend
M7.4  Warkah surface + completeness + frontend
```

There is no M7.5: taxes, registers, protocol and reports are outside M7, not deferred within it. The
`(id, office_id)` support keys are created **in the migrations that create their tables**, so M7 does
not repeat M6.3's separate-migration correction. Project detail gains its PPAT Deeds section at M7.2
through a `project_id` filter, following O-037 rather than the nested route D-118 refused.

### One question left for M7.1 to answer explicitly

Whether `property_number` is allocated or office-supplied. The ERD gives no format; `CLAUDE.md` §38
names `PROP-000001` as an example internal reference; but D-103's allocator is namespaced by Office
**and calendar year**, and a land parcel is not a yearly thing. Recorded in the lock's §15 so M7.1
meets it as a decision rather than a surprise — the same shape as the `created_by` question M5.0 left
for M5.4.

---

## 2026-08-25 — O-037: Notary Deeds on the Project page

Branch `fix/o-037-project-deeds`, from `cc56a4f`. **One filter, one section, no migration, no
permission.** Backend **2552 passed + 8 skipped** (7 new), frontend **127 passed** (was 121).
Closes **O-037**; **O-038 stays open**.

### Built as a filter, not the nested route the brief specified

The follow-up brief asked for `GET /api/v1/projects/{project}/notary-deeds`. That is the shape
**D-118 refused** for exactly this question:

> *"No `GET /{entity}/{id}/documents`. That question is already answered by
> `GET /documents?project_id=…`. A second address for one question is two surfaces that must be
> kept in step, and the first divergence between them would be a bug."*

Documents and Tasks already answer the Project page through `?project_id=`, and every existing
section on that page is a `filter` against the entity's own top-level endpoint. So
`GET /notary/deeds` gained `project_id` instead — correlated through `matter_id`, because a deed
carries no Project of its own.

A test asserts no `projects/{project}/…deeds` route exists, so the refused shape cannot reappear
quietly.

### The filter needs no second capability, and that is deliberate

Every row is bounded by `notary.deeds.view` and its Data Scope **before** the filter runs, so
naming a Project the caller cannot open returns the deeds they could already see — never one
more. Requiring `projects.view` would refuse a legitimate narrowing rather than protect anything,
and `matter_id` has always worked the same way. A test pins it: an `OWN`-scoped actor filtering
by a shared Project still sees only their own.

### The grandchildren objection, answered rather than dropped

O-037 recorded a real design question: a Project holds Matters and Matters hold Deeds, so this is
the **one surface in the product that reaches two levels down**. It earns the exception because
*"what has this engagement actually produced?"* is a question about the Project, and answering it
by opening each Matter in turn is the thing a summary exists to avoid.

Because rows span several Matters, `DeedsList` gained an optional `matterOptions` prop — adding a
Matter column and a Matter dropdown — rather than being duplicated. The deeds page and the Matter
section leave it undefined: on a single-Matter view a column repeating the parent and a dropdown
that cannot change anything are both noise.

### Also

`ProjectDetail`'s class docblock said the page *"deliberately has no participants, Matter,
workflow, document, or deed section"*. Three of those five have been built since M3.3. Corrected,
keeping the part that is still true — no Matter and no workflow section, because a Matter is
reached at its own domain root (D-101).

**No PPAT deeds can appear here.** `notary_deeds` rows exist only against NOTARY Matters, so a
Project running both domains shows only its Notary output. PPAT deeds are a different table in M7.

---

## 2026-08-24 — M6.3 Minuta Akta metadata

Branch `feat/m6-notary`, from `8c638d4`. **Two migrations (40 → 42), three routes, no permission —
the count stays at 177.** Backend **2545 passed + 8 skipped** (49 new), frontend **121 passed** (was
114). Implements D-120; no new decision.

### Two migrations, not one

The brief expected 40 → 41. `notary_minuta (notary_deed_id, office_id) -> notary_deeds (id, office_id)`
needs a unique index on **exactly** those columns, and `notary_deeds` did not carry one — M6.1
correctly declined to build a support key no table referenced. So
`add_notary_deed_office_support_key` lands first, its own forward migration following the
`add_user_office_support_key` precedent from M5.4. It rejects nothing that was previously accepted.

### `release_status` is created and no vocabulary is asserted

The ERD names the column and gives it **no values at all**. The `DRAFT / ARCHIVED / RELEASED` triple
the brief specified appears in no canonical document, and *"What triggers Minuta Akta archiving, and
what release conditions apply?"* is open question four.

So the column is **nullable, with no default and no CHECK** — verified on PostgreSQL. Defaulting it to
`DRAFT` would assert a vocabulary; constraining it to three values would assert the whole lifecycle.
`archived_at` and `archived_by` are the same: canonical, unwritten, and kept honest by a pair CHECK so
a later milestone cannot write half an archival. All three are refused on presence by both Form
Requests, and a test asserts no minuta status enum exists anywhere.

`notary.minuta.archive` and `notary.minuta.release` stay registered and unimplemented (D-064).

### Three more things the brief asked for that this does not do

**No `DELETE`.** The catalogue defines `view`, `create`, `update`, `archive` and `release` and **no
`notary.minuta.delete`** — verified against the live registry — and `notary_minuta` has no
`deleted_at`, the ERD omitting it. The brief asked for a soft delete restricted to `DRAFT`, needing
both. A Minuta filed against the wrong deed is corrected by replacing `document_id`, which is exactly
what `update` is for.

**No top-level `/notary/minuta/{minuta}` address.** The brief proposed `PUT` and `DELETE` there; the
routes are nested under the deed instead, following D-105's explicit convention that no address
reaches a row without naming the parent it belongs to. A Minuta has no independent existence — one per
deed — so `GET /notary/deeds/{deed}/minuta` is **one record or 404**, never a collection.

**`status` is `release_status`**, and `notes` was restored: both transcribed from the ERD rather than
from the brief's field list.

### What it does do

`office_id` added as the composite-key carrier (the M6.0 ruling). `UNIQUE (notary_deed_id)` — one
Minuta per deed, because the term carries the cardinality. Three composite foreign keys so the deed,
the Document and any future archiver all belong to the Minuta's own Office. `document_id` is the one
mutable pointer, because replacing a bad scan is ordinary correction and both Documents keep their
version histories (D-116).

**The Document is re-resolved, never trusted.** `notary.minuta.create` is authority to record a
filing, never authority to discover which Documents exist — an unreachable Document, one in another
Office and one that does not exist all answer alike (the D-118 two-question rule applied to a column
rather than a junction).

**`notary.minuta.*` is its own capability family.** Reading a deed does not confer reading where its
original is filed, and the reverse holds too — both asserted.

### Frontend

`MinutaSection` on the deed detail page — a section, not a tab, for the fifth milestone running. **A
404 is rendered as the ordinary empty state**, not a failure: the endpoint answers one record or
nothing, and "nothing filed yet" is what most deeds look like.

The document picker is a **selection control, not `EntityDocumentPicker`** — that component commits an
attach to the junction endpoint on choice (M5.3), whereas here the chosen id is a column submitted
with the rest of the form. The candidate list is the same one either way, already bounded by
`documents.view`.

`release_status` and `archived_at` render as *unset* rather than being hidden, so a reader can see the
fields exist and are empty rather than wondering whether they were dropped.

### Three guard tests narrowed, one extended

`NotaryDeedSchemaTest` asserted `notary_minuta` did not exist — narrowed to keep the claim that
outlives this milestone: registers and protocol are batch 11 and outside M6 entirely.
`NotaryDeedManagementTest`'s route-name guard fired on the three new names and was extended to include
them, with the two families' missing `delete` codes still absent. `deed-detail.test.tsx` needed its
service mock extended, since `DeedDetail` now renders a section that asks its own endpoint.

### Verification

Backend `pint --test` clean, 2545 passed + 8 skipped. Frontend `format:check`, `lint` (0 errors, 3
pre-existing warnings), `typecheck`, 121 tests and `build` all clean.

PostgreSQL probe on a disposable `m63_probe` at **42 migrations**: six foreign keys, the archival pair
CHECK, the deed unique index, the new support key on `notary_deeds`, and **`release_status` confirmed
nullable with no default and no CHECK**.

**HTTP smoke — 20/20**, over a real Sanctum cookie session with CSRF cookie, `X-XSRF-TOKEN`, `Origin`
and `Referer`, and no Bearer authentication. Per O-034 the serving process proved its own database
before the first functional request. Covered: 404 before filing; filing with shelf metadata; a second
filing refused 422; `release_status`, `archived_at` and `notary_deed_id` each refused 422 on both write
paths; a Document replaced and a shelf field edited alone without disturbing it; cross-office and
nonexistent Documents refused alike; `DELETE` 405, `/archive` and `/release` 404, top-level
`/notary/minuta` 404.

Probe dropped; **the persistent development database was not touched and remains at 22 migrations**,
re-verified afterwards.

---

## 2026-08-24 — M6.2 Notary Deed surface and frontend

Branch `feat/m6-notary`, from `33dfe32`. **Nine routes, no migration, no permission — the count stays
at 177.** Backend **2496 passed + 8 skipped** (44 new), frontend **114 passed** (was 100). Implements
D-120; no new decision.

### What landed

Backend: `NotaryDeedController` (9 endpoints), six Actions, `DeedStatusNotEligible`, three Form
Requests, `NotaryDeedResource`.

Frontend: `types/notary.ts`, `services/notary.ts`, five components, three pages, the Matter deeds
section, a navigation entry, and 71 translation keys per locale with verified parity.

### Five things the brief asked for that this does not do

Each was refused for a reason already recorded, and each refusal is asserted by a test.

**No `DELETE`.** The brief asked for a soft delete restricted to `DRAFT`, *and* forbade both a
migration and a new permission. Its own constraints rule the endpoint out: `notary_deeds` has no
`deleted_at` (M6.1) and the catalogue has no `notary.deeds.delete`. Four canonical sources agree
separately (D-120).

**`approve` is not restricted to a role name.** *"Hanya PRINCIPAL/SUPER_ADMIN"* is the authorization
shape D-032, D-041 and D-048 forbid. Restricting approval to the Principal is done by granting
`notary.deeds.approve` to that role alone — office configuration, not a check in code. A test asserts
a `SUPER_ADMIN` role name confers nothing.

**Finalizing assigns no number.** *"Set deed_number jika belum"* asserts *when* a deed is numbered,
which is half of open question one. Numbering is `notary.deeds.number` on its own endpoint — the
capability the catalogue defined and nothing had used. Finalizing also writes no `locked_at` and
creates no register entry.

**An approved deed is still editable.** The brief wanted edits confined to `DRAFT` and `UNDER_REVIEW`.
`CLAUDE.md` §29 denies normal updates *once finalized* and says nothing about approval; the narrower
rule is an approval requirement, which §62 forbids inventing. Encoded as M6.1's `isEditable()` had it.

**No parties, tasks or document collection in the deed payload.** Participation answers to
`notary.matters.parties.view`, tasks to `tasks.view`, documents to `documents.view` — each with its
own Data Scope. Embedding any of them would make `notary.deeds.view` a way to read it.

Also: **sections, not tabs** — the repository has no `Tabs` primitive, the ruling M5.2, M5.3 and M5.4
each made. And **no audit or `Activity` placeholder**: D-115 forbids it, and writing deed events into
`task_comments` would have been worse than not recording them.

### Four guard tests narrowed, and one of them is the interesting case

`CompanyRegistryStatusTest`, `CompanyRelationshipRegistryTest` and `ProjectLifecycleTest` each
forbade a bare `deeds` route segment. Each was narrowed to the rooted direction it was really about —
`companies/{company}/deeds`, `projects/{project}/deeds` — exactly as `documents` was narrowed at M5.2.

**`ProjectReferenceTest` is the one worth reading.** It forbade any route URI containing `number`, on
the grounds that a Project reference is system-allocated and immutable so *"there is nothing for a
caller to send"*. M6.2 ships `notary/deeds/{deed}/number`, and the guard fired **correctly on a route
that is correct**: D-103 already ruled that `PRJ-2026-000001` is *"ordinary office identification …
not a deed number, a repertorium number, a minuta or Warkah number"*. A deed number is
caller-supplied precisely because it is not system-allocated. `number` is now scoped to Project's own
addresses; `reference`, `sequence` and `counter` stay forbidden everywhere.

### Verification

Backend `pint --test` clean, 2496 passed + 8 skipped. Frontend `format:check`, `lint` (0 errors, 3
pre-existing warnings), `typecheck`, 114 tests and `build` all clean.

**HTTP smoke — 29/29 against real PostgreSQL**, on a disposable `m62_probe` at 40 migrations, over a
real Sanctum cookie session with CSRF cookie, `X-XSRF-TOKEN`, `Origin` and `Referer`, and **no Bearer
authentication anywhere**. Per O-034 the serving process proved its own database with
`SELECT current_database()` before the first functional request, launched via `php -S` with the
framework's front controller rather than `artisan serve`.

Covered: the full `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED` ladder; approving before review
refused 422; editing a finalized deed refused 403; a PPAT Matter, a `deed_number` and a `status` each
refused 422 at creation; a duplicate number refused and a re-recorded one accepted; `DELETE` 405,
`/void` 404, `/ppat/deeds` 404; and the payload carrying no parties, tasks, documents or identity.

**Two smoke assertions failed before the code did anything wrong.** `?? 'x'` was used as a
present-key sentinel, and `??` coalesces on a **null value** as well as a missing key — so a
legitimately-null `deed_number` defeated its own check and reported "finalize assigned a number".
Printing the payload settled it: the API had returned `"deed_number": null` and `"locked_at": null`
throughout. The assertion was fixed; nothing in the product changed.

**One behaviour confirmed rather than assumed.** The feature suite runs on SQLite, where `LIKE` is
case-insensitive, so the deed search could plausibly have behaved differently on PostgreSQL. It does
not: Laravel's `whereLike` defaults to case-insensitive and compiles to `ILIKE`, and a lowercase
search matched both mixed-case titles on the probe.

Probe dropped; **the persistent development database was not touched and remains at 22 migrations**,
re-verified afterwards.

---

## 2026-08-24 — M6.1 Notary Deed schema and Policy

Branch `feat/m6-notary`, from `bec5dd5`. **Two migrations (38 → 40), no route, no permission — the
count stays at 177.** Backend **2452 passed + 8 skipped** (92 of them new). Implements D-120; no new
decision.

Schema, Policy and Data Scope only — no CRUD UI, following M2.1, M3.1, M4.1, M4.2 and M5.1.

### What landed

| | |
|---|---|
| `2026_08_26_090000` | `notary_matters` — the Matter extension, keyed by `matter_id` |
| `2026_08_26_090001` | `notary_deeds` — 20 columns, 12 foreign keys, 4 PostgreSQL CHECKs |

Plus `NotaryDeedStatus`, `NotaryDeed`, `NotaryMatter`, `NotaryDeedVisibility`, `NotaryDeedPolicy`,
two factories, a `NOTARY_DEED_DOMAIN` scope entry, and `Matter::notaryExtension()` /
`Matter::notaryDeeds()`.

**No support key was added** — `matters`, `documents` and `users` have carried theirs since M4.2, M5.1
and M5.4. The first milestone since M2 for which that is true.

### A Deed's reach is its Matter's reach

A deed carries no `created_by` and no `pic_user_id`, so `OWN` and `ASSIGNED` resolve through the parent
Matter via a correlated `EXISTS`. This looks like the thing `MatterVisibility` forbids and is its
opposite: **the Matter supplies the predicate, never the grant.** Holding `notary.matters.view` at
`ALL` still reaches no deed, and holding every deed code at `ALL` still reaches no Matter — D-100 in
both directions, and both are asserted.

### Three omissions and one addition, each recorded

* **`locked_by`** — the ERD carries `locked_at` alone, and adding an actor would assert somebody
  performs a locking act, which is an open domain question. *(Contrast M5.4's `created_by`, which was
  added because the `OWN` predicate structurally required an owner.)*
* **`deleted_at` and soft delete** — four canonical sources agree they should not exist.
* **`created_by`** — the `OWN` predicate has somewhere else to go.
* **`office_id` on `notary_matters`** — added as the composite-key carrier, absent from the canonical
  field list, recorded rather than made quietly.

### `deed_number` without a numbering rule

Nullable, unique per Office where present, supplied by the office, **no format validated and no
allocator built**. Set through `notary.deeds.number` — the capability the catalogue had defined and
nothing had used. A test asserts no allocator class or counter table exists, because D-103 already
ruled `N-YYYY-NNNNNN` is *"an operational identifier, never a legal deed number"*.

### Two guard tests narrowed, not deleted

`MatterSchemaTest` and `ProjectSchemaTest` both asserted `notary_matters` did not exist — correct for
M4 and M3, and correct only for `ppat_matters` now. Each was narrowed with a note on why, and each
keeps the stronger half of its original claim: **no column on `matters` or `projects` stands in for an
extension.**

### Verification

PostgreSQL probe on a disposable `m6_probe` at 40 migrations. All 12 foreign keys present; cross-office
matter, document and reviewer each refused; the three act-pair CHECKs each refused half an act and
accepted a whole one; `RESTRICT` proven on document, Matter and User deletion; a duplicate deed number
refused within an Office and accepted across two; a free-form number accepted; a second extension row
refused by the primary key.

**The status vocabulary was proven twice, deliberately.** The enum cast refuses `PENDING` and `LOCKED`
before they reach the database — so a raw `INSERT` bypassing Eloquent was run to prove the
`notary_deeds_status_check` refuses them independently, and that it **accepts `VOID`**. That is the
D-109 pattern made visible: storable at the database, unreachable through the API.

Ten tables confirmed absent: `notary_minuta`, `notary_register_entries`, `notary_protocols`,
`notary_protocol_items`, `protocol_records`, `notary_deed_documents`, `ppat_matters`, `audit_logs`,
`activities`, `task_templates`. Probe dropped; **the persistent development database was not touched
and remains at 22 migrations**, re-verified afterwards.

---

## 2026-08-24 — M6.0 Notary architecture lock

Branch `feat/m6-notary`, from `6d0c2e9`. **No code, no migration, no permission — the count stays at
177.** One new decision, **D-120**, and two new open items, **O-035** and **O-036**.

`docs/16_M6_NOTARY_ARCHITECTURE.md` is the fifth `.0` lock and the first whose subject is a
specification that is deliberately empty.

### Five of the seven open domain questions are rules a deed surface would encode

`08_NOTARY_WORKFLOW.md` §6 lists seven questions requiring domain validation. The M6 brief specified
an answer to five of them — deed numbering format and allocator, auto-created Repertorium entries,
a Minuta archive lifecycle, post-finalization correction through lock/void/supersede, and approval
restricted to named roles. `CLAUDE.md` §62 names four of those five explicitly as things not to
invent and prescribes **STOP / DOCUMENT THE GAP / ASK FOR DOMAIN SPECIFICATION**.

The approval question is blocked twice: even with a validated domain source, *"default hanya
PRINCIPAL dan SUPER_ADMIN"* is a **role-name authorization**, which D-032, D-041 and D-048 forbid as
a mechanism regardless of the domain answer.

### The permission catalogue independently declines the same acts

Verified against the live registry — `notary.deeds.lock`, `notary.deeds.void`,
`notary.deeds.delete`, `notary.register.delete`, `notary.minuta.delete` and all four
`notary.protocol.*` codes are **ABSENT**. Three of those are exactly the correction mechanisms §6
asks about. Two canonical sources declining to describe the same act is evidence rather than
coincidence, so **M6 builds no act that has no canonical code.**

`notary.deeds.number` **does** exist, and nothing in the repository had noticed. The catalogue
anticipated that assigning a deed number is its own capability, separate from `finalize` — which is
what lets M6 store a number without inventing a numbering rule.

### Blocked does not mean absent

Where the ERD names a field, M6 creates it — a schema matching the ERD is transcription, not a legal
claim. `VOID`, `SUPERSEDED`, `locked_at` and `release_status` become **stored vocabulary with no code
path**: the D-109 pattern for Matter's unreachable statuses, and the D-102 pattern for
`matters.deleted_at`.

The lifecycle that *is* built — `DRAFT → UNDER_REVIEW → APPROVED → FINALIZED` — is not a guess:
`CLAUDE.md` §29 states it verbatim as the legal-record lifecycle and §64 states its consequence.

### Three of the brief's structures are not canonical

* **`notary_protocols` + `notary_protocol_items` do not exist.** The canonical table is
  **`protocol_records`** (ERD §22) — one table with a `NOTARY | PPAT` discriminator, **no junction to
  deeds**, no status vocabulary. §32 places it in batch 11, later than PPAT deeds. M6 is batch 9.
* **Registers are batch 11 too**, and the Repertorium procedure is open question two.
* **`notary_deeds` gets no `deleted_at` and no soft delete** — the ERD omits it, §33 prefers states
  over deletion for finalized legal records, `CLAUDE.md` §30 forbids user-facing hard delete of
  finalized Deeds, and no `notary.deeds.delete` capability exists.

### Decomposition

```text
M6.0  Notary architecture lock                       <- this
M6.1  notary_matters + notary_deeds schema + Policy    (no routes, like M5.1)
M6.2  Deed management surface + deed frontend
M6.3  notary_minuta — metadata and document link, no archive/release lifecycle
```

There is no M6.4: registers and protocol are outside M6, not deferred within it.

### Also recorded

A Deed's reach is its Matter's reach — no `pic_user_id` of its own, so `OWN` and `ASSIGNED` resolve
through the parent, and D-100 holds in both directions. One Minuta per Deed, by unique index, because
the term carries the cardinality. `office_id` added to `notary_matters` and `notary_minuta` as
composite-key carriers, recorded rather than made quietly. D-118's `notary_deed_documents` blocker is
removed by M6.1 and the junction stays unbuilt.

---

## 2026-08-24 — M5.4 Task schema, management and frontend

Branch `feat/m5-documents-tasks`, from `74b7bd3`. **Three migrations (35 → 38), twelve routes, and no
permission — the count stays at 177.** Backend **2360 passed + 8 skipped** (84 of them for Task),
frontend **100 passed** (was 82). One new decision, **D-119**.

### The plan asked for six new permissions. The registry already had eight

`tasks.view`, `view_all`, `create`, `update`, `assign`, `complete`, `reopen` and `delete` have been
canonical since the catalogue was transcribed at M1. `PermissionRegistry.php` is **untouched** by this
milestone, so the brief's "total becomes 183" would have been an invented catalogue extension.

Two things follow that the plan had wrong:

* **`tasks.reopen` is its own capability.** The plan folded reopening into completing; an office may
  reasonably let more people close work than un-close it.
* **`tasks.view_all` is consulted nowhere**, superseded by Data Scope `ALL` for reach (D-090) — as
  `projects.view_all` and both `*.matters.view_all` codes already are.

Where the catalogue is silent nothing was invented. `cancel` and `destroy` share **`tasks.delete`**,
because cancelling is what makes deletion available. Commenting answers to **`tasks.view`**, not
`tasks.update` — requiring the edit capability would mean only those who can change the work may
discuss it.

### `created_by` is added, closing the question M5.0 left for this milestone

`03_DATABASE_ERD.md` section 15 carries `assigned_by` and no `created_by`; the M5 lock §11.1 recorded
that as a question M5.4 must meet as a decision. `assigned_by` cannot be the owner — it moves on every
reassignment, so ownership would drift between people without anybody deciding it, and an unassigned
task would have no owner at all.

### `OWN` and `ASSIGNED` stay two predicates

The plan proposed `OWN` = *"created_by OR assigned_to"*, and `ASSIGNED` = the same thing "for
consistency". That makes `OWN` a superset of `ASSIGNED`, so `ASSIGNED` could express nothing `OWN` did
not — **a ranking between scopes, which D-028 forbids.** Kept apart they answer *"work I raised"* and
*"work I was given"*, and an actor holding both reaches the union, which is what the plan actually
wanted.

### `MEDIUM` would have failed at the database, and the CHECK caught it

Priority is `ProjectPriority` — `LOW NORMAL HIGH URGENT`, already shared by Project and Matter (D-095).
The plan wrote `MEDIUM`. The PostgreSQL probe inserted it and `tasks_priority_check` **refused the
row**, so the constraint earned its place by catching the one thing it was written to catch.

### Schema

| | |
|---|---|
| `2026_08_25_090000` | `UNIQUE (id, office_id)` on `users` — the support key the four user columns need |
| `2026_08_25_090001` | `tasks`: 17 columns, 9 foreign keys, 3 PostgreSQL CHECKs |
| `2026_08_25_090002` | `task_comments` |

Every user a Task names — `assigned_to`, `assigned_by`, `created_by`, `completed_by` — is a composite
foreign key through the Task's own `office_id`, as are `project_id` and `matter_id`. **`RESTRICT`
everywhere and `SET NULL` nowhere**: nulling a composite key nulls `office_id` too, which is
`NOT NULL`, so `nullOnDelete()` would fail at runtime — and refusing to delete a Project that still has
tasks is the right answer anyway.

`workflow_stage_instance_id` is **omitted**. Unlike the blocked document junctions it could have been
written — `matter_stage_instances` exists since M4.7 — but `task_templates` is what would fill it, and
D-104 keeps that unbuilt. A nullable pointer no code can set is the placeholder D-095 refused.

`task_comments` carries **no `office_id`**, following the ERD: its Office is its task's, one join away.
Comments are **write-once** — no edit, no delete, a model guard that refuses an update. `task_id`
cascades; `user_id` restricts.

### A transition matrix, superseding the lock's §11.3

By decision, as D-117 did for §10.2, and with less tension: a Task status is **operational, not
legal**. Only `COMPLETED` and `CANCELLED` may be deleted, so nothing in flight disappears without
somebody saying what happened to it. Completion is reversible; cancellation is not.

### Frontend, shipped with the endpoints

M5.5 held the Task frontend; it lands here for the reason M5.2 gave for documents — a twelve-route
surface nobody can exercise is a milestone nobody can accept. Five pages, eight components, a dashboard
widget, Project and Matter task sections, and 76 translation keys per locale with verified parity.
`is_overdue` is **server-computed and rendered, never recomputed** in the browser. The `can_*` flags
fold status eligibility into capability, so a control the endpoint would 422 is simply absent — and
they stay presentation only (D-113).

### Nothing is audited, again deliberately

The brief asked for a simple log or an `Activity` model. D-115 forbids exactly that and D-118 refused
the same request. `created_by`, `assigned_by`, `completed_by` and the comment thread record who and
when on the rows themselves. A test asserts no such store was improvised.

### A test-runner limit raised, not a test skipped

The suite exhausted memory around 2,360 tests. `php -d memory_limit=1G` had no effect because
`artisan test` spawns Pest as a subprocess — the same shape as O-034, where a shell override never
reached the process that mattered. Fixed where the subprocess reads it, in `backend/phpunit.xml`.

### Verification

PostgreSQL probe on a disposable database at 38 migrations: all nine foreign keys present, cross-office
assignee / creator / project refused, `MEDIUM` refused, completion pair enforced, `RESTRICT` proven on
Project, Matter and User deletion, comments surviving a soft delete, and no `task_templates`,
`audit_logs` or `notifications` created. Probe dropped afterwards; **the persistent development
database was not touched and remains at 22 migrations.**

---

## 2026-08-24 — CI fix: M5.3 formatting

**Quality #53 failed on `077365b`.** One cause, one line, and it was a reporting
failure rather than a code one.

`pnpm format:check` rejected `document-relation-list.test.tsx`: Prettier wanted a
three-line `expect(...)` collapsed onto one. The M5.3 report claimed
`format:check ✓`, and that claim was true when the command ran — the gate was run,
then two test files were edited to fix a failing assertion, and **the gate was
never re-run.** The D-077 defect class: a claim that stopped being true.

### What was checked, and what was ruled out

The milestone brief suggested PostgreSQL, `lockForUpdate()` and missing environment
variables. None of those could be it, and the workflow says so plainly: **CI runs
the backend suite on in-memory SQLite** — `backend/phpunit.xml` pins
`DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, and no PostgreSQL service is
declared. CI never reaches a database where `lockForUpdate()` means anything.

`gh` is still not installed (O-010), so the run log could not be read from the
terminal. The cause was found by **reproducing CI in a clean clone** at `077365b`
instead, which is closer to what CI does than the working copy is:

| CI step | Clean clone at `077365b` |
|---|---|
| `composer install` | ✓ |
| `cp .env.example .env` + `key:generate` | ✓ |
| `vendor/bin/pint --test` | ✓ |
| `php artisan test` | ✓ 2238 passed, 8 skipped |
| `pnpm install --frozen-lockfile` | ✓ |
| `pnpm format:check` | **✗ — the failure** |
| `pnpm lint` | ✓ 0 errors |
| `pnpm typecheck` | ✓ |
| `pnpm test:ci` | ✓ 82 passed |
| `pnpm build` | ✓ |

Also ruled out along the way: no PHP 8.4-only function is used
(`array_find`, `array_any`, `mb_trim` and the rest — none present);
`composer.json` pins `config.platform.php` to `8.3.0`, so dependencies resolve for
the CI runtime; every `@/` import resolves with **exact case**, checked against
real directory listings rather than `existsSync`, which is case-insensitive on
Windows and would have hidden a failure that only appears on `ubuntu-latest`; and
every M5.3 file is present in the commit tree.

**One thing remains unverified locally: PHP 8.3.** The workstation carries only 8.4
and 8.5, so the version CI pins cannot be run here. The checks above are the
evidence that stands in for it.

### The rule this produced

`CLAUDE.md` §52 gains one: **run the gate after the last edit, and report only what
that run said.** A green run is evidence about the tree that produced it and
nothing later — if a file is touched afterwards, the gate has not been run.

---

## 2026-08-24 — M5.3 Document relation surfaces

Branch `feat/m5-documents-tasks`, from `bb6ea99`. **Three routes, no migration, no permission — the
count stays at 177.** Backend **2238 passed + 8 skipped**, frontend **82 passed** (was 76). One new
decision, **D-118**.

> **Correction.** This entry originally recorded `format:check ✓`. That was true
> when the command ran and stopped being true two edits later, and Quality #53
> failed on it. Fixed in the entry above; the gate result for this milestone is
> only green as of that fix.

### Three of six requested entity types can exist, and that was checked before anything was written

The brief asked for junction tables and relations across six types: Project, Matter, Party, Property,
Notary Deed, PPAT Deed. **Three of them have no target.**

`Property`, `NotaryDeed` and `PpatDeed` do not exist as models, and `properties`, `notary_deeds` and
`ppat_deeds` do not exist as tables — verified by counting every `Schema::create` across all 35
migrations: 31 tables, none of them these. The ERD puts them in §16, §17 and §18, batches 8/9/10,
belonging to **M6 and M7**.

So *"buat tiga junction table jika belum ada"* is not a scoping preference that was declined — those
migrations would **fail**, because a composite foreign key cannot point at a table that is not there.
D-115 and the M5 lock §7 had already ruled the four blocked junctions are stubbed none: not empty, not
without their key, not replaced by a polymorphic column.

They are **named as blocked** in `DocumentRelationType` rather than omitted, so adding one later is a
case and a migration rather than a redesign. A request naming one gets a field error, and tests assert
each junction table is still absent.

### Five further corrections to the brief, each checked against the repo

| The brief said | Actually |
|---|---|
| `app/Domains/Documents/` | `app/Domains/Document/` — singular |
| `src/components/documents/` | Does not exist; components live in `src/features/documents/` |
| `[locale]/matters/[id]`, `[locale]/companies/[id]` | `notary/matters/[id]`, `ppat/matters/[id]`, `parties/companies/[id]` — all under `(app)` |
| `ppat/properties/[id]` | Does not exist (M7) |
| `document-relation-section` "mungkin placeholder" | Shipped complete at M5.2, already on the Project and Matter pages |

Two more were settled last milestone and stand: **sections, not tabs** (no `Tabs` primitive exists,
and adding one changes pages M4 already shipped), and `GET /{entity}/{id}/documents` is **already**
`GET /documents?project_id=…`, so a second address was not added.

### Duplicates are refused at the surface and permitted by the schema

The brief asked for a composite primary key `(entity_id, document_id)`. That would be a cardinality
rule the junctions deliberately do not carry: M5.1 declined to invent one because *"a unique index is
a business rule wearing an index's clothing"* (D-116, following D-105 and D-110).

D-110 also said what to do instead if an office decides duplicates are wrong — *"a rule to state and
validate"* — so that is what shipped. The attach endpoint refuses a second attachment inside the
transaction with `lockForUpdate`; the schema stays open, so an office that later needs one is not
blocked by a migration. **Detach removes every matching row**, not the first, because a pair can still
exist from a direct write. Tests pin both halves.

### Audit was asked for and is still refused

The brief asked for *"audit log … gunakan Activity model jika ada atau buat log sederhana"*. There is
no `Activity` model, and D-115 forbids exactly this: *"an application log is not append-only in the
sense §31 means, is not queryable by resource, and is the stopgap that becomes permanent."*

`attached_by` and `attached_at` record who and when on the row itself. A test asserts no audit store
was improvised — no `audit_logs`, no `activity_log`, no `activities`, no `App\Models\Activity`.

### Attaching asks two questions

`documents.update` on the document side — attaching is a correction to a document's own filing rather
than a new act, so **no `documents.attach` was registered**. And the record on the other end must be
reachable under **its own domain's** view capability, resolved through that domain's visibility class:
`documents.update` is authority over filing, never authority to discover which records exist.

For a Matter the namespace comes from the row's own `domain` column — the second place in the
repository that does so, after M5.2. It looks like the D-101 hazard and is not: the caller supplies an
id, the namespace comes from a row they cannot influence, and the resulting check is the **stricter**
of the two. A test proves a Notary-only actor is refused a PPAT Matter and accepted for a Notary one.

### Verified on PostgreSQL, because SQLite could not have caught it

`m53_probe`, created and dropped; the persistent database stayed at 22 migrations. M5.3 adds no
migration, so the probe exists for one specific reason: **`lockForUpdate()` is a no-op on SQLite and
real on PostgreSQL**, and the duplicate guard depends on it.

All three attaches accepted; duplicate refused through the lock path; cross-office attach refused
through the Action and again on a raw insert with a mismatched carrier; detach cleared both duplicate
rows; detaching nothing refused; `RESTRICT` refused deleting an attached document and an attached
party; the blocked junctions and the audit store confirmed absent.

### Three defects found and fixed

**A shell-generated model edit ate its own docblock.** Backticks inside a heredoc were interpreted as
command substitution, so `$this` and every code span vanished from three models. Reverted with
`git checkout` and redone with the editor — the generated code was never committed.

**A test failed on its own spelling.** `"data.{$type}s"` builds `partys`, not `parties`. The dataset
now carries the plural explicitly rather than deriving it.

**A frontend assertion raced the query.** The attach button renders outside the query branches, so
finding it proved nothing about the list having loaded; the detach assertion is awaited now.

And one guard did its job: `DocumentSchemaTest`'s exact-route list failed the moment three routes were
added, and was narrowed to twelve rather than loosened to a count.

## 2026-08-24 — M5.2 Document management

Branch `feat/m5-documents-tasks`, from `6f495f8`. **Nine endpoints, four pages, one migration
(34 → 35).** Backend **2202 passed + 8 skipped**, frontend **76 passed** (was 62). **No permission is
registered; the count stays at 177.** One new decision, **D-117**.

The first M5 milestone with routes and a frontend.

### Five corrections to the brief, found before any code was written

| The brief said | Actually |
|---|---|
| `DocumentStorageService`, `DocumentNumberService` | `DocumentStorage` and `AllocateDocumentReference` — those class names do not exist |
| `DocumentPolicy` has five methods | It has **nine**. Nothing to add |
| `current_version_id` is an FK to `document_versions.id` | A **composite** FK `(id, current_version_id) → document_versions (document_id, id)` |
| Options authorizes `documents.create` | **There is no `documents.create`.** Upload answers to `documents.upload` |
| Verify sets `verified_at` / `verified_by` | `documents` has **neither column** — the ERD gives them to `matter_requirements` and `warkah` |

Three conflicts were raised and settled before implementation rather than resolved silently: the
missing columns, the status matrix, and the frontend milestone boundary.

### The lock's own "no transition matrix" ruling is superseded

M5.0 §10.2 said M5 would authorize *who* may verify or archive and never encode *which* status may
follow which. M5.2 encodes one, by decision:

```text
upload   ->  RECEIVED
verify   RECEIVED, UNDER_REVIEW   ->  VERIFIED
archive  VERIFIED, FINAL          ->  ARCHIVED
delete   DRAFT, RECEIVED          ->  (soft deleted)
```

**Operational, not legal.** Nothing here says what a deed, a Minuta or a Warkah may become — M6 and
M7 are untouched. What it says is that an office may not verify twice, may not archive what was never
verified, and may not delete what somebody has verified. `02_MENU_AND_PERMISSIONS.md` §13 requires
`documents.delete` be *"heavily restricted"*; "only before verification" is the restriction, expressed
as a status rule rather than by inventing a permission.

**Upload creates `RECEIVED`, not `DRAFT`, and that correction is load-bearing.** Verify requires
`RECEIVED` or `UNDER_REVIEW`, and nothing moves a document out of `DRAFT` — so as originally
specified, verify would have answered 422 to every document that exists. `DRAFT`, `UNDER_REVIEW`,
`FINAL` and `VOID` are unreachable in M5.2 and recorded as such (the D-109 precedent).

### Verification records a status and nothing else

`03_DATABASE_ERD.md` §13 gives `documents` no `verified_at` or `verified_by`; the pair belongs to
`matter_requirements` and `warkah`. Adding them would extend the canonical field list on this
milestone's authority. Who verified and when is what the audit store records (D-115), and writing it
in two places would guarantee the two eventually disagree.

### Every sensitive download is refused, whatever the actor holds

D-115 rules that no sensitive-download surface ships before an audit store exists. The gate sits in
`DocumentPolicy::download` **after** the capability checks rather than instead of them, so the
milestone that builds audit deletes three lines rather than reconstructing the authorization.

`documents.sensitive.download` is therefore a capability that currently authorizes nothing — recorded
in the Policy, in `can_download`, and on the screen, which says why the button is missing instead of
leaving somebody to guess. The smoke proves it against an actor holding **every** relevant code.

Sensitive documents an actor cannot reach are **excluded from the list, not stubbed**: what a stub
may carry is a question the M5 lock leaves open, and rendering one would have answered it by
accident.

### Soft delete arrives, and leaves the file alone

M5.1 withheld `SoftDeletes` while `deleted_at` sat unused, so "invisible because deleted" could not be
confused with "invisible because out of scope" (D-102). M5.2 ships `DELETE`, so the lifecycle exists.

**The bytes, the checksum and every version row survive** — a delete that erased files would be a hard
delete wearing a soft one's name (`CLAUDE.md` §19, §30). There is **no restore endpoint**: reading
`documents.delete` as *"may also undelete"* would make one capability do two jobs.

### Two things that are invisible until they are a defect

**`is_sensitive` is sent as `"1"` / `"0"` over multipart.** A multipart body carries strings, and
`"false"` would arrive as a non-empty string and pass Laravel's `boolean` rule as **true** — silently
marking every document sensitive.

**File type is validated with `mimetypes`, not `mimes`** — the file's detected content type rather
than its extension. The HTTP smoke caught what the test suite could not: `UploadedFile::fake()`
reports a type derived from the filename, so a text file named `.pdf` passed in Pest and was correctly
refused by a real upload. The smoke fixture is a real PDF now.

### The frontend ships here, as sections rather than tabs

The lock listed the document frontend for M5.5; nine endpoints with no way to exercise them is not a
milestone anybody can accept, so it ships with them and §13 is amended in place. M5.5 keeps the Task
frontend, which genuinely depends on M5.4.

Four pages — list, upload, detail, edit — plus **sections** on the Project and Matter detail pages,
following the M4.5 and M4.7 precedent on those same pages. Not tabs: the repository has no `Tabs`
primitive, and adding one is a design decision affecting shipped pages rather than a side effect.

**No new frontend dependency.** Drag-and-drop is native HTML5 events on a real
`<input type="file">`, so the keyboard and assistive paths belong to the browser rather than a
library. `react-dropzone` was not added.

### Verified over HTTP, on a disposable database

`m52_probe`, created and dropped. **The serving process proved its own database** before any
functional request — `current_database() = m52_probe`, 35 migrations, 177 permissions — because
`artisan serve` drops a shell `DB_DATABASE` override for its `php -S` child (O-034). Real Sanctum
cookie session, CSRF cookie, XSRF header, no Bearer anywhere.

**47 of 47 checks passed**: upload with three attachments and an allocated `DOC-2026-000001`; the
file on the private disk under `documents/{office}/2026/08/` with a matching SHA-256; a download whose
bytes are byte-identical to the upload; `attachment` and `no-store` headers; verify, archive, and the
422s for verifying twice, archiving the unverified, deleting the verified, changing sensitivity after
verification, and patching a replacement file; a 403 for a sensitive download; 403s for a
metadata-only reader on upload, options, download, verify and delete; 401s for a guest; and a 404 for
`/storage/documents/…`, which has no route.

Rolling back migration 35 alone returned `document_number` to nullable with all 15 junction and
office foreign keys and both unique constraints intact; re-migrating restored `NOT NULL` against real
rows.

**The persistent development database was not touched — still 22 migrations, zero document tables.**

### One flaky M4 test, found and fixed rather than re-run until green

`MatterManagementTest`'s identity guard failed once during this milestone and passed on every other
run. The cause is real and had nothing to do with M5.2: it scanned the **raw JSON payload**, which
carries four lowercased ULIDs — and a ULID is 26 characters of Crockford base32, so it can legitimately
contain the letters `deed` or `npwp`. Measured at roughly **one ULID in 200,000**, which made the test
fail about once in fifty thousand runs.

Only two of its five needles could ever collide: Crockford base32 excludes `i`, `l`, `o` and `u`, and
has no underscore, so `nik`, `tax_id` and `warkah` were never at risk. That is why it went unnoticed
from M4.4 until now.

Identifiers are redacted before the search. The claim is unchanged and is the one worth keeping — no
Party identity, deed, or Warkah field or value appears in a Matter payload — and a real `nik` or
`warkah` leak is still caught, which was verified rather than assumed. An opaque identifier was never
evidence either way.

---

## 2026-08-23 — M5.1 Document schema and private storage foundation

Branch `feat/m5-documents-tasks`, from `0890fec`. **Five migrations, 29 → 34.** Backend
**2127 passed + 8 skipped**, frontend **62 passed** and unchanged. **No permission is registered;
the count stays at 177.** One new decision, **D-116**.

Backend foundation only — no route, controller, request, resource or frontend, following M2.1,
M3.1, M4.1, M4.2 and M4.6. A test asserts that no route contains the word `document`.

### `is_current` is gone, and a bare pointer would not have been enough

M5.0 handed M5.1 an explicit choice: a partial unique index on a boolean, an application invariant,
or a pointer on `documents`. **The pointer wins.** A partial index does not exist on the SQLite
connection the suite runs on, so the two engines would disagree about what is representable — the
shape D-111 already refused once.

But `current_version_id` alone could have named a version belonging to some **other** document, and
nothing would have objected. So `document_versions` carries a support key `UNIQUE (document_id, id)`
— redundant for uniqueness, required for a composite foreign key — and `documents` declares:

```text
documents (id, current_version_id)  ->  document_versions (document_id, id)
```

the construction `company_people`, `project_parties`, `matters`, `matter_parties` and
`workflow_templates` all use for the same-Office invariant, applied here to a same-Document one. A
cross-document pointer is unrepresentable rather than merely wrong. The key arrives by `ALTER` in its
own migration because the two tables reference each other; SQLite cannot add one to an existing
table, so a model guard holds the identical rule where the tests run.

### A claim that was written before it was tested

The composite key first shipped `ON DELETE NO ACTION`, with a docblock arguing that `RESTRICT` would
break the cascade: `document_versions.document_id` cascades, so hard-deleting a Document removes
versions that the same Document row still points at, and PostgreSQL checks `RESTRICT` immediately.

**The PostgreSQL probe ran the delete under both declarations. Both succeeded.** The referencing
`documents` row goes in the same statement, so by the time `RESTRICT` looks for something pointing at
the version, there is nothing. The two are also identical in the other direction — deleting a version
while its Document survives is refused either way.

The declaration is now `RESTRICT`, like every other key in the schema; `document_versions.document_id`
remains the single deliberate CASCADE. This is written down because the asymmetry was asserted as
verified before it was verified — the D-077 defect class, caught by the check that was promised
rather than by a later milestone.

### Three corrections against the plan's schema

| Plan | Canonical source | Shipped |
|---|---|---|
| `documents` omits `updated_by` | ERD §13 lists it | **included** — `matters` carries the same pair, and `updated_at` alone records that something changed without recording who |
| `document_versions` gains `created_at` / `updated_at` | ERD §13 lists neither, only `uploaded_at` | **omitted** — a column recording when a version changed is a column inviting one |
| "14 columns" / "12 columns" | — | **18 and 12** |

The plan also said four migrations; there are five, so the count is 29 → 34 rather than 29 → 33.

### A version is written once, enforced rather than intended

The model refuses `update` outright, timestamps are off, and `storage_path` / `stored_filename` are
never serialized. `storage_path` may contain neither `public/` nor `uploads/` — checked in the
storage service, by a PostgreSQL `CHECK`, and by a model guard that holds on both engines.
`checksum_sha256` must be 64 lowercase hex characters, for the same reason in the same three places.

### Storage issues no URL of any kind

No signed URL, no temporary URL, no path a client could try — asserted by both a reflection check and
a source scan. A URL that authorizes by possession is a second authorization path beside the Policy
chain (D-114), and a second one that happens to work is the problem rather than the convenience it
looks like.

```text
documents/{office_id}/{YYYY}/{MM}/{ulid}.{ext}
```

`office_id` leads so a misconfigured backup meets the Office boundary the database enforces. The
uploader's filename is **never** a path component — it carries traversal sequences, case collisions,
and for a KTP scan often the subject's own name — and the extension is reduced to lowercase
alphanumerics so a crafted name cannot smuggle a separator. The SHA-256 is computed from **the bytes
actually written**, because hashing the source would attest to something other than what is stored.

### `DOC-YYYY-NNNNNN`, two namespace dimensions rather than three

Matter needed a domain because `N-` and `P-` are distinct sequences competing for one value (D-108);
a Document has no such split, so it takes the shape Project uses. One atomic `INSERT … ON CONFLICT …
DO UPDATE … RETURNING`, no `MAX+1`, and the allocator opens no transaction of its own so it
participates in the caller's. `document_number` ships **nullable**, exactly as `project_number` was
until M3.3 and `matter_number` until M4.4.

### Three scopes reach a Document, and the Matrix was narrowed to match

```text
OWN       documents.created_by = actor id
OFFICE    documents.office_id  = actor office
ALL       cross-office reach
ASSIGNED  no grant — a Document has no assignee
TEAM      no grant — no Team entity exists (D-042)
```

`OWN` is granted here where Party (D-080) and Service Type (D-106) withhold it, and the difference is
real: those are shared reference records the colleague who typed them in has no claim on, whereas
`created_by` names the person who filed the document — the argument Project made at D-088.

All nine `documents.*` codes are narrowed to these three in `PermissionScopeRules`. **Withholding
`ASSIGNED` only in the predicate would have left the dead control visible in the interface**: an
administrator could grant `documents.view` at `ASSIGNED`, see it saved, and hold a silently powerless
grant. That is the failure D-080 named, and it is why Party and the office-owned master data both got
explicit entries.

### Sensitivity is a second capability, not a scope

`is_sensitive` appears **nowhere** in `DocumentVisibility`, and a source scan asserts its absence.
Folding it into the scope predicate would make one permission answer two questions and would silently
reinterpret every existing `documents.view` grant.

It is checked in the Policy as a condition **on top of** reach, which is what keeps the two
independently grantable in both directions (D-115): `documents.view` does not reach a sensitive
document, and `documents.sensitive.view` cannot stand in for the ordinary code. Sensitivity gates
every write ability too — correcting, verifying, archiving or deleting a KTP scan all disclose it.

`download` is written and **nothing calls it**: D-115 rules that no sensitive-download surface ships
before an audit store exists.

### The junctions moved a milestone earlier, and the lock says so

M5.0 put `party_documents`, `project_documents` and `matter_documents` in M5.3; they are built here.
The reason is structural rather than a change of plan — each carries an `office_id` constraint
carrier with a composite key into `documents (id, office_id)`, and that support key is created by the
`documents` migration, so splitting the tables from the key they depend on would have run a milestone
boundary through one invariant. **M5.3 keeps the surfaces**, which is where the authorization work is.

`15_M5_DOCUMENT_TASK_ARCHITECTURE.md` is amended in place rather than quietly left stale: the status
line now reads `LOCKED — M5.0, amended at M5.1`, §13 records the move and why, and both questions
§14 assigned to M5.1 are marked resolved.

### Verified on PostgreSQL, on a disposable database

`m51_probe`, created and dropped for the purpose; the persistent development database was not
touched and remains at 22 migrations with zero document tables. The serving process proved its own
database with `SELECT current_database()` before anything was asserted.

Migrated 0 → 34; every `CHECK`, composite key and cascade exercised directly. Refused, as designed:
a version deleted while its Document survives, a pointer at a foreign version, `public/` and nested
`uploads/` paths, an uppercase checksum, `version_number = 0`, `file_size = -1`, a status outside the
enum, `reference_year = -1`, and a cross-office attachment on all three junctions. Rolled back the
five M5.1 migrations alone → 29, with no leftover constraint or index and the `parties`, `projects`
and `matters` support keys intact; `migrate:reset` and a full re-migrate from zero both clean.

---

## 2026-08-23 — M5.0 Document and Task architecture lock

Branch `feat/m5-documents-tasks`, from `main` at `f82dc25`. **No migration, no permission, no
model, no route.** A documentation lock, following M4.0 — plus **one config line**, because it
closes an access path and the right moment for that is before anything valuable sits behind it.
Backend **2005 passed + 8 skipped**, frontend **62 passed**, both unchanged. Two new decisions,
**D-114** and **D-115**.

### The one line of code: `serve => false`

`config/filesystems.php` shipped the private `local` disk — rooted at `storage/app/private`, the
directory M5 will fill with KTP scans, NPWP records and Minuta Akta — with `'serve' => true`. That
registers two routes straight into it:

```text
GET  /storage/{path}   storage.local
PUT  /storage/{path}   storage.local.upload
```

**It was never open**: `ServeFile` aborts without a valid relative signature when the disk is
private. That is not the problem. The problem is that **a signed URL is a transferable bearer token
that bypasses the authorization chain entirely** — no Policy, no `EffectiveAccessResolver`, no Data
Scope, and no distinction between `documents.download` and `documents.sensitive.download`. Whoever
holds the string holds the file: forwarded in a chat, pasted into a ticket, sitting in a browser
history.

`CLAUDE.md` §21 requires sensitive files be *"authorization protected"* and *"unavailable through
predictable public URLs"*; §54 forbids exposing private document URLs. A URL that authorizes by
possession fails both however unguessable it is.

Both routes are gone. The application's own **127 routes are untouched**, and the lock rules that no
document surface may ever issue a signed or temporary URL — downloads stream from a controller that
authorized the actor against the record first.

### Three of seven junctions are buildable

`03_DATABASE_ERD.md` §14 recommends seven document junction tables. Four reference tables that **do
not exist**: `properties` (batch 8, M7), `notary_deeds` (batch 9, M6), `ppat_deeds` (batch 10, M7)
and `matter_requirements`. A foreign key cannot point at a table that is not there — the reasoning
the M4 lock used for "M4.1 precedes M4.2".

M5 builds `party_documents`, `project_documents` and `matter_documents`, and **stubs none of the
rest**: not empty, not without their key, and not replaced by a polymorphic column — which §14
explicitly argues against.

### Audit is required, absent, and not improvised

§21 requires sensitive files be *"audited where appropriate"*. `audit_logs` has never been built,
D-033 kept it out of M1 on the ERD's batch-7 ordering, and `audit.view` / `audit.export` are
registered and unimplemented.

The lock rules three things rather than filling the gap: **no half-measure ships** — an application
log is not append-only in the sense §31 means, is not queryable by resource, and is the stopgap that
becomes permanent; **no sensitive-download surface lands before audit exists**, because the
capability to read a KTP scan and the record of who read it belong in the same milestone; and when
built it follows §31 exactly and **never logs the document's contents nor the identifier it is
about**.

### Workflow gating deferred, and doubly so

`matter_requirements.required_before_stage_code` gates a stage transition on document completeness.
D-104 forbids inferring workflow content; the Notary and PPAT gating rules differ and neither is
authored; **and the table it references, `service_document_requirements`, does not exist**. So M5
builds neither table — not empty, not column-present-but-unused, not a nullable placeholder (D-095).

### Two catalogues left uninvented

`document_type_code` stays **opaque and nullable**, following `role_code` (D-105, D-111): `KTP`,
`NPWP` and `AKTA` are examples in prose, not a validated list, so no enum, no `CHECK`, and no
dropdown built from a guess. And **`is_sensitive` is set by whoever uploads, never inferred from the
type** — deriving it would encode which document kinds are sensitive, a judgement that varies by
office.

### Two questions handed forward as owned

`is_current` uniqueness on `document_versions`: the obvious partial unique index is **the shape
D-111 already refused**, because SQLite has no partial indexes and the two engines would disagree
about what is representable. M5.1 must choose between that, an application invariant with a test, or
a pointer on `documents` — and say which.

And `tasks` carries `assigned_by` but **no `created_by`**, while Data Scope `OWN` needs an owner.
M5.4 resolves it explicitly rather than adding a column on instinct.

### Decomposition

```text
M5.0  architecture lock                      <- this
M5.1  document schema + private storage
M5.2  document management surface
M5.3  document relations (party/project/matter)
M5.4  task schema + management
M5.5  frontend + M4 integration
M5.6  M5 quality gate
```

Documents precede tasks because `03_DATABASE_ERD.md` §32 says so — batch 6 then batch 7 — not by
preference. **Audit is deliberately unnumbered**, since §8 of the lock rules that no
sensitive-download surface ships before it exists.

### Also corrected

`CLAUDE.md` §58 and the README documentation table gained `15_`; the README's status header still
said M4 was *"selesai di branch dan menunggu penerimaan"* after the merge, and its bootstrap
paragraph still said **173** permissions rather than 177.

---

## 2026-08-21 — M4.8 M4 Quality Gate

Branch `feat/m4-matter-workflow`. **No migration, no permission, no schema change, no route.** An
audit milestone, following M1.10, M2.6 and M3.5. Backend **2005 passed + 8 skipped / 6461
assertions**, unchanged — the audit found no code defect to fix. **Six documentation defects fixed**,
all of the D-077 class: a claim that stopped being true.

### What was audited

Git position, migration inventory, the canonical permission count, the persistent development
database, five documentation files, the `view_all` supersession rule, debug code, and the whole M4
chain end to end over HTTP.

### Documentation defects found and fixed

**1. Three CHANGELOG dates were wrong.** M4.5 and M4.6 landed 2026-08-20 and M4.7 on 2026-08-21;
all three were recorded as 2026-08-18. Each entry had been written from the clock at the moment the
work *started*, and the clock moved during the session. Corrected against `git log` in both
`CHANGELOG.md` and `DECISIONS.md`, which carried the same headings.

**2. The M4 lock's delivery list was out of order.** Entries were appended as each milestone landed,
so **M4.2's block had come to sit after M4.7's** and read as though it were the most recent. Sorted
into milestone order.

**3. `current_stage_id` was described as deferred to a milestone that declined it.** The M4.2 block
said the column was deferred "to M4.7 with the real stage-instance foreign key". M4.7 built the
stage instances and then **decided not to build the column** (D-112), because the `ACTIVE` instance
*is* the current stage. The paragraph now records the deferral and its resolution.

**4. Six "will reference" claims outlived their milestone.** Support keys described as what a future
milestone *would* use, in `14_M4_MATTER_ARCHITECTURE.md` and `03_DATABASE_ERD.md`, where every
referencing milestone has since shipped. Also "M4.5 is expected to add four" permissions — it added
them.

**Decision records were deliberately left alone.** D-105 saying "four permissions are expected at
M4.5" and D-108 saying "M4.4 will allocate" were true when written, and a dated register's value is
that it records what was known then. Only the living reference documents were corrected.

**5. `README.md` said "Status: M0 — Foundation."** Four milestones stale, in the most visible file
in the repository, claiming there were no business modules at all. Rewritten to state what exists
through M4.8, what does not until M5, and that the workflow engine ships deliberately empty (D-104).
The milestone list marked M1–M3 complete and M4 delivered-pending-acceptance.

**6. `README.md`'s documentation table omitted the three architecture locks.** `12_`, `13_` and
`14_` were missing while `CLAUDE.md` §58 lists all fourteen files — the same gap O-003 closed for
`CLAUDE.md` at M0, left open in the README. Added, with a sentence on what a lock is for.

**7. The M4 unresolved-items table asked the wrong question.** Its third column read *"Blocks
M4.1?"* and it closed with *"None blocks M4.1"* — written at M4.0 and asking about a milestone
finished six milestones earlier. Re-asked as *"Blocks closing M4?"*. Three rows gained the outcome
that has since settled around them: `matter_parties.sequence_no` is now distinguished from
`workflow_stages.sequence_no`, which M4.6 did settle; the transition-matrix row notes that stage
transitions have no matrix either; and the stage-visibility row records that M4.7 built
`assigned_user_id` and kept it out of every scope predicate. **One row was added** — re-instantiating
a workflow on a Matter created before templates existed, which `UNIQUE (matter_id)` makes
impossible (D-112).

### Code audit — nothing to fix

- **`view_all` supersession holds.** Five codes registered — `projects`, `notary.matters`,
  `ppat.matters`, `tasks`, `calendar` — and a source scan finds **no** call resolving any of them as
  authority. The `app/`-wide forbidden-authority scan covers every file added by M4.5–M4.7.
- **No debug code.** No `dd`, `dump`, `var_dump`, `print_r` or `ray` in `app/`, `routes/` or
  `database/`; no `console.*` or `debugger` in `frontend/src`.
- **No suppressions.** No `@ts-ignore`, `@ts-expect-error` or `eslint-disable` anywhere in the
  frontend.
- **Guard tests.** M4.5, M4.6 and M4.7 each narrowed the guards their own work made stale; the audit
  found none left behind.

### Full-M4 smoke — 57 steps, 57 passed

One disposable database carrying the **whole chain**, which no previous smoke had exercised
together: Service Type (M4.1) classifying a Matter (M4.2) with an allocated reference (M4.3),
managed over HTTP (M4.4), carrying participants (M4.5), running a workflow (M4.7) instantiated from
a template bound to that Service Type (M4.6).

The integration facts that only a combined run can show: a **classified** Matter picks the
service-bound template (4 stages) while an unclassified one falls back to the generic default
(2 stages), and an Office with no template configured gets no workflow at all — the ordinary state
on a fresh deployment. Plus the boundaries: cross-domain and cross-Office Service Types refused,
per-Office reference namespaces independent, no status/archive/restore route, a Party linkable twice
under two roles, no identity in any participation payload, stage moves leaving Matter status
untouched, and completing the Matter closing its workflow run.

Full reversibility re-proven: `migrate:reset` to 1 table, re-migrate to 29.

### Persistent development database — untouched

`migrate:status` shows **22 Ran and 7 Pending**, every pending one an M4 migration. 25 tables, none
of them `matter*`, `workflow*` or `service_types`. Permissions still 173, because
`permissions:sync` has never been run against it — expected, and not a structural change.

### Open items

O-010, O-018, O-031, O-032, O-033 and O-034 remain open and untouched, as they have since M3.5.

---

## 2026-08-21 — M4.7 Matter workflow instances and stage transitions

Branch `feat/m4-matter-workflow`. **One forward migration** (29 total) creating `matter_workflows`,
`matter_stage_instances` and `matter_stage_history`. **No permission** (177):
`*.matters.change_stage` was already canonical and now has routes. Backend **2005 passed + 8
skipped = 2013 tests / 6461 assertions** — 62 new. i18n **881 / 881** exact, 33 new keys per locale.
One new decision, **D-112**.

Six routes, three per domain. Reading answers to `*.matters.view` — a stage is part of what a Matter
*is*, not a separate resource with its own audience, unlike participation which the registry gave
its own codes. `options` and `move` answer to `*.matters.change_stage`, which **leaves the deferred
list** now that it has a target.

### The RESTRICT that carries the whole snapshot design

M4.6 wrote down a consequence for M4.7 to honour, and this is it:
`matter_stage_instances.workflow_stage_id` is **`RESTRICT`, never `CASCADE`**. M4.6's stages cascade
from their template, so a `CASCADE` here would chain — deleting a template would delete its stages,
which would delete the instances of every Matter that ran it, destroying exactly the history
snapshotting exists to preserve. Two tests prove it: deleting a running stage is refused, and
deleting the template is refused with all instances intact.

The other two mechanisms: `workflow_version` on the run, meaningful because M4.6 made `version` a
counter on one row rather than a row per version; and `stage_code` plus both names copied onto every
instance and never refreshed. A test renames a template stage and asserts the running Matter still
shows the old name.

**`stage_name_snapshot_id` is not a foreign key.** The `_id` is the locale code for Bahasa
Indonesia; the column holds a displayable name. Every other `*_id` in this domain holds a ULID, so
the name genuinely invites a wrong join — transcribed rather than renamed, with a test asserting it
is not a ULID.

### What a move does, which the specification left open

The brief validated that a target exists and is open, and never said what becomes of the stage
moved away from. Something had to: two `ACTIVE` stages leave "current stage" with no answer.

**The stage you leave becomes `COMPLETED`; stages jumped over stay `PENDING`.** Marking them
`SKIPPED` would infer a decision from a navigation, and skipping is something somebody chooses. So
`SKIPPED` and `BLOCKED` are **vocabulary nothing sets** — the same honest gap M4.4 left for the
unreachable Matter statuses — and a source scan asserts no code path writes either. Both still
render, because the backend may one day return them.

**Still no transition matrix** (D-104). A backward move is ordinary and offered exactly like a
forward one. Moving to the stage already active is refused, because that is not a move. **Matter
Status is never written by a stage move**, and a test says so.

### How a workflow completes

A stage completes by being moved away from, so the final stage never would and
`matter_workflows.completed_at` would be dead schema. **Completing the Matter closes its workflow**,
in `CompleteMatter`'s existing transaction, marking the `ACTIVE` stage complete and stamping the
run. It reuses an act offices already perform and a capability that already exists rather than
inventing a third endpoint. **No history row is written** — nothing moved, and a row whose `from`
and `to` were the same stage would record a movement that never happened.

### Instantiating nothing is the ordinary outcome

D-104 seeds no templates, so on a fresh deployment **every** Matter is created without a workflow.
That is the normal path, not an edge case: refusing to create Matters until somebody configures a
process would make the whole module depend on domain validation that has not happened.

Called explicitly inside `CreateMatter`'s transaction rather than from a model observer — the
repository registers none, one would make Matter creation silently do two things including in every
factory call, and a workflow committing while its Matter rolled back would be an orphan the
`UNIQUE (matter_id)` key blocks forever. A test proves the rollback.

M4.6 left no uniqueness on `is_default`, so **this action breaks ties and says how**: Service Type
first, then generic; `is_default` first, then **oldest by ULID** — the established default, not one
created this morning. `is_start_stage` is deliberately not consulted: its meaning is unsettled, and
honouring it would be inferring workflow semantics.

### A defect this milestone surfaced in M4.4

`MatterController::store` set `service_type_id` **after** `CreateMatter` returned. At M4.4 that was
a second write outside the transaction; M4.7 made it a defect, because instantiation reads
`service_type_id` to prefer a service-specific template, and running before the value was set meant
**the preference could never fire in production** while passing in a directly-constructed test — the
test would have been green and the feature dead. `service_type_id` is now an explicit parameter of
`CreateMatter`, set before instantiation and inside the transaction.

### History is append-only, and enforced

The model refuses `update` and `delete`; the schema carries `changed_at` and neither `updated_at`
nor `deleted_at`. Stage codes are stored as strings rather than foreign keys, so a later template
edit cannot rewrite what the record says happened. `reason` is free text and a leak surface — D-105
forbids Party identity there, and the interface warns.

### `matters.current_stage_id` is deliberately not built

The ERD lists it and M4.2 and M4.3 both deferred it *by name* to this milestone. The `ACTIVE` stage
instance **is** the current stage, so a pointer would be a second source of truth that can disagree
with it. Recorded as a decision, closing the earlier deferrals rather than leaving them dangling.

### Recorded but never written

`assigned_user_id`, `approved_at` and `approved_by`: M4.7 ships no stage assignment and no approval
act, and the Form Request refuses all three. **A stage assignee confers no Matter reach** (D-100) —
a test grants an account `ASSIGNED` Matter visibility, assigns it to a *stage*, and asserts it still
cannot open the Matter.

### Frontend

A workflow **section** on the Matter detail page, not a tab: the repository has no `Tabs` primitive
and M4.5 set the precedent. The vertical stepper renders all five statuses with an icon **and** a
translated label, so nothing depends on colour. The move dialog offers every open stage rather than
a "next" one — offering only "next" would be the transition matrix D-104 refuses, invented by an
interface.

### Guards narrowed rather than deleted

Fourteen moved. `MatterManagementTest`'s payload guard had been matching the substring `stage`
against the new `can_change_stage` flag; it now asserts the real claim, that the Matter payload
embeds neither the participant list nor the workflow. `PermissionMatrixTest` inverted: it had
checked that no stage route existed, which is the opposite of what removing the badge means, so it
now checks that one does. M4.6's own probes narrowed the very next milestone, including its
hardcoded rollback step.

One test of mine exhausted PHP's 128MB limit by tokenizing all of `app/` in a single pass — the
repository's comment-stripping idiom does not scale to a directory. Rewritten to tokenize only the
handful of files that mention the enum.

---

## 2026-08-20 — M4.6 Workflow templates and stages

Branch `feat/m4-matter-workflow`. **One forward migration** (28 total) creating `workflow_templates`
and `workflow_stages`. **No permission** (177): both workflow codes were already canonical, and M4.6
only narrows their assignable Data Scopes. Backend **1943 passed + 8 skipped = 1951 tests / 6303
assertions** — 36 new, one skipped on SQLite by design. i18n unchanged at **848 / 848**. One new
decision, **D-111**.

**Backend foundation only** — no route, controller, request, resource, seeder, or frontend,
following M2.1, M3.1, M4.1 and M4.2.

### A mechanism, shipped deliberately empty

D-104 permits the engine and forbids the content. Nothing here seeds or infers a Notary or PPAT
stage sequence, a default template, an approval point, a required-before-stage rule, tax or deed
gating, or a legal completion condition. A test asserts both tables are empty; a second scans the
two factories, comments stripped, for `pemeriksaan`, `penandatanganan`, `minuta`, `warkah`, `ajb`,
`apht`, `legalisasi`, `waarmerking`, `repertorium` and `akta` — a fixture reading like real process
vocabulary could later be mistaken for validated content by a reader, by a copy-paste into a seeder,
or by somebody reconstructing "how the office works" from the test suite. The fixtures say `UJI_`.

**A configurable engine with no content is the correct outcome**, and is stated plainly rather than
presented as a limitation: the office's real workflow is blocked on domain validation, not on
engineering.

### The version question the specification contradicted itself on

The brief required `UNIQUE (office_id, code)` **and** said a template may have several versions.
Those cannot both hold — two rows sharing a code violate that key.

**One row per code, and `version` is a counter on it.** The ERD settles it: `matter_workflows`
carries **both** `workflow_template_id` *and* `workflow_version`. Under a row-per-version reading
the foreign key alone would identify the iteration and the number would be redundant. Carrying both
only makes sense if the id says which template and the number says which iteration of it.

What preserves the old iteration is not an old row but M4.7's snapshot — `stage_code` plus both
snapshot names on every stage instance. `CLAUDE.md` §18 requires that editing a template never
retroactively change a running Matter, and a snapshot guarantees that where a surviving row would
not, since nothing stops an administrator editing that too.

`office_id` and `code` are immutable on the model, following `ServiceType`. **`version` is
deliberately outside that set** — bumping it is the ordinary act of editing.

### `approval_permission` is validated where it is written

The column stores a permission code as data, which is an authorization surface configured by text.
**A value naming no canonical permission is refused on save.** Left open, a typo or a renamed code
would sit in the table until M4.7 tried to resolve it and had to decide at runtime what an unknown
string means — the case where inventing a meaning is most dangerous.

Storing a code authorizes nothing. Whatever reads it still goes through a Policy and
`EffectiveAccessResolver` with the actor's Data Scope (D-048). Null stays ordinary:
`requires_approval` alone is meaningful, since an office may know a step needs signing off before it
knows which capability should gate it.

### Structural same-Office binding, and no support key added

```text
workflow_templates (service_type_id, office_id) -> service_types (id, office_id)
```

Office A's template cannot bind Office B's service. `service_types` has carried the matching
`UNIQUE (id, office_id)` since M4.1, which added it in anticipation of exactly this, so this
migration adds no support key and drops none on rollback. A composite key with a NULL component is
satisfied, so a generic template — `service_type_id` null — stays valid, which matters because M4.1
ships the catalogue empty on purpose.

### What is deliberately not constrained

**`is_default` carries no cardinality rule.** Several templates may be default at once, following
`project_parties.is_primary` (D-092). A partial unique index would be a business rule nobody wrote,
and it does not exist on SQLite, so the two engines would disagree about what is representable.
**M4.7 must break the tie deterministically and say how.**

**`sequence_no` uniqueness per template is not the invented-rule trap.** D-105 deferred
`matter_parties.sequence_no` because four meanings competed; here the meaning is settled and
structural — the order the engine reads stages in. Two stages at position 3 leave "what comes next"
undefined for the thing whose whole job is answering it. Noted for whoever builds a template editor:
PostgreSQL checks unique constraints per statement, so swapping two positions needs one statement, a
temporary out-of-range value, or a deferrable constraint.

### One CASCADE, with its consequence written down now

`workflow_stages.workflow_template_id` cascades: a stage is a line inside a configuration, not a
record the office keeps. **This constrains M4.7 —
`matter_stage_instances.workflow_stage_id` must be `RESTRICT` or nullable**, or deleting a template
would reach through the cascade and damage the history of Matters that ran it. The snapshot columns
exist precisely so an instance survives its stage definition.

### Three CHECKs, because Laravel's unsigned types are MySQL concepts

`version >= 1`, `target_days IS NULL OR target_days >= 0`, `sequence_no >= 1`. PostgreSQL maps
`unsignedInteger` to signed `integer` without complaint — the M4.1 lesson, applied before it could
bite and proven on the engine that enforces it.

### Data Scopes narrowed

`master.workflows.view` and `.manage` join `master.services.*` at `OFFICE` and `ALL`. A template is
Office-owned configuration: `OWN` would have to mean `created_by`, a column the table deliberately
lacks; `ASSIGNED` has no assignee; `TEAM` has no Team entity. Without the narrowing an administrator
could grant `master.workflows.view` at `OWN`, see it save, and hold a silently powerless grant. The
constant was renamed `MASTER_OFFICE_OWNED`, since it no longer holds one family.

### A recurring maintenance defect fixed rather than repeated

Four consecutive milestones edited the same hardcoded `--step` counts in the migration-reversibility
probes, and M4.6 would have been the fifth. **A literal step count decays**: once a later milestone
adds a migration, the test silently rolls back something other than the migration it names.
`rollbackStepsTo()` has been in `tests/Pest.php` since M1.10 for exactly this and had not been
adopted; the four Matter and Master Data probes now derive their counts from the migration they mean.

Five other guards narrowed rather than deleted, each in the milestone that made its claim stale.

---

## 2026-08-20 — M4.5 Matter ↔ Party participation

Branch `feat/m4-matter-workflow`. **One forward migration** (27 total) creating `matter_parties`.
**Four permissions — the count moves 173 → 177**, exactly as D-105 scheduled at M4.0 and in the
milestone that gives them routes. Backend **1907 passed + 7 skipped = 1914 tests / 6218
assertions** — 66 new. i18n **848 / 848** exact, 38 new keys per locale. One new decision,
**D-110**.

Ten routes, five per domain, nested under the Matter. There is deliberately no top-level
`/matter-parties` collection, and a participation belonging to another Matter answers **404**.

### The unique index the specification asked for, and why it is not there

The M4.5 specification required `UNIQUE (matter_id, party_id)`. D-105 and the `project_parties`
migration both refuse the equivalent, and the refusal won.

That index asserts one Party holds **at most one role** in a Matter — and the same person may
legitimately be a `SELLER` in their own right and an `AUTHORIZED_PERSON` for somebody else in the
same transaction. Whether that is permitted is a question about Indonesian notarial practice, not
about databases, and *a unique index is a business rule wearing an index's clothing*. No
`UNIQUE (matter_id, party_id, role_code)` either: it would assert the triple is the identity, and
would be meaningless while `role_code` is nullable. A test pins the behaviour — adding the same
Party twice under two roles succeeds — rather than pinning an index name.

If the office later decides duplicates are wrong, that is a rule to state and validate, not a
constraint to add quietly.

### Structural same-Office, and no support key added

```text
matter_parties (matter_id, office_id) -> matters (id, office_id)
matter_parties (party_id,  office_id) -> parties (id, office_id)
```

Both keys resolve through **one** `office_id` carrier, so the two endpoints cannot disagree with
each other. A cross-office participation is unrepresentable, **including for an actor holding
`ALL`**: `ALL` grants reach and administrative visibility, never permission to redefine domain
ownership. Unlike M3.4, which had to add `projects_id_office_id_unique`, both support keys already
existed — `parties` since M2.1, `matters` since M4.2, which added its for exactly this table. So
the migration adds none and drops none on rollback.

The carrier is written from the Matter and is never request input; it is withheld from mass
assignment alongside `matter_id` and `party_id`, and the Form Requests refuse all three on
**presence**, not emptiness (D-097).

### Column set transcribed, not designed

`notes` and `updated_at` are present because `03_DATABASE_ERD.md` §9 lists them. **`is_primary` is
absent** even though `project_parties` has one, because §9 does not list it — the two tables are
transcribed from their own field lists rather than made to match each other. No `updated_by`, so a
correction records *when* it happened and never *who* made it.

`role_code` stays nullable, opaque, `varchar(30)` matching `project_parties`: no enum, no
`Rule::in`, no `CHECK`, and **no dropdown in the interface**. The ERD's role codes are labelled
examples, and constraining the column would turn them into a catalogue.

`deleted_at`, `effective_from` and `effective_until` are absent. Removal is a hard delete of the
relationship row — the Matter untouched, the Party untouched, neither archived — and the
confirmation dialog says there is nothing to restore from. `sequence_no` and
`represented_by_party_id` are **refused with a 422**, not silently dropped: accepting and ignoring
them would teach a caller that the fields work.

### One Party-visibility implementation, not two

`ProjectParticipantVisibility` became `App\Domains\Party\ParticipantVisibility`, keyed on an Office
id, and M3.4's call sites moved to it in the same change.

Every question it answers — bulk `can_view_party`, the candidate query, re-resolving a submitted
`party_id` — depends on an Office and a Party subtype; the parent record contributed nothing but
`office_id`. Copying ~130 lines of security-critical code would have created two implementations of
the `parties.view` / `companies.view` rule, and **two copies of a security check drift silently**.
D-105 keeps `matter_parties` independent of `project_parties` **as data** — a statement about
tables and rows, not an instruction to write the Party permission rule twice.

### Managing participation is never authority to discover Parties

`*.matters.parties.manage` over the Matter is necessary but not sufficient. The candidate query
additionally applies `parties.view` to Individuals and `companies.view` to Companies, **each at its
own Data Scope and each independently**: an actor holding one branch and not the other sees only
that branch, and one holding neither gets an empty list rather than the whole Office. A submitted
`party_id` is re-resolved through that same authorized query. Nonexistent, another Office,
archived, and a subtype the actor cannot see produce **one indistinguishable 422**.

`view` and `manage` are independent **in both directions**. `manage` does not imply `view` — the
direction that matters more, since an actor who may edit the list is not thereby authorized to read
it — and `*.matters.update` reaches neither.

### No Party identity, and bulk evaluation

The stub is `id`, `display_name`, `party_type`, `is_archived`, `can_view_party` and nothing else:
no NIK, no NPWP, no `tax_id`, and **no masks**, since a mask is still a statement about a sensitive
value. A Party the actor cannot open **still appears** as a stub with `can_view_party = false` —
hiding it would misreport the Matter's composition to somebody authorized to read it. An archived
Party stays listed, marked archived, and is not offered as a candidate.

Visibility is computed in bulk, and the test measures it as a **comparison between two list sizes**
rather than against a guessed threshold: two participations and twenty-four cost the same number of
queries. A threshold only says "fewer than I guessed"; the comparison says the thing D-105 actually
requires.

### Frontend

A section on the Matter detail page, not a tab — following the Project precedent, and because the
repository's shadcn set has no `Tabs` component. It renders only when `can_view_parties` is true.
`MatterResource` gains `can_view_parties` and `can_manage_parties`, two flags because the two codes
are independent. Query keys stay domain-first:
`['matters', domain, 'detail', matterId, 'parties']`.

### Guards narrowed rather than deleted

Nine files moved. `MatterAuthorizationTest`'s "registers no matter participation permission yet"
inverted into "registers the four and no more"; its `.matters.` inventory narrowed to the lifecycle
codes it owns; its DELETE guard learned the difference between deleting a Matter and unlinking a
Party from one. `MatterSchemaTest`'s "builds no workflow or participation table" dropped
`matter_parties` and kept workflow. `MatterManagementTest`'s route inventory and payload guard both
narrowed — the latter had been matching the substring "parties" against the new capability flags,
so it now asserts the real claim: **the Matter payload embeds no participant list**.
`MatterReferenceTest` stopped keeping a second copy of the global permission total, which is pinned
once in `PermissionRegistryTest`. Three rollback step counts moved by one.

---

## 2026-08-18 — M4.4 Matter core management

Branch `feat/m4-matter-workflow`. **One forward migration** (26 total) that adds no column and no
table: it tightens `matters.matter_number` to `NOT NULL`, which M4.3 scheduled for the milestone
that gives Matter a creation path. **No permission** (173). Backend **1841 passed + 7 skipped =
1848 tests / 5998 assertions** — 50 new in `MatterManagementTest`, net +49 after guard narrowing.
i18n **810 / 810** exact, 79 new keys per locale. One new decision, **D-109**.

The first Matter milestone with an HTTP and frontend surface: create, list, detail, ordinary edit,
assign, complete, cancel.

### Eighteen routes, nine per domain, generated from one array

```text
GET    /api/v1/{domain}/matters
POST   /api/v1/{domain}/matters
GET    /api/v1/{domain}/matters/service-type-options
GET    /api/v1/{domain}/matters/{matter}
PATCH  /api/v1/{domain}/matters/{matter}
PATCH  /api/v1/{domain}/matters/{matter}/assignment
GET    /api/v1/{domain}/matters/{matter}/assignment/options
POST   /api/v1/{domain}/matters/{matter}/complete
POST   /api/v1/{domain}/matters/{matter}/cancel
```

`{domain}` is a **literal** `notary` or `ppat` segment in every registered route, never a wildcard.
The domain travels as a route **default** and `ResolvesMatterDomain` reads it back by name from
`$request->route()`.

**It is deliberately not a controller argument, and the reason is a defect this milestone hit.**
Laravel binds non-model route parameters positionally, so declaring `show(Request, Matter, MatterDomain)`
handed the method the Matter *id* where the domain belonged — a silent mis-binding that the type
declaration did nothing to prevent. Reading the default by name is order-independent, and a route
declaring no domain throws rather than guessing one. (`RouteRegistrar::defaults()` does not exist on
a group either, so the default is applied per route.)

**A Matter of the other domain answers 404, not 403.** Resolution is domain-constrained before
authorization is consulted, so a Notary address handed a PPAT id behaves as though nothing is
there. A 403 would confirm that a record exists in a domain the caller never named, making the
paired endpoints an existence oracle across the boundary `CLAUDE.md` §16 draws.

### The Office comes from the Project, never from the actor

`CreateMatter` reads `$project->office_id`. Taking it from the creating user would let somebody
with cross-Office reach create a Matter in Office A underneath a Project in Office B — precisely
what the composite foreign key `matters (project_id, office_id) -> projects (id, office_id)` exists
to make unrepresentable. **Seven fields are system-controlled** — `office_id`, `domain`,
`matter_number`, `status`, `pic_user_id`, and the reference allocation — and the Form Requests mark
them `prohibited` *and* refuse them on presence, so sending one is a 422 rather than a silently
ignored field. Allocation runs **inside the creating transaction**, so a rollback takes the counter
increment with it.

### One indistinguishable 422 for every ineligible reference

Wrong Office, wrong domain, retired, and nonexistent produce the same Service Type field error;
inactive, other-Office, and nonexistent produce the same assignee error. Distinguishing them would
let a caller enumerate another Office's reference data and staff list through error messages.

`pic_user_id` is validated `present` + `nullable`: **null means unassign, absent means a malformed
request.** No other combination separates those two.

`service-type-options` is authorized by the **Matter capability alone** — `viewAny` for the route's
domain — filtered to the actor's own Office, the route's domain, and active rows. No
`master.service_types.view` gate was invented for a picker.

### No status control, and this is a recorded gap rather than a silence

The canonical registry gives Matter `complete` and `cancel` and **no `change_status`**, unlike
Project. So `OPEN → COMPLETED` and `OPEN → CANCELLED` are the only reachable transitions, and
**there is no status dropdown anywhere in the Matter interface**. `IN_PROGRESS`, `WAITING`,
`ON_HOLD` and `ARCHIVED` remain vocabulary a filter can select on and a badge can render, and
nothing in the product can set them.

Inventing a `matters.change_status` code to close the gap would be inventing an authorization
surface the registry does not define — `CLAUDE.md` §62 in the small. The gap is recorded instead,
and M4.7 is where intermediate states properly come from. A test asserts **no status or stage route
exists**.

`complete` stamps `completed_at`; `cancel` records no reason, no timestamp, and **no history
table** — a lifecycle history has real questions inside it (who, when, why, and whether it is the
audit log's job) that M4.4 does not get to answer in passing.

### Not built

No Matter participation, no workflow, no stages, no deeds, no archive or restore.
`matters.change_stage` stays **registered and deferred** in both domains, and the Permission Matrix
reports it as such — the deferred badge's original case reappearing in a new module.

### Frontend

Eight locale routes across the two domains — list, `new`, detail, `edit` — plus **Notary** and
**PPAT** navigation groups carrying **Matters only**. Each is gated on its own `*.matters.view`
code, never a shared one, because the two capabilities are independent and the role matrix gives
Notary Staff and PPAT Staff different reach across the pair. Deeds, Minuta, Warkah and registers
are absent rather than rendered dark: a group whose every child is unreachable is a promise the
product does not keep.

Query keys are **domain-first** (`['matters', domain, …]`), so a Notary list and a PPAT list can
never share a cache entry.

### Guards narrowed rather than deleted

Nine guard files moved, following the standing discipline. `exposes no matter http surface in m4.2`
became `exposes no matter surface beyond the milestone that owns it`; `adds a nullable matter
number` became `requires a matter number on every matter`; and `leaves only the security settings
codes deferred` in the Party suite became `leaves no Party-domain code deferred` — it had been
pinning the *global* deferred set from inside a Party test, which stopped being the right subject
the moment another module registered a code ahead of its surface. The global set stays pinned once,
in `PermissionMatrixTest`. The reference-immutability guard lost its `null → value` case because
`NOT NULL` now makes it unrepresentable; `value → other` and `value → null` remain.

---

## 2026-08-17 — M4.3 Matter internal reference foundation

Branch `feat/m4-matter-workflow`. **One forward migration** (25 total). **No permission** (173):
allocation is system-controlled infrastructure, not a user capability. Backend **1792 passed + 7
skipped = 1799 tests / 5850 assertions** — 44 new. i18n **731 / 731** exact. One new decision,
**D-108**.

**Backend foundation only** — no route, controller, request, resource, frontend page, or
navigation entry. The allocator exists now; M4.4 integrates it into the real `CreateMatter`
transaction.

```text
N-YYYY-NNNNNN     Notary
P-YYYY-NNNNNN     PPAT
```

Ordinary office identification and nothing more — not a deed number, a repertorium number, a
minuta or Warkah number, a PPAT register entry, or any government numbering.

### Three namespace dimensions, and a dedicated counter

`matter_reference_counters`, primary key `(office_id, reference_year, domain)`. Project counts per
Office and year; Matter adds the domain, because a shared counter would make `N-2026-000001` and
`P-2026-000001` compete for one value. Office A's Notary and PPAT sequences, Office B's Notary
sequence, and Office A's next-year sequence are four independent counters, each starting at 1 —
verified as four counter rows on PostgreSQL.

**The M3.2 allocator is reused as a pattern, never as a table.**
`13_M3_PROJECT_ARCHITECTURE.md` §9 refused to generalize it into anything Matter-shaped, and the
generic configurable numbering engine `03_DATABASE_ERD.md` §27 sketches — prefix patterns, monthly
resets, `master.numbering.*` — is deliberately not used. A test asserts the Project counter gained
no `domain` dimension when Matter got its own.

### One atomic statement

`INSERT … ON CONFLICT (office_id, reference_year, domain) DO UPDATE SET last_value = last_value + 1
RETURNING last_value`. The increment happens inside the database against a row the engine locks for
the duration of the upsert, so two concurrent callers cannot both compute the same value — neither
computes it at all. `MAX+1`, `COUNT+1`, `latest()+1` and read-then-write are forbidden and guarded
by a comment-stripped source scan, and a transaction alone would not fix a `SELECT`-then-`UPDATE`
because under `READ COMMITTED` two transactions can both read before either writes.

**Concurrency evidence is taken on PostgreSQL only.** 16 simultaneous OS processes, 25 allocations
each, in one namespace: **400 values, all distinct, contiguous 1–400, the counter landing exactly
on 400**, and the other three namespaces untouched. SQLite runs the identical statement, so there is
one execution path — but it proves nothing about contention and is not claimed to.

**The allocator opens no transaction of its own** and commits nothing, so it participates in the
caller's. The consequence is stated rather than hidden: the counter row stays locked from allocation
until that transaction ends, serialising concurrent creates *within one Office-year-domain* for the
duration of a single insert. The namespace split means Notary and PPAT creates never block each
other.

### Gaps, precisely

If allocation and insert share a transaction that rolls back, **the counter increment rolls back
with it and the number is not lost** — proven by test. If an allocation **commits** and is then not
used, the number is permanently skipped. Both are acceptable: this is an internal identifier, not
legal numbering, and nothing may treat the sequence as a record count.

### Schema

`matters.matter_number` `varchar(32)`, **nullable in M4.3**, `UNIQUE (office_id, matter_number)`.
Nullable for the M3.2 reason: no creation path allocates yet, so `NOT NULL` would make Matter
unwritable for a whole milestone including by its own factory. M4.4 tightens it.

**`domain` is deliberately absent from that unique key** — the formatted string already carries the
prefix, so the two domains cannot collide as strings, and including it would permit
`N-2026-000001` twice in one Office if the domains differed. Verified: one Office holds both
`N-2026-000001` and `P-2026-000001`; two Offices each hold `N-2026-000001`; the same reference twice
in one Office is refused by the unique index.

**A nullable-aware CHECK enforces prefix–domain agreement** and only that — a NOTARY Matter carrying
`P-2026-000001` is refused by the database, independent of PHP. Full format correctness stays in
`MatterReference`; turning PostgreSQL into a second parser would duplicate the rule where it is
harder to read.

**Two counter CHECKs exist because Laravel's unsigned types do not.** `unsignedSmallInteger` and
`unsignedInteger` are MySQL concepts and PostgreSQL maps both to signed columns, so
`reference_year >= 0` and `last_value >= 0` are what actually enforce the claim — the M4.1
`default_duration_days` lesson applied before it could bite. A negative counter is refused.

### No backfill, and the evidence for it

**The persistent development database has no `matters` table at all** — it is still at 22
migrations, so no Matter row has ever existed outside an in-memory test or a disposable
verification database. This was inspected rather than inferred from "no route exists". Nothing was
backfilled and no ordering was invented.

### Immutability, stricter than Project's

`null → value`, `value → other value`, and `value → null` are **all** refused. The Project guard had
to permit `null → reference` while M3.2's column was nullable; Matter starts strict because its
create path does not exist yet to have relied on the looser rule, and M4.4 stamps inside the
creating transaction rather than numbering a Matter afterwards. The guard fires on `updating` only,
so it never blocks the stamp itself.

### Six digits are a minimum

The 1 000 000th reference formats as seven digits rather than wrapping to `000000` or truncating —
either of which would silently break uniqueness. `varchar(32)` is sized for it. The M3.2 rule
adopted verbatim.

`MatterReference` exposes exactly `prefix`, `format`, and `matchesFormat` — **a formatter, not a
parser**, asserted by reflection. The prefix map lives there rather than on `MatterDomain`, keeping
an authorization type free of presentation concerns.

### Three guards narrowed and two stale mockups corrected

`MatterSchemaTest` asserted the reference was deferred to M4.3 — intentionally falsified, narrowed
to assert the column exists and is still nullable. `ServiceTypeSchemaTest`'s rollback probe now
takes three steps as each milestone layers on the one below. And `ProjectSchemaTest` listed
`matter_reference_counters` among tables that must not exist, as a stand-in for "the Project
counter got generalized" — M4.3 creates it as a **separate, dedicated** table, which is what M3.2
said should happen rather than what it warned against, so the guard now checks the Project counter
gained no `domain` dimension instead.

**`04_UI_DESIGN_SYSTEM.md` showed five-digit references** — `N-2026-00312` and `P-2026-00128` —
contradicting the locked six-digit minimum in `03_DATABASE_ERD.md` §27 and `CLAUDE.md` §38.
Corrected to `N-2026-000312` and `P-2026-000128`. Mockup text only; no UI was redesigned.

### Verification

```text
Backend        1792 passed + 7 skipped, 5850 assertions — 44 new
Pint           passed
composer       validate --strict passed
Frontend       format:check, lint, typecheck, build all clean
i18n           731 / 731 exact — no UI string added
Migrations     25, none pending
Permissions    173 registry / 173 database, sync idempotent twice
Disposable DB  full chain from empty PostgreSQL; migrated 0 -> 25; counter schema, PK,
               FK and all three CHECKs verified; sequential allocation, domain, Office
               and year independence proven; 16-process contention giving 400 distinct
               contiguous values; wrong prefix, duplicate reference and negative counter
               all refused; same reference accepted in a second Office; M4.3 rolled back
               alone leaving exact M4.2 state (24, no counter table, no column, M4.2
               constraints intact); rolled back all 25 leaving only an empty migrations
               table; re-migrated; allocator re-verified; dropped; absence proven
```

The 7 skips are PostgreSQL-only assertions — two new CHECK tests plus the five carried from M4.1
and M4.2 — which in-memory SQLite cannot reproduce. All are proven against real PostgreSQL.

No HTTP smoke: M4.3 exposes no route. O-034 remains open and untouched.

**Persistent development database unchanged**: all sixteen tracked counts identical before and
after, still at 22 migrations with none of `service_types`, `matters`, or
`matter_reference_counters` present.

### Open items

None opened, closed, or modified. O-010, O-018, O-031, O-032, O-033 and O-034 are unchanged.

**M4.4 has not started.** No `CreateMatter`, no Matter route, controller, request or resource, no
assignment or status surface, no participation, no workflow, and `matter_number` is not yet
`NOT NULL`.

---

## 2026-08-17 — M4.2 Matter schema and authorization foundation

Branch `feat/m4-matter-workflow`. **One forward migration** (24 total). **No permission** (173):
the sixteen Matter codes were already canonical. Backend **1750 passed + 5 skipped = 1755 tests /
5742 assertions** — 103 new. i18n **731 / 731** exact. One new decision, **D-107**.

**Backend foundation only** — no route, controller, request, resource, frontend page, or
navigation entry, following M2.1, M3.1 and M4.1. `matters` is the M4 root, one table with a
`domain` discriminator; no `notary_matters`, no `ppat_matters` (D-102).

### Two invariants the database enforces, not the application

```text
matters (project_id, office_id)              -> projects (id, office_id)
matters (service_type_id, office_id, domain) -> service_types (id, office_id, domain)
```

The first makes a Matter whose Office disagrees with its Project's **unrepresentable** — Office is
inherited from the parent (D-099), never caller-selected.

The second is **one key doing two jobs**: same Office *and* same domain, so a Notary Matter
classified with a PPAT service cannot exist. That required adding `UNIQUE (id, office_id, domain)`
to `service_types` in the same migration — M4.1 shipped only `(id, office_id)`, and a composite
foreign key needs a unique index on exactly the columns it references. `service_type_id` stays
nullable and PostgreSQL treats a composite key with a NULL component as satisfied, so an
unclassified Matter remains valid. **Never `SET NULL`**: erasing a classification because a
catalogue was tidied would lose data a historical record depends on.

`matters` also gains `UNIQUE (id, office_id)` — the support key M4.5's `matter_parties` will
reference, the M4.1 pattern of one index now against a second migration later.

### Deferred, and deliberately not stubbed

`matter_number` belongs to M4.3 **with its allocator**, and `current_stage_id` to M4.7 **with the
real stage-instance foreign key**. Neither exists as a nullable placeholder: the first would be a
column somebody fills in wrongly and the second a pointer validated by nothing (D-095's rule,
applied for the third time).

`deleted_at` exists as **reserved schema capability** and the model uses **no `SoftDeletes`**. The
trait would install a global scope silently filtering every query — including `MatterVisibility` —
making "invisible because soft-deleted" indistinguishable from "unreachable by scope", and would
settle visibility semantics before the milestone that owns archiving exists to settle them.
`ARCHIVED` remains a business status, never soft deletion.

### The domain comes from the caller, never the row

One `MatterPolicy`, and every ability takes an explicit `MatterDomain` that selects the permission
namespace. Reading `$matter->domain` to *choose* the permission would be the new authorization
shape `13_M3_PROJECT_ARCHITECTURE.md` flagged; route-derived namespacing keeps the question
ordinary. A **separate** rule keeps the row honest — the supplied domain must equal the persisted
one or the ability refuses — and at M4.4 the route binding turns that into the canonical 404
(D-101). The two answer different questions, and collapsing them would reinstate the row-derived
namespace by the back door. A source guard pins it: `$matter->domain` appears exactly once in the
Policy, in the equality check.

Eight abilities, each answering to its own code, **none implying another** — proven exhaustively:
holding any one of the six record capabilities authorizes that one and refuses the other five.

### Creation

Four conditions, and the third is the one worth naming: the domain's own `create` code at a scope
that can describe a record about to exist (`OWN`, `OFFICE`, `ALL` — **`ASSIGNED` cannot**, because
a new Matter has no PIC, and an actor holding `ASSIGNED` *and* `OFFICE` creates normally);
**`projects.view` on the parent**, the minimum coherent proof somebody may open work beneath it and
the *only* place Matter authorization consults the parent; **the parent in the actor's own Office,
refused even at `ALL`** (D-097's ruling, one domain across); and a Project that is **not archived**,
which falls out of using the canonical reach check rather than a separate lookup.

**Parent Project reach confers no Matter access** — an actor holding `projects.view` and
`projects.update` at `OFFICE` reaches no Matter at all — and two source guards forbid the branches
that would change that: no project join in `MatterVisibility`, and no stage-assignment branch.

### Scope rules

**Fourteen codes, not sixteen.** Every actionable Matter capability gets `OWN`, `ASSIGNED`,
`OFFICE`, `ALL`; `TEAM` is withheld. **Both `view_all` codes are excluded** and consulted by no
ability — an actor holding only `view_all` at `ALL` reaches nothing, proven for both domains
(D-090).

`create` needs no special entry, and that is worth stating because it looks as though it might: the
`ASSIGNED` exclusion belongs to the predicate, not the assignable-scope list. Encoding it in the
rules would confuse *what may be granted* with *what a grant can match*.

### Enums

`MatterDomain` is **its own enum** rather than a reuse of `ServiceTypeDomain` — Matter is not a
master-data concept, and naming its domain after that type would make the aggregate depend on a
master-data detail. A parity test keeps the two value lists identical so a divergence must be
deliberate. `priority` **reuses `ProjectPriority`**, whose docblock already records that the ERD
names the column on projects, matters and tasks and defines the vocabulary exactly once.

### Five guards narrowed, one of them M4.1's own

`ProjectSchemaTest`, `ProjectPartySchemaTest`, `ProjectLifecycleTest`, `PartySchemaTest` and
`ServiceTypeSchemaTest` all asserted that `matters` does not exist. Each keeps every other table and
retains the point it was really making — no foreign key or column reaching into a later milestone.
`ProjectLifecycleTest`'s **route** assertions were left untouched and still pass, because M4.2 ships
no Matter endpoint.

A sixth was narrowed for a different reason: `ServiceTypeSchemaTest`'s migrate/rollback probe rolled
back one step while `service_types` was the newest migration. `matters` now is, and it holds a
foreign key into that table, so the probe rolls back two.

### Verification

```text
Backend        1750 passed + 5 skipped, 5742 assertions — 103 new
Pint           passed
composer       validate --strict passed
Frontend       format:check, lint, typecheck, build all clean
i18n           731 / 731 exact — no UI string added
Migrations     24, none pending
Permissions    173 registry / 173 database, sync idempotent twice
Disposable DB  full chain from empty PostgreSQL; migrated 0 -> 24; every constraint,
               index and support key verified; ten database invariants proven, including
               cross-office Project refused, cross-office Service Type refused,
               same-Office wrong-domain Service Type refused, null Service Type accepted,
               invalid domain/status/priority refused, and RESTRICT on both parents;
               M4.2 rolled back alone leaving exact M4.1 state (23, no `matters`, M4.1's
               support key kept and M4.2's removed); rolled back all 24 leaving only an
               empty migrations table; re-migrated; dropped; absence proven
```

The five skips are PostgreSQL-only assertions — three CHECK constraints here plus M4.1's two —
which in-memory SQLite cannot reproduce. All are proven against real PostgreSQL in the disposable
run.

No HTTP smoke: M4.2 exposes no Matter route, and no temporary endpoint was manufactured to create
one. O-034 remains open and untouched.

**Persistent development database unchanged**: all sixteen tracked counts identical before and
after, still at 22 migrations with neither `service_types` nor `matters` present — M4.1's and
M4.2's migrations have never been applied to it.

### Open items

None opened, closed, or modified. O-010, O-018, O-031, O-032, O-033 and O-034 are unchanged.

**M4.3 has not started.** No `matter_number`, no allocator, no Matter route, controller, resource,
or UI, no `matter_parties`, no workflow tables, no extension tables.

---

## 2026-08-17 — M4.1 Service Type master-data foundation

Branch `feat/m4-matter-workflow`. **One forward migration** (23 total). **No permission** (173):
both `master.services.*` codes were already canonical. Backend **1650 passed + 2 skipped = 1652
tests / 5474 assertions** — 61 new. i18n **731 / 731** exact, because a backend foundation adds no
UI string. One new decision, **D-106**.

**The two skips are the first in this suite and are deliberate.** Both assert database behaviour
that only PostgreSQL has — the `domain` CHECK and the non-negative duration CHECK — and the test
connection is in-memory SQLite, which cannot add a CHECK after the fact and accepts a negative
integer into an `unsigned` column. Asserting either there would pin behaviour the production engine
does not share. Both are proven instead against real PostgreSQL in the disposable-database run
below, which is where they belong.

**Backend foundation only** — no route, controller, request, resource, frontend page, or navigation
entry, following the M2.1 and M3.1 precedent. `service_types` is the first master-data table in the
repository and the first anywhere to carry bilingual `name_id` / `name_en` columns.

### Office-owned, and that settles the authorization question

The ERD gives `service_types` an `office_id`; the genuinely global tables — roles, permissions —
carry none. So the `allowsGlobally()` pattern D-044 built for Role definitions does **not** apply,
and the answer lands on the **Party** side rather than the Project side:

```text
OFFICE   service_types.office_id = actor office
ALL      cross-office reach
OWN      withheld — would have to mean created_by, and there is no such column
ASSIGNED withheld — nobody is the PIC of a catalogue entry
TEAM     withheld — no Team entity (D-042)
```

D-080's reasoning transfers exactly: a Service Type is a **shared reference record**, and the
colleague who typed it in has no claim on the service the office offers. `PermissionScopeRules`
offers exactly the two scopes the visibility class can honour, so an administrator cannot save a
silently powerless grant — the dead control D-080 named. **Only the Service Type family is
narrowed**; the other twelve `master.*` families keep the permissive default because their domains
are still undesigned.

`view` and `manage` stay independent, and **`manage` does not imply `view`** (D-098's answer).
**Creation always lands in the actor's own Office, including for `ALL`** — reach over existing
records is not authority to decide where a new one belongs.

### Identity versus content

Office, `code` and `domain` are **identity**, and the model refuses to change any of them after
creation. Other records classify themselves by all three: `code` is the handle, `domain` decides
which Matter surface may offer the service at all (D-101), and Office is the security boundary.
Both names, both descriptions, `sort_order` and `default_duration_days` are ordinary content.

`code` is a stable classification handle — **never an internal reference and never legal
numbering** (D-103). Stored exactly as submitted with **no case normalization**, because no
canonical document defines one. `UNIQUE (office_id, code)` is composite and never global, the
O-023 shape for the same reason, and **`domain` is deliberately outside that namespace** so one
code cannot mean two things in one Office.

**`UNIQUE (id, office_id)` is added now, ahead of its use** — the support key M4.2's
`matters.service_type_id` will reference through a composite foreign key. A deliberate exception to
"add nothing on speculation": the shape is already fixed by D-105 and the two precedents that
built it, and it costs one index today against a second migration later.

### Retirement, not deletion

`is_active` is the whole lifecycle. No delete, no soft delete, no archive, no restore — and no
canonical code that could authorize one. The ERD lists `is_active` and no `deleted_at`, and the
`offices` migration set the precedent in the same words. It is also the only choice that survives
M4.2: a Matter referencing a deleted Service Type would lose the classification a historical record
depends on (`CLAUDE.md` section 63). **Inactive means unavailable for new selection, never erased
from history**, which is why the future Matter foreign key must be restrictive and never
`SET NULL`.

**`legal_term` and `preserve_legal_term` are withheld.** They appear in the ERD field list and are
defined nowhere else, while a separate `legal_terms` table carries its own `preserve_original_term`
concept — a foreign key, a free-text term, and a display-fallback flag are all plausible readings.
Withheld until validated, exactly as M3.1 withheld `project_number` (D-095).

**Zero production rows.** The factory emits `UJI_` codes and `Layanan Uji` names rather than
plausible legal services somebody could later copy into a seeder.

### The disposable database caught a false claim

The migration originally documented `unsignedInteger` as making `default_duration_days`
non-negative. **PostgreSQL has no unsigned integer type and silently maps it to `integer`** —
proven by inserting `-1`, which the database accepted. An explicit CHECK now enforces
non-negativity beside the domain one, and the comment says what is actually true. The local suite
would never have caught it: SQLite's dynamic typing accepts the value too, so the assertion is
PostgreSQL-only and was skipped there.

### Two M3-era guards narrowed rather than deleted

`ProjectSchemaTest` and `ProjectPartySchemaTest` both asserted that `service_types` does not
exist — true when written, and intentionally made false by this milestone. Each keeps every other
table on its list and gains the assertion the test was always really about: **Project and
participation gain no foreign key into any later milestone**, so `projects.service_type_id` and
`project_parties.service_type_id` are now pinned absent. The same treatment M3.1 and M3.4 applied
to the M2-era guards they invalidated.

### Two stale authorization comments corrected

`RolePolicy` stated in the present tense that *"Spatie registers a `Gate::before` that grants any
ability matching a permission the user holds"*, citing O-027. **D-048 resolved that** —
`register_permission_check_method` is `false`, the package registers no callback, and a test
asserts zero. `UserPolicy` already said the correct thing. `PermissionPolicy` cited O-027 as live
reasoning. Both now record what changed and why the naming convention still holds — the D-077
shape, found during M4.1 reconnaissance and fixed rather than carried into a new Policy modelled on
them.

### Verification

```text
Backend        1650 passed + 2 skipped, 5474 assertions — 61 new
Pint           passed
composer       validate --strict passed
Frontend       format:check, lint, typecheck, build all clean
i18n           731 / 731 exact — no UI string added
Migrations     23, none pending
Permissions    173 registry / 173 database, sync idempotent twice
Disposable DB  full chain from empty PostgreSQL; migrated 0 -> 23; every constraint
               verified; invalid domain, negative duration, duplicate (office_id, code)
               and Office deletion all refused by the database; same code in a second
               Office accepted; M4.1 rolled back alone leaving exact M3 state (22, no
               service_types, project_parties intact); rolled back all 23 leaving only an
               empty migrations table; re-migrated; dropped; absence proven
```

No HTTP smoke: M4.1 exposes no route, controller, or resource, so there is no Service Type HTTP
behaviour to exercise and no temporary endpoint was manufactured to create one. O-034 remains open
and untouched.

The persistent development database was compared before and after: **all sixteen tracked counts
identical**, still at 22 migrations, and no `service_types` table — M4.1's migration was never
applied to it.

### Open items

None opened, closed, or modified. O-010, O-018, O-031, O-032, O-033 and O-034 are unchanged.

**M4.2 has not started.** No `matters` table, no Matter Policy, no workflow anything.

---

## 2026-08-17 — M4.0 Matter and Workflow architecture lock

Branch `feat/m4-matter-workflow`, opened from the accepted `main` tip `c17684e`.
**Documentation only.** No migration (22), no permission (173), no model, policy, controller,
route, request, resource, factory, or frontend page. Backend **1591 tests / 5329 assertions**
unchanged; i18n **731 / 731** exact, because an architecture lock adds no UI string.

Seven new decisions, **D-099 through D-105**, and a new canonical document:
`14_M4_MATTER_ARCHITECTURE.md`.

### What M4 is, and the sentence that governs its size

> **M4 builds a configurable workflow mechanism. It seeds no workflow content, because none
> exists.**

`08_NOTARY_WORKFLOW.md` and `09_PPAT_WORKFLOW.md` are still `DRAFT — DOMAIN VALIDATION REQUIRED`,
each stamped `DO NOT IMPLEMENT FROM THIS DOCUMENT YET`, and between them they carry sixteen
unanswered domain questions. Section 4 of both is explicit that the structural vocabulary they do
carry consists of *"architectural facts, not legal rules"* — and that distinction is the only
reason M4 can proceed at all. The engine's **shape** is canonical; its **content** does not exist.

Stated plainly rather than as a limitation: **the office's actual workflow is blocked on domain
validation, not on engineering.** When a qualified source completes those two documents, the
content becomes configuration entered through the master-data surfaces, and no schema change
should be needed to accept it (D-104).

### The rulings

**Matter is a required child of Project, and its Office is inherited** *(D-099)*.
`matters.project_id` is required; one Project may hold many Matters; a Project with zero Matters is
complete, not a draft. Office is inherited from the parent at creation and immutable during M4,
enforced **structurally** by `(project_id, office_id) -> projects (id, office_id)` — reusing the
`UNIQUE (id, office_id)` support key `projects` has carried since M3.4, at no migration cost. A
Matter whose Office disagrees with its Project's is *unrepresentable*, the pattern already proven
for `company_people` (D-080) and `project_parties` (D-098).

**Matter authorization is independent of Project authorization** *(D-100)*. This is deliberately
the harder answer. "If you can see the Project you can see its Matters" would make Project reach a
silent superset of Matter reach, so an administrator granting `projects.view` would have granted
Notary and PPAT work visibility without ever naming those capabilities. **One interaction
survives**: creating a Matter validates the parent Project through canonical Project authorization,
because a parent is being chosen. Reading, updating, assigning, completing and cancelling answer
only to their own capability.

Matter Data Scope: `OWN` = `created_by`, `ASSIGNED` = `matter.pic_user_id`, `OFFICE` =
`matter.office_id`, `ALL` = cross-office reach, `TEAM` = no grant. And the rule that will be
tempting to break at M4.7: **`matter_stage_instances.assigned_user_id` does not count toward Matter
`ASSIGNED`** — that would be a new grant wearing an existing scope's name, the failure D-088 named
one milestone earlier, one domain across. D-088's rule holds in the other direction unchanged, so
neither leaks.

**Domain-split routes, and the namespace comes from the path** *(D-101)*. `/api/v1/notary/matters`
and `/api/v1/ppat/matters`; the generic `/api/v1/matters?domain=…` form is refused. This is the
ruling that keeps M4 inside the authorization shape the codebase already has:
`13_M3_PROJECT_ARCHITECTURE.md` section 12 flagged the alternative as *"a genuinely new
authorization shape"* — a Policy selecting its permission namespace from the record it is being
asked about. Route-derived namespacing makes the question ordinary again. For an existing Matter
the persisted `domain` must match the route, and a mismatch answers **404** through the canonical
binding convention, for the D-098 reason: a 403 would confirm the record exists in a domain the
caller did not name.

**The root only** *(D-102)*. One `matters` table with a `domain` discriminator. **Neither
`notary_matters` nor `ppat_matters` is built**, and no field standing in for one is persisted —
`deed_category`, `requires_minuta`, `requires_register_entry`, `land_office_region`,
`tax_processing_required`, `registration_required` all belong to **M6 and M7** with their domain
content. M4 owns the service-type container but seeds no catalogue, so **`service_type_id` is
nullable**: requiring it would make Matter uncreatable for as long as the catalogue is empty, which
is the D-095 lesson in reverse.

**No Matter archive or restore** *(D-102)*. The canonical registry gives Matter eight codes per
domain and neither is `archive` nor `restore` — unlike Project, which has both. The absence is the
registry's and M4 does not fill it by invention. `deleted_at` may exist as reserved schema
capability with **no API lifecycle reaching it**: a column without a surface is honest; a surface
without a permission is not. And the trap worth naming, because Matter has no restore path to
recover from a wrong answer: **`CANCELLED`, `COMPLETED` and `ARCHIVED` are business statuses and
never synonyms for soft deletion.** No transition matrix is invented (D-091's reasoning, one domain
across).

**Matter reference** *(D-103)*: `N-YYYY-NNNNNN` and `P-YYYY-NNNNNN`, both transcribed from
`CLAUDE.md` section 38, allocated per **Office + calendar year + domain** — three components,
because a shared counter would make the two prefixes compete for one value. A **dedicated**
allocator: `13_M3_PROJECT_ARCHITECTURE.md` section 9 refused to generalize the Project one into
anything Matter-shaped, and M4 honours that refusal rather than reversing it by extending the same
table. The M3.2 *pattern* is reused — one atomic upsert-returning statement — but not the table. No
`MAX+1`, no `COUNT+1`, no read-then-write. Gaps carry no meaning; a reference is immutable once
assigned.

**Matter participation is independent of Project participation** *(D-105)*: not inherited, not
copied, not synchronized. Project participants may later serve as **candidate context** only —
convenience for whoever is typing, not a data relationship, because two tables that silently mirror
each other drift apart and the drift is found by somebody reading the wrong one. The same-Office
invariant is structural again, through one `office_id` carrier and two composite foreign keys, and
`matters` gains its own `UNIQUE (id, office_id)` for it.

Four permissions are expected at **M4.5**, moving the count **173 → 177**: the participation pair,
per domain. Four rather than two because the role matrix gives Notary Staff full access to Notary
Matters and view-only on PPAT Matters, and the reverse for PPAT Staff — one pair spanning both would
hand each of them the other's participation. `view` and `manage` independent, `manage` not implying
`view` (D-098). **Not registered at M4.0.**

**Two ERD fields deliberately not built** *(D-105)*. `represented_by_party_id` is
**DOMAIN VALIDATION REQUIRED** — a Party acting through another Party is representation, proxy, or
legal capacity, and which it means has no canonical answer here. `sequence_no` is deferred because
display order, signing order, legal priority and appearance order are four different things the
column name distinguishes between none of, and a wrong guess stays invisible until a deed is
drafted from it. `role_code` stays nullable and opaque; the ERD's `SELLER` / `BUYER` /
`SELLER_SPOUSE` / `DIRECTOR` / `COMMISSIONER` codes are labelled examples and are not promoted.

### Stale and contradictory documentation corrected

**1. `06_API_CONVENTIONS.md` contradicted itself about Matter routes.** It showed a generic
`/matters` in section 13, `GET /api/v1/matters?domain=PPAT` in section 11, and
`POST /api/v1/matters/{id}/move-stage` in section 22 — while *the same section 22* already used
domain-prefixed paths for deeds and Warkah. Three canonical sources disagreed with the generic
form and the fourth disagreed with itself. Corrected in all three places, with the supersession
recorded rather than silently applied.

**2. `02_MENU_AND_PERMISSIONS.md` still asserted the permission count was 171.** In the M2.5
subsection on composed navigation entries — *"No such permission exists, and the count stays at
171."* The count has been **173** since M3.4. This is the same class of defect M3.5 fixed in eight
other places and **did not catch here**; the M2.5 context is preserved and the current total stated
beside it.

**3. The ERD's `matter_parties` recorded neither the Office carrier nor the two deferrals.** It
lists the field set without `office_id`, exactly as it listed `project_parties` without one, and
gives `represented_by_party_id` and `sequence_no` no status at all. All three are now recorded
where a reader will find them, so the carrier reads as a decision rather than an oversight.

**4. `13_M3_PROJECT_ARCHITECTURE.md`'s unresolved-items table carried two rows that were M4's.**
Project-to-Matter cardinality is now resolved by D-099; "whether Matter workers need Project
visibility" is answered in one direction by D-100 and remains open in the other. The M3-era wording
and the `Blocks M3.1?` column are left intact rather than rewritten to read as though they had
always known the answer.

### Verification

```text
Backend        1591 tests, 5329 assertions — unchanged
Pint           passed
composer       validate --strict passed
Frontend       format:check, lint, typecheck, build all clean
i18n           731 / 731 exact — no UI string added
Migrations     22, none pending
Permissions    173
Matter files   none — no migration, model, policy, route, resource, or page created
```

No disposable database and no HTTP smoke: M4.0 changes no schema and no code path, so there is
nothing for either to exercise. The M1.10, M2.6 and M3.5 gates ran them because those milestones
touched behaviour; M3.0 and M2.0, the comparable architecture locks, did not.

### Open items

No open item was opened, closed, or modified. O-010, O-018, O-031, O-032, O-033 and O-034 remain
exactly as they were.

**M4.1 has not started.** No Matter implementation exists.

---

## 2026-08-17 — M3.5 M3 Quality Gate

Branch `feat/m3-project-matter`. **No migration** (22 total) and **no permission** (173). An
audit milestone: no new product capability, no M4, no merge to `main`. Backend **1591 tests /
5329 assertions**, unchanged — every fix was to a comment or a document, and none needed new
coverage. i18n **731 / 731** exact.

### What the gate found

Nine findings, and every one is the shape M1.10 named (D-077): a claim the repository made about
itself that had quietly stopped being true, or a premise that changed without anything recording
the consequence. M3.4 and M3.3 between them are what expired all nine.

**1. Eight live sites still said the canonical catalogue holds 171 permissions.** M3.4 moved the
total to 173 and pinned it in one place — `PermissionRegistryTest` — precisely so a legitimate
addition would not have to be applied in six files. That worked for the *assertions*. It did not
reach the prose, and the number had been copied into five source files and two test comments:
`AuthorizationState`, `EffectiveAccessResolver`, `DefaultRoleRegistry`,
`BootstrapDeploymentCommand`, `role-permission-matrix.tsx`, `EffectiveAccessProjectionTest`, and
`BootstrapDeploymentTest`, plus `navigation.ts`.

In every one of them the number was **incidental** — the point was "one permission and the whole
catalogue", "one round trip per permission", "the matrix cannot be turned into codes and scopes
without inventing the mapping". So the fix is not a re-pin to 173, which would rot again on the
next legitimate addition; each now says what it actually means. D-035 keeps the historical
figure, marked as the count when that decision was recorded.

**2. The deferred-permission badge documented itself with an example that M3.3 falsified.**
`PermissionController` explained the flag with *"`projects.create` needs no flag because Projects
is absent from the navigation entirely"*. M3.3 put Projects in the sidebar and gave every
`projects.*` lifecycle code a route. The example now uses `notary.matters.create`, whose module
genuinely is absent, and the correction says when the old one expired.

**3. `projects.view_all` now matches the badge's shape and is still deliberately unbadged.**
This is what made finding 2 worth more than a comment fix. Once Projects reached the navigation,
`projects.view_all` became exactly what the deferred list describes: registered, routeless, and
inside a module the interface presents as working. It stays off the list, and the reason is now
written where the list lives — **deferred means not built yet, and this one will never be built.**
D-090 supersedes it by Data Scope `ALL`, permanently, and no `view_all` code may be backend reach
authority. Badging it would promise an implementation that is refused rather than pending. No
route and no alternate authorization path was added.

**4. The M3 architecture lock contradicted itself about its own permission count.** Section 11
opened *"M3 adds no permission. The canonical count stays at 171"* while section 8 of the same
document recorded `171 -> 173`. The M3.0 position is preserved as the scoped historical claim it
was, and the sentence now says which part of it expired and when.

**5. `ProjectResource` said "Project references no Party yet".** False since M3.4. The resource
genuinely carries no participant collection — that is a separate nested surface with its own
Resource — but the *reason* had inverted, and the wording claimed Project touched no Party at all.

**6. The ERD described `project_number` as nullable.** M3.3 tightened it to `NOT NULL` by forward
migration (D-097), and `03_DATABASE_ERD.md` still described the M3.2 state. This was the sharpest
of the documentation findings, because the ERD is the canonical schema document and a reader had
no way to discover the constraint from it. Confirmed `NOT NULL` against real PostgreSQL before
the text was changed. The `project_parties` section, by contrast, was already fully current.

**7–9. Three dated deferrals pointing at a delivered milestone.** `Project`, `ProjectController`,
and `project.ts` each described participation as something M3.4 *would* own. The facts they
guarded are still true; only the tense pointed forward.

### Re-verified rather than corrected

**No N+1.** M2.6's defect was a per-row `EffectiveAccessResolver` call, and a participation list
has exactly that shape. M3.4 had already applied the lesson — `ProjectParticipantVisibility`
resolves once per subtype branch and asks the record predicate in bulk — and both list surfaces
are pinned by tests asserting the query count is **constant** rather than merely smaller. The
gate confirmed the tests exist and pass rather than trusting the docblock that claims it.

**O-018 re-verified, and its wording corrected.** next-intl is still **4.13.5** and
`setRequestLocale` is still load-bearing in exactly three files. But the register said the package
"contains no reference to `next/root-params`", and that is no longer literally true: the
`@deprecated` notice in `RequestLocaleCache` links the migration blog, in the compiled module and
its type declaration. Those two strings are the only occurrences and they are a pointer, not an
implementation, so migration is still blocked upstream — but a claim that is checked by counting
matches should say what it counts.

**O-031, O-032, O-033 unchanged**, each confirmed against the code rather than carried on trust:
the directory Office filter is still derived from the page in view, the frontend still has no test
runner, and the six fields are still translated in both locales while no component collects or
displays them. O-010 unchanged — `gh` is still absent, so CI remains the user's to observe.

### Verification

```text
Backend        1591 tests, 5329 assertions — unchanged, no new coverage needed
Pint           passed
Frontend       format:check, lint, typecheck, build all clean
composer       validate --strict passed
i18n           731 / 731 exact
Migrations     22, none pending
Permissions    173 registry / 173 database, sync idempotent twice
Disposable DB  full chain from empty PostgreSQL; migrated 0 -> 22; both composite FKs,
               the support key and NOT NULL project_number confirmed; cross-office
               participation refused in BOTH directions by the FK named in each case;
               rolled back all 22 leaving only an empty migrations table; re-migrated;
               dropped; absence proven
Smoke          80/80 over real PostgreSQL and Sanctum cookie sessions, 0 failures
Clean clone    installed, keyed, formatted, linted, typechecked, tested and built from
               tracked files alone
```

The smoke walked M3.1 through M3.4 in one run: reference allocation from `PRJ-2026-000001` and
the refusal to rewrite it, the D-091 mutation boundaries with generic update reaching neither
assignment nor status, archive/restore with the reference surviving both, all four Project
predicates including **TEAM failing closed**, participation add/correct/unlink with the Project
and the Party both surviving, `view` and `manage` proven independent in both directions,
`projects.update` reaching neither, an actor with `manage` but no Party visibility getting an
empty candidate list and a **422 on a real id it should not know**, archived and cross-office
Parties excluded as candidates, an `ALL` actor reaching another Office's Project while being
refused a participation that would bridge two Offices, nested binding answering **404** under the
wrong parent, and no NIK, NPWP, `tax_id` or mask anywhere in a participation payload.

### The database guard fired, and this is the report of it

M3.3 once contacted persistent development data, and the rule that followed — prove the serving
process's own database, from inside that process, before the first smoke request — **caught a real
wrong-database condition here.**

The first serving attempt reported `notary_ppat_office`, the persistent development database, not
the disposable one. The cause is worth recording because it will recur: **`php artisan serve` does
not pass a shell `DB_DATABASE` override to the `php -S` subprocess it spawns.** The override
reached every artisan CLI command — `migrate`, `tinker`, `permissions:sync` all connected to the
disposable database and said so — and was then filtered out of the one process that actually
serves requests. A shell override is not evidence about the serving process, exactly as the rule
says. Recorded as **O-034**.

**No smoke request ran against persistent data.** The probe was the first request, it is
read-only, and the run was aborted on its answer. The server was launched directly as `php -S`
instead, re-probed, and only then did the smoke begin. The persistent database was compared
against its pre-gate baseline immediately after the abort and again at the end: **all sixteen
tracked counts identical both times, `sessions` included.**

Unlike M3.4, whose "before" capture was truncated by a shell pipeline, this gate holds a complete
sixteen-row before *and* after capture of the persistent database, and they match exactly.

Two further method notes, reported rather than smoothed over. The smoke's first complete run
scored 77/80, and **all three failures were the harness's own**: it asserted `pic_user` where the
resource returns `pic`, used `??` to test for a null `role_code` — which cannot distinguish a null
value from an absent key — and requested a Party id from `individuals/{individual}`, which is keyed
by the subtype. The product was correct in all three cases; the fixture was fixed and the
disposable database reset so the final 80/80 is a genuine clean run rather than a patched one. The
same lesson M1.10 and M2.6 both recorded. Separately, the smoke needed
`SANCTUM_STATEFUL_DOMAINS` to include its own port before the session cookie was honoured — a
harness configuration detail, not a product finding.

The temporary probe route lived in `routes/api.php` for the duration of the smoke and was removed
afterwards; the file's SHA-256 was recorded before and re-verified after, and it is byte-identical.

### Open items

**O-034 added** (`artisan serve` does not propagate a shell database override to its serving
subprocess). O-010, O-018, O-031, O-032 and O-033 remain open; O-018's evidence was re-verified
and its wording corrected. No open item was closed for the sake of a clean checklist.

**M4 remains unstarted.** No Matter, no `matter_parties`, no workflow, no service types, no legal
role catalogue.

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
