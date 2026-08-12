# M2 — Party / Individual / Company Architecture

**Status:** Architecture lock, **partly implemented**.

**Locked at:** 2026-08-11 (M2.0), branch `feat/m2-parties`, from `main` at `501401f`.

**Implemented at:** 2026-08-11 (M2.1) — schema, models, sensitive storage, structural
invariants, and authorization predicates. Sections 4, 13, and 14 were revised to describe
what the code actually enforces rather than what the lock intended; section 4's original
wording overstated what `party_id` PK/FK gives, and that correction is called out where it
happened. **Still unimplemented:** every HTTP surface (sections 11 and 17), the frontend
(section 18), and duplicate detection (section 15).

**Numbering note:** the M2.0 specification suggested `10_M2_PARTY_ARCHITECTURE.md`, but
`10_M0_FOUNDATION.md` and `11_LEGAL_REFERENCES.md` already occupy those slots. This document
takes the next free number rather than overwriting either.

**Precedence:** `DECISIONS.md` outranks this file where they disagree, and this file outranks
the historical field lists in `03_DATABASE_ERD.md` section 6 where it explicitly says so —
each such departure is named and justified below. `08_NOTARY_WORKFLOW.md` and
`09_PPAT_WORKFLOW.md` remain `DRAFT — DOMAIN VALIDATION REQUIRED` and are **not** authority
for anything here.

---

## 1. Scope

M2 builds the master-data directory of the people and organizations the office deals with.

**In scope across M2:** the Party aggregate, Individual, Company, Company-to-Individual
relationships with history, directory-level lookup and filtering, sensitive identity
handling, and advisory duplicate detection.

**Not in scope, and not made easier to sneak in:** Project, Matter, Document management,
`party_documents`, global cross-domain Search, Property, Notary workflow, PPAT workflow,
Warkah, and the audit-log product module. Older planning text that placed "Documents" or
"Search" inside M2 is historical breadth, not permission — see section 15.

**M2.0 specifically produces no runtime artefact.** Its output is this document plus the
decisions it records, so that M2.1 is transcription rather than invention.

---

## 2. Terminology

| Term | Meaning here |
|---|---|
| **Party** | The aggregate root. Exactly one row per person or organization the office knows. |
| **Individual** | The subtype carrying person-specific data. Never exists without its Party. |
| **Company** | The subtype carrying organization-specific data. Never exists without its Party. |
| **Client** | A **business-facing word, not an entity.** A Party becomes a client through use. |
| **Sensitive identifier** | NIK, and NPWP / tax identifier. Treated as credentials, not as fields. |
| **Directory filtering** | Searching *within* the Party module. Distinct from global Search, which M2 does not build. |

"Client" earns its own row in this table because it is the term most likely to become a
table by accident. It must not.

---

## 3. Unified Party aggregate

```text
              PARTY  (aggregate root, ULID, Office-owned)
                |
        +-------+-------+
        |               |
   INDIVIDUAL       COMPANY
   (party_id PK/FK) (party_id PK/FK)
```

One canonical Party row backs every Individual and every Company. There is no second
identity table, no `clients` table, and no `client_id` running parallel to `party_id`
(**D-078**).

The reason is not tidiness. A person appears as a seller in one matter and a company
director in another; an organization is a client on Monday and a counterparty on Thursday.
Role belongs to the *relationship* with a Project or Matter — CLAUDE.md section 17 already
says so for Party roles, and a `clients` table would be the same mistake wearing a different
name.

Subtype tables use `party_id` as **both** primary key and foreign key: no surrogate id, so
no way to write two Individual rows for one Party and no orphan subtype row.

**That is not by itself enough**, and M2.1 corrected this section's original wording rather
than let it stand. PK/FK alone permits one Party to hold *both* an Individual and a Company
row, and says nothing about whether a subtype agrees with its Party's `party_type`. Section
4 now states exactly which mechanism enforces which invariant.

---

## 4. Subtype invariants

Four invariants, locked (**D-078**), and **implemented in M2.1**. What enforces each is
stated precisely, because a domain rule documented as a database constraint is a rule
somebody will later assume they cannot break:

| # | Invariant | Enforcement |
|---|---|---|
| 1 | Exactly one subtype per Party — never both | **DATABASE** |
| 2 | No subtype without a Party | **DATABASE** |
| 3 | Subtype agrees with `parties.party_type` | **DATABASE** |
| 4a | `party_type` immutable *while a subtype exists* | **DATABASE** |
| 4b | `party_type` immutable in all other cases | **DOMAIN** (`Party::booted()`) |
| 5 | No Party without a subtype | **DOMAIN TRANSACTION** |

The database half rests on one mechanism. `parties` carries `UNIQUE (id, party_type)`, and
each subtype table carries a pinned `party_type` column held at its own value by a CHECK,
completing a composite foreign key `(party_id, party_type) -> parties (id, party_type)`.
From that single constraint:

- a subtype row can only attach to a Party whose type matches — invariant 3;
- a Party already holding a Company row cannot hold an Individual row, because its
  `party_type` is one value and the other subtype's reference would not resolve —
  invariant 1;
- `parties.party_type` cannot be updated while any subtype row points at it, because the
  update would break that reference — invariant 4a. This holds against raw SQL, not merely
  against Eloquent.

**Invariant 5 is honestly domain-only.** No practical constraint makes a parent row require
a child, so a Party that outlives a failed subtype insert is prevented by the transaction in
section 12 — and by nothing else. It is a defect state, not a representable one, but the
database will not stop you creating it from a console. Saying so is the point; M2.2 and M2.3
own the Actions that keep it true.

`Party::booted()` refuses a `party_type` change before it reaches SQL. That is not redundant
with 4a: it covers the Party that has no subtype yet, and it fails with a message that
explains itself rather than a foreign-key violation.

Immutability deserves its reasoning. An Individual and a Company differ in identity
semantics, validation, relationships, and every future legal reference that will point at
them. An in-place conversion would silently reinterpret existing data and any deed already
referring to it. A record created with the wrong type is corrected by archiving it and
creating the right one — visibly, and without rewriting history.

M2 therefore ships **no** type-conversion workflow and **no** merge workflow. A future
duplicate-merge design is registered as an open item (section 20), not assumed.

---

## 5. Field ownership — one source of truth

The aggregate owns what is common; the subtype owns what is specific. Where the historical
ERD duplicates a field across both, this document removes the duplicate and says so.

### Party owns

```text
id                ULID
office_id         FK -> offices.id
party_type        INDIVIDUAL | COMPANY, immutable
display_name      derived (section 6), normalized for display and indexing
primary_phone     the aggregate's contact number
primary_email     the aggregate's contact address
created_by        FK -> users.id
updated_by        FK -> users.id
created_at
updated_at
deleted_at        the single archive authority (section 9)
```

### Departures from `03_DATABASE_ERD.md` section 6, each deliberate

| ERD field | Disposition | Why |
|---|---|---|
| `parties.status` | **Dropped** | It competes with `deleted_at` for lifecycle authority. Two sources of truth for "is this record active" is how a record ends up archived-but-visible. Section 9 makes `deleted_at` the only authority. If a future business state genuinely differs from archived, it gets its own column and its own name. |
| `companies.status` | **Dropped** | Same reason, one level down. Archive is an aggregate operation; a Company does not have a lifecycle its Party lacks. |
| `companies.phone`, `companies.email` | **Dropped** | Duplicates `parties.primary_phone` / `primary_email` with no documented independent meaning. `individuals` carries no such pair, so keeping them on Company alone would also make the two subtypes gratuitously asymmetric. |
| `company_people.is_current` | **Dropped** | Derivable from `effective_until`. A stored boolean beside the dates it summarizes will eventually disagree with them, and the disagreement is invisible. Current-ness is a query, not a column. |

Nothing else from the ERD is removed. Everything retained is classified in section 7.

---

## 6. `display_name` invariant

`display_name` is a **derived, normalized** value owned by the aggregate. It is never a third
independently editable name (**D-079**).

```text
Individual   derives from the individual's canonical full name
Company      derives from short_name when one is intentionally present,
             otherwise legal_name
```

The Company precedence is chosen, not inherited: a short name exists precisely because
somebody wanted the organization displayed that way, so honouring it is honouring intent.
When absent, `legal_name` is the only correct fallback.

Subtype-name changes and the `display_name` update must occur in one transaction (section
12). Without that, a rename leaves the directory showing the old name while the detail page
shows the new one — and the directory is what people search.

---

## 7. Field classification

Every historical field is classified rather than accepted wholesale. **Optional** means
nullable and offered; **deferred** means not built in M2 and not designed here.

### Individual

| Field | Class | Note |
|---|---|---|
| `full_name` | canonical | Required. Feeds `display_name`. |
| `prefix`, `suffix` | optional | Honorifics and titles; free text. |
| `nik` | optional, **sensitive** | Never required by this document — see section 11. |
| `npwp` | optional, **sensitive** | Not everyone has one. |
| `birth_place`, `birth_date` | optional | `birth_date` is a duplicate signal (section 8). |
| `gender` | optional | Stable code, not a display string. |
| `occupation`, `nationality` | optional | Free text; no enum invented. |
| `marital_status` | optional | Stable code. **No spouse-consent behaviour is implied** — that is a legal rule this milestone has no authority to invent. |
| `address`, `village`, `district`, `city`, `province`, `postal_code` | optional | Free text. No administrative-region reference table in M2. |

### Company

| Field | Class | Note |
|---|---|---|
| `legal_name` | canonical | Required. Fallback for `display_name`. |
| `short_name` | optional | Preferred for `display_name` when present. |
| `entity_type` | canonical | Stable code from the ERD set: `PT`, `CV`, `YAYASAN`, `PERKUMPULAN`, `KOPERASI`, `FIRMA`, `OTHER`. Transcribed, not extended. |
| `registration_number` | optional | Duplicate signal. No format rule invented. |
| `tax_id` | optional, **sensitive** | The Company NPWP. Section 11 governs it. |
| `address` … `postal_code` | optional | As Individual. |

**No field here is made mandatory on legal grounds.** `full_name`, `legal_name`, and
`entity_type` are required for structural reasons — a directory row with no name is not
usable, and a company with no entity type cannot be displayed correctly. Everything an
Indonesian legal process might demand is a matter-level requirement, not a master-data one,
and belongs to a milestone with domain authority.

---

## 8. Office ownership and Data Scope

### Ownership

```text
parties.office_id -> offices.id
```

Locked (**D-080**): no `organization_id` on Party — the Organization is reached through the
Office, exactly as D-027 established for User. No `tenant_id`. No `party_offices` pivot. No
global Party table detached from Office ownership, and no automatic cross-office sharing.

A Party created for Office A belongs to Office A. Cross-office reach is a **Data Scope**
question, never a matter of copying the row.

### Scope predicates

Data Scopes remain predicates, never ranks (D-028). For Party-domain resources:

| Scope | Party-domain meaning |
|---|---|
| `OFFICE` | `party.office_id == actor.office_id` |
| `ALL` | any Office in the deployment |
| `OWN` | **grants nothing** |
| `ASSIGNED` | **grants nothing** |
| `TEAM` | **grants nothing** |

The three that grant nothing are the important half (**D-080**).

`OWN` must not become `created_by`. A Party is a shared office directory record; the
colleague who typed it in has no special claim on a person or an organization, and "I created
this record" is not a relationship with the human it describes.

`ASSIGNED` must not be invented into existence. There is no Party assignment entity in M2,
so there is nothing for the predicate to match, and inventing one to give the scope work
would be building a feature to justify a word.

`TEAM` must never collapse to `OFFICE`. No Team entity exists (D-042), and quietly aliasing
the two would grant access the deployment never configured.

All three **fail closed**: a grant carrying only `OWN`, `ASSIGNED`, or `TEAM` reaches no
Party at all. That is the same rule M1's `UserVisibility` already applies, and M2.1 should
follow its shape.

### Creation

Creation has no target record, so it is authorized against the **intended target Office**:

```text
OFFICE                 may create in the actor's own Office
ALL                    may create in another Office, if the API exposes the choice
OWN / ASSIGNED / TEAM  grant nothing
```

Office selection is never a frontend-only rule. The backend Policy decides, exactly as
`UserPolicy::create()` already does for a destination Office.

---

## 9. Archive semantics

One authority: **`parties.deleted_at`** (**D-081**).

- Archiving an Individual or a Company archives **the Party aggregate**. Subtypes are not
  independently soft-deleted, because a live Party root with an archived subtype is a state
  nothing in the product could render honestly.
- No ordinary hard-delete endpoint exists. Party-domain records are master data that legal
  records will eventually reference.
- **No restore capability in M2.** The registry defines `parties.archive` and
  `companies.archive` but no restore permission, and inventing one is out of scope. If
  restore is wanted, it needs a permission and a decision first.
- `status` is dropped from both `parties` and `companies` (section 5) precisely so that
  `deleted_at != null` is the whole answer to "is this archived".

---

## 10. Permission mapping

**M2.0 adds no permission. The canonical count remains 171.** The registry was read
directly; every code below already exists.

### Lifecycle

| Capability | Permission |
|---|---|
| Individual lifecycle | `parties.view`, `parties.create`, `parties.update`, `parties.archive` |
| Company lifecycle | `companies.view`, `companies.create`, `companies.update`, `companies.archive` |

**One ordinary mutation requires one permission.** Creating a Company requires
`companies.create` and *not* additionally `parties.create`, even though the action writes a
Party row inside its transaction. Persistence composition is an implementation fact; making
the user hold two permissions because of it would leak the schema into the authorization
model.

### Sensitive identity — two-tier

The live registry carries four codes in the `parties` group, all canonical under **D-001**:

```text
parties.identity.view              tier 1 — open the identity surface
parties.identity.update            tier 1 — mutate sensitive identity
parties.identity.nik.view_full     tier 2 — reveal raw NIK only
parties.identity.npwp.view_full    tier 2 — reveal raw NPWP / tax identifier only
```

Semantics locked (**D-082**):

1. `parties.identity.view` **alone** opens the identity surface with NIK and NPWP still
   **masked**. Access to the surface is not access to the values.
2. `parties.identity.nik.view_full` authorizes the raw **NIK** only. It implies nothing about
   NPWP.
3. `parties.identity.npwp.view_full` authorizes the raw **NPWP / tax identifier** only. It
   implies nothing about NIK.
4. `parties.identity.update` authorizes mutation. It does **not** confer full readback of
   identifiers the actor may not otherwise see — writing a value is not licence to read a
   different one.
5. `parties.view` implies neither identity-surface access nor any raw reveal.
6. `companies.view` implies no raw Company tax-identifier reveal.
7. **Company tax identity uses `parties.identity.npwp.view_full`.** No `companies.identity.*`
   family is invented; the identity surface belongs to the aggregate, and the registry places
   these codes in the `parties` group deliberately.

Raw reveal is decided **field by field**, against that field's own permission. There is no
combination of lifecycle permissions that produces a raw identifier.

### Company relationships

| Relationship category | Permission |
|---|---|
| Management-type | `companies.management.view`, `companies.management.update` |
| Ownership-type | `companies.shareholders.view`, `companies.shareholders.update` |

Mapping the ERD's `relationship_type` values (**D-083**):

```text
DIRECTOR             management
COMMISSIONER         management
AUTHORIZED_PERSON    management
SHAREHOLDER          ownership
BENEFICIAL_OWNER     ownership
```

This split categorises by **what the relationship is about** — who acts for the organization
versus who owns it — and invents no Indonesian corporate law. It asserts nothing about how
many directors a company may have, whether a commissioner is required, what ownership must
total, or how beneficial ownership is determined. Those are domain rules, and section 16
keeps them out.

Ownership data is not visible merely because a user can view ordinary Company details, and a
frontend tab is never the boundary.

---

## 11. Sensitive identity architecture

### The rule

**A browser that is not authorized for a raw identifier never receives it.** Not hidden by
CSS, not masked in React, not present in a payload the console can read — absent.

This is a backend serialization guarantee (**D-082**). It is the difference between privacy
and the appearance of privacy, and it is the single most important sentence in this document.

### Response shape

| Surface | NIK / NPWP content |
|---|---|
| Individual or Company list | masked only |
| Individual or Company detail | masked only |
| Identity surface, tier 1 only | masked, plus other authorized identity fields |
| Identity surface, with the matching tier-2 permission | that field raw; every other field still masked |

Masking is **presentation computed server-side from the stored value**. The mask is never the
stored canonical value, and no mask character pattern is fixed by this document — that is a UI
decision, not a schema one.

### API direction

M2.0 left the route shape open between two options that both satisfy the contract:

- **A.** dedicated per-field reveal operations; or
- **B.** one identity endpoint that serializes each field conditionally on that field's
  effective authorization.

**M2.2 settled it as A, for Individuals.** Option B would have made the raw value part of an
ordinary `GET` response, which is precisely the thing that ends up in a long-lived frontend
query cache; a value that never enters a cache cannot be read out of one.

```text
GET    /api/v1/individuals/{individual}/identity                  parties.identity.view
PATCH  /api/v1/individuals/{individual}/identity                  parties.identity.update
POST   /api/v1/individuals/{individual}/identity/nik/reveal       parties.identity.nik.view_full
POST   /api/v1/individuals/{individual}/identity/npwp/reveal      parties.identity.npwp.view_full
```

Both tier-2 abilities additionally require tier 1 — a permission to see one field of a surface
the actor may not open would be incoherent.

**Three properties of the reveal operation are contract, not implementation detail**, and
M2.3 must reproduce them for Company `tax_id`:

- **`POST`, never `GET`.** A raw identifier must not be reachable by a method that browsers,
  proxies, and query caches treat as repeatable and cacheable, and must never be expressible
  as a URL. This is what makes the "never in a URL, never in a cache key" clause of the
  storage contract structural rather than a convention.
- **`Cache-Control: no-store, no-cache, must-revalidate, private`** on the response, so the
  value exists in one response and nowhere else — not in a shared cache, a browser disk
  cache, or the back/forward cache.
- **Its own named rate limiter**, deliberately disjoint from the `security.*` buckets. The
  reasoning is D-071's, applied forward: a shared bucket means spending one route's budget
  silently disables an unrelated one, so somebody working through a directory must not find
  their own password change refused. NIK and NPWP share the one reveal bucket on purpose —
  they are the same kind of disclosure against the same record, and separating them would
  hand a caller twice the budget for alternating fields. The limit is a brake on bulk
  disclosure and never a substitute for authorization.

The reveal response carries the field name and its value and nothing else. A null identifier
stays null rather than becoming a placeholder, since a fabricated one would make an absent
value indistinguishable from a present one.

The Company identity surface remains **direction, not built**:

```text
GET    /api/v1/companies/{company}/identity          parties.identity.view
PATCH  /api/v1/companies/{company}/identity          parties.identity.update
```

with raw reveal gated per field by the tier-2 codes, following the three properties above.

### Storage contract

Raw NIK, NPWP, and Company tax identifiers:

- are **never** logged, in any log level;
- never appear in exception messages or debug output;
- never reach frontend telemetry;
- are never serialized to an unauthorized client;
- are never copied into `display_name`;
- never appear in a URL path, query string, or fragment;
- never appear in a cache key;
- are never written to `localStorage` or `sessionStorage`.

At rest, use **framework-provided encryption primitives** — the `encrypted` cast M1 already
uses for `two_factor_secret`. **No custom cryptography is written**, for the same reason M1.9
refused to hand-roll TOTP: a subtly wrong scheme fails silently.

If M2 later needs equality search or a dedup fingerprint over a sensitive identifier, then:
no additional plaintext searchable copy; no unkeyed plain hash standing in for secure storage
(an unkeyed hash of a 16-digit NIK is brute-forceable in seconds); a documented **keyed**
deterministic construction or another reviewed approach; and the fingerprint is never
API-visible. **If that design is not settled when M2.1 begins, the fingerprint is not built**
— see open item in section 20.

### Format validation

**Deferred, deliberately.** No canonical document in this repository freezes the NIK or NPWP
format, and general knowledge is not authority — NPWP formats have changed in Indonesia, and
encoding a guess as a validator would reject real identifiers.

Until a canonical rule exists: no legal-format validation, and no aggressive normalization
that destroys the original semantic value. Safe technical normalization may be added **after**
it is documented, never before.

---

## 12. Atomic aggregate operations

```text
Individual create        BEGIN → create Party → create Individual → COMMIT
Company create           BEGIN → create Party → create Company    → COMMIT
Name / contact update    one transaction covering subtype and display_name
Archive                  one transaction at the aggregate root
```

A failed subtype insert must leave no Party behind. This is a structural rule about aggregate
consistency, not a legal workflow rule.

---

## 13. Company relationships and history

```text
company_people
  id                     ULID
  company_party_id       -> a Party that is a COMPANY
  individual_party_id    -> a Party that is an INDIVIDUAL
  relationship_type      DIRECTOR | COMMISSIONER | SHAREHOLDER |
                         AUTHORIZED_PERSON | BENEFICIAL_OWNER
  position_name          optional free text
  ownership_percentage   optional, nullable
  effective_from         optional
  effective_until        NULL means current
  created_at
  updated_at
```

**Person names are never duplicated here.** The relationship points at the Individual Party;
the name lives in one place and stays correct when it changes.

**History is preserved** (**D-083**). A director change does not overwrite the old row — the
existing relationship is ended by setting `effective_until`, and the new one is a new row.
"Who was the director in March" must remain answerable, because deeds executed in March
depend on the answer.

Where PostgreSQL can enforce the subtype of each endpoint structurally rather than by
application convention, it should — M2.1 owns the exact constraint form, but a generic FK to
`parties` plus a hopeful comment is not sufficient.

**Same-office invariant** (**D-080**), **DATABASE enforced as of M2.1**: a relationship
cannot silently bridge Offices. `company_people.office_id` is a *constraint carrier* — two
composite foreign keys reference `parties (id, office_id)` through that one column, so both
endpoints must agree with it and therefore with each other. A cross-office relationship is
unrepresentable, not merely discouraged. The subtype of each endpoint is likewise structural:
`company_party_id` references `companies.party_id` and `individual_party_id` references
`individuals.party_id`.

```text
company Party.office_id == individual Party.office_id
```

`ALL` governs visibility and administrative reach; it does not redefine domain ownership, and
it is not permission to create cross-office relationships. If a real requirement for that
emerges, it needs its own decision.

**No corporate-law cardinality is invented.** Nothing here requires a director, forbids two,
demands a commissioner, makes shareholdings total 100%, infers beneficial ownership, or
imposes date-transition rules.

---

## 14. Proposed table matrix

No migration exists. This is the proposal M2.1 implements.

### `parties`

| Aspect | Decision | Why |
|---|---|---|
| PK | ULID | CLAUDE.md section 11; first-party domain table. The M1 package-table bigint exception (D-023/D-038) does not apply. |
| Office ownership | `office_id` FK → `offices.id`, **required**, indexed | Office is the security boundary (section 8). PostgreSQL does not index a referencing column automatically. |
| FK behaviour | `restrictOnDelete` | Matches `users.office_id`. Removing an Office must not silently take its directory with it. |
| Actor metadata | `created_by`, `updated_by` FK → `users.id`, `restrictOnDelete` | Attribution must survive; M1 has no user-deletion path anyway (D-050). |
| Soft delete | `deleted_at` | The single archive authority (section 9). |
| Indexes | `office_id`; `(office_id, party_type)`; `display_name` | The directory always filters by Office first, usually by type, and sorts by name. |
| Unique | **none on identity** | See below. |
| Deliberately absent | `organization_id`, `tenant_id`, `status`, `client_id` | Sections 3, 5, 8. |

### `individuals`

| Aspect | Decision | Why |
|---|---|---|
| PK | `party_id`, also FK → `parties.id`, `cascadeOnDelete` | Enforces one-to-one structurally (section 3). Cascade is right here because the subtype cannot outlive its root. |
| Sensitive | `nik`, `npwp` encrypted at rest | Section 11. |
| Nullable | every field except `full_name` | Section 7. |
| Unique | **none on `nik` / `npwp`** | Section 8 of this table's rationale — see the note below. |
| Deliberately absent | surrogate `id`, `status`, contact duplicates | Sections 3, 5. |

### `companies`

| Aspect | Decision | Why |
|---|---|---|
| PK | `party_id`, FK → `parties.id`, `cascadeOnDelete` | As Individual. |
| Sensitive | `tax_id` encrypted at rest | Section 11. |
| Required | `legal_name`, `entity_type` | Structural, not legal (section 7). |
| Unique | **none on `tax_id` / `registration_number`** | See below. |
| Deliberately absent | surrogate `id`, `status`, `phone`, `email` | Section 5. |

### `company_people`

| Aspect | Decision | Why |
|---|---|---|
| PK | ULID | First-party domain table. |
| FKs | `company_party_id`, `individual_party_id` → `parties.id`, subtype-enforced where PostgreSQL can | Section 13. |
| Unique | **none** | The same person may hold the same role twice across different periods; that is history, not duplication. |
| Indexes | `company_party_id`; `individual_party_id`; `(company_party_id, relationship_type)` | Company detail, "which companies is this person in", and per-category tabs. |
| Nullable | `position_name`, `ownership_percentage`, `effective_from`, `effective_until` | `effective_until IS NULL` means current. |
| Deliberately absent | `is_current`, duplicated person names | Sections 5, 13. |

### Why no UNIQUE constraint on any identifier

A `UNIQUE` constraint is an assertion that two rows sharing a value are the same entity, and
that the value is always known and always correct. None of that holds for NIK, NPWP,
`tax_id`, or `registration_number` in a Party directory: identifiers are optional, may be
recorded with errors, and are Office-scoped rather than deployment-global. A unique index
would also become a **cross-office existence oracle** — a rejected insert tells the user a
matching record exists in an Office they cannot see (section 16).

They are excellent duplicate *signals*, and section 15 uses them as such. Promoting one to an
authoritative uniqueness key requires an explicit decision this milestone does not make.

### Intentionally absent relations

No `party_documents`, no Project or Matter foreign keys, no `party_offices` pivot, no Property
link. Those belong to M3 and later, and adding a column "ready for" them now would be a
speculative table by another name (CLAUDE.md section 61).

---

## 15. Duplicate detection

**Advisory only** (**D-084**). Detection surfaces candidates to a human. It does not
auto-merge, does not overwrite, does not delete a candidate, and does not assert that two
records are the same person or organization — an assertion the software has no standing to
make.

**Office-scoped by default.** An `OFFICE`-scoped user receives candidates from their own
Office only, and must never learn that a matching identifier exists elsewhere in the
deployment. `ALL` may see across Offices where a later milestone implements it explicitly.

Candidate signals, when present and safely normalized:

```text
Individual   exact sensitive-identifier match, phone, email, name + birth_date
Company      exact tax-identifier match, registration_number, legal_name, phone, email
```

M2.0 hard-codes no fuzzy matching and adds no fuzzy-search dependency. M2 does **not**
consolidate records across Offices; a cross-office consolidation design and a merge workflow
are both open items (section 20).

---

## 16. Security threat review

| # | Threat | Architecture control | Test M2 must eventually carry |
|---|---|---|---|
| 1 | Unauthorized raw NIK/NPWP exposure | Backend serialization; two-tier per-field authorization (D-082) | Raw value absent from the payload for each unauthorized combination |
| 2 | List endpoint leakage | Lists serialize masked values only; no identity fields in the collection resource | Assert raw identifiers absent from list responses |
| 3 | Cross-office enumeration | `OFFICE` predicate applied **in the query**, as `UserVisibility` does | Office-scoped list excludes other Offices; record fetch by id 403s |
| 4 | Sensitive-search existence oracle | Duplicate detection Office-scoped; no unique constraints on identifiers | `OFFICE` user's duplicate probe returns nothing for a cross-office match |
| 5 | Identifiers in logs | Storage contract (section 11); M1's `Log::` scan test extended to Party actions | Source scan finds no identifier field inside any `Log::` call |
| 6 | Exception / debug output | Never interpolate identifiers into exception messages | Error responses contain no raw identifier |
| 7 | Browser storage | No `localStorage` / `sessionStorage` for revealed values; M1's frontend scan already enforces the absence repo-wide | Frontend scan stays clean |
| 8 | Query-string leakage | Identifiers never in path, query, or fragment | Route inventory carries no identifier parameter |
| 9 | Insecure dedup fingerprint | Keyed construction or nothing; never API-visible (section 11) | Fingerprint absent from every resource |
| 10 | Leakage via Company relationship endpoints | Relationship resources expose the related Party's display data, never its identity fields | Relationship response contains no identifier |
| 11 | Frontend-only authorization | Policy → `EffectiveAccessResolver` → Party predicate; guards are presentation (D-048, D-063) | Every endpoint authorized server-side independent of UI |
| 12 | Route binding accepting the wrong subtype | Binding verifies subtype (section 17) | Individual endpoint 404s a Company id, and the reverse |

This is a design review, not a framework. Each control is a claim M2's tests must make true.

---

## 17. API direction

Conventions from `06_API_CONVENTIONS.md` are unchanged: `{"data": …}` for a single resource,
`{"data": […], "meta": …}` for a collection, and 401 / 403 / 404 / 409 / 422 / 429 as M1 uses
them.

**Individuals are built (M2.2).** The delivered surface, ten routes, verified against the
router rather than transcribed from this document:

```text
GET        /api/v1/individuals                                    parties.view
POST       /api/v1/individuals                                    parties.create
GET        /api/v1/individuals/options                            parties.create
GET        /api/v1/individuals/{individual}                       parties.view
PUT|PATCH  /api/v1/individuals/{individual}                       parties.update
POST       /api/v1/individuals/{individual}/archive               parties.archive
GET        /api/v1/individuals/{individual}/identity              parties.identity.view
PATCH      /api/v1/individuals/{individual}/identity              parties.identity.update
POST       /api/v1/individuals/{individual}/identity/nik/reveal   parties.identity.nik.view_full
POST       /api/v1/individuals/{individual}/identity/npwp/reveal  parties.identity.npwp.view_full
```

`options` is narrow form metadata — the Offices this caller may create in — not an Office API.
It applies the same predicate the Policy applies, so the dropdown cannot offer a destination
the Policy would then refuse.

**No `DELETE` and no restore.** Party-domain records are archived (section 9, D-081), and the
registry defines `parties.archive` with no restore counterpart, so there is nothing to
authorize one with.

Companies remain direction, built in M2.3:

```text
GET    /api/v1/companies                         POST   /api/v1/companies
GET    /api/v1/companies/{company}               PATCH  /api/v1/companies/{company}
POST   /api/v1/companies/{company}/archive
```

Plus the identity surfaces in section 11, and later a relationship subresource under
`/api/v1/companies/{company}/…`. **No `/api/v1/clients`** — that would be the duplicate
persistence surface section 3 exists to prevent. It stays absent: nothing under `clients`,
`companies`, or a generic `parties` path is routable, checked by probe rather than assumed.

**Identifier semantics.** An Individual or Company is addressed by its **Party ULID**. One
public identifier per aggregate; no second id for the subtype. Route model binding must
verify the subtype, so an Individual endpoint rejects a Company Party id with 404 rather than
resolving it into an unexpected shape.

**Never in a Party-domain response:** raw NIK, raw NPWP, raw `tax_id`, ciphertext, dedup
fingerprints, encryption metadata, or permission pivot internals.

---

## 18. Frontend direction

```text
/[locale]/parties
/[locale]/parties/individuals
/[locale]/parties/individuals/[id]
/[locale]/parties/companies
/[locale]/parties/companies/[id]
```

**Delivered in M2.2**, and the differences from the sketch above are deliberate:

```text
/[locale]/parties/individuals             directory
/[locale]/parties/individuals/new         create
/[locale]/parties/individuals/[id]        detail — Profile and Identity
/[locale]/parties/individuals/[id]/edit   edit
```

No `/[locale]/parties` index page: with one child it would be a page whose only content is a
link to the page beside it in the sidebar. Navigation carries "Clients & Parties" as a group
with a single "Individuals" entry behind `parties.view`, and gains "Companies" when M2.3
builds the route — entries appear only when the route exists (D-064).

The detail page has **Profile and Identity only**. No Companies section: M2.4 owns
relationships, and an empty tab is a promise the product cannot keep.

**Not under `/settings/`.** Settings administers the deployment; the Party directory is
operational shared-office data that most staff use daily. Placing it in Settings would put a
daily tool behind an administrative door.

Navigation may read naturally — "Clients & Parties" with "Individuals" and "Companies" — via
i18n keys, never hardcoded strings, and in both `id` and `en`. **Navigation entries appear
only when the route actually exists** (D-064), so nothing is added to `navigation.ts` during
M2.0.

**Sensitive-data UX.** Lists and normal detail show masked values. A reveal is an explicit
action that **requests authorized data from the identity endpoint** — it must never merely
unhide a raw value already sitting in the page payload, which would mean the payload was
wrong. Revealed values are not persisted to storage or URLs, and are not cached longer than
the reveal needs.

**Detail sections.** Individual: Profile, Identity, Companies. Company: Overview, Management,
Shareholders. The Companies section shows real relationships only once M2.4 exists. **No tab
is created for Documents, Projects, Matters, or Timeline** until those modules exist — an
empty tab is a promise the product cannot keep.

**Directory filtering** by name, phone, email, company legal name, and authorized registration
data is in M2's future scope. Sensitive-identifier lookup requires the sensitive
authorization and must not become an existence side-channel. This is **not** the global Search
feature: no header search, no cross-domain search, and no Project, Matter, Document, or
Property search in M2.

---

## 19. Milestone breakdown

```text
M2.0   Planning + Party Architecture Lock            <- this document
M2.1   Party Schema + Authorization Foundation
M2.2   Individual Management
M2.3   Company Management
M2.4   Company Relationships / Management / Shareholders
M2.5   Party Directory + Duplicate Detection + Integration Polish
M2.6   M2 Quality Gate
```

**M2.1 owns:** the four tables as locked in section 14; the enums and value objects the schema
needs; the Party aggregate model foundation; the `OFFICE` / `ALL` authorization predicates
with `OWN` / `ASSIGNED` / `TEAM` failing closed; sensitive-storage primitives where section 11
has settled them; Permission Matrix implementation metadata updated **without changing the
count**; database constraints; and architecture tests.

**M2.1 does not own** full CRUD UI — that is M2.2 onward.

**M2.2 delivered:** the Individual lifecycle — create, list, detail, update, archive — with
Party and Individual written in one transaction, `display_name` synchronized from the
canonical full name in that same transaction, and the sensitive identity surface with two
independent reveal operations (section 11). Ten routes, four frontend routes under
`/[locale]/parties/individuals`, and "Clients & Parties" in navigation. **No migration** —
the count stays at 17, because M2.1's schema was designed to make this milestone mechanical.
**No permission** — the count stays at 171; `parties.*` was already canonical and M2.2 gave
each of the eight codes a reachable route. `companies.*` became *deferred* in the Permission
Matrix at the same time, which is not a step backwards: before M2.2 the Party module was
absent from navigation, so the namespace advertised nothing; now it looks implemented, and an
administrator granting `companies.view` would reasonably expect something to happen.

**M2.2 does not own** anything Company-shaped, duplicate detection (M2.5), identifier search,
or Party-to-Project anything.

**Project remains M3.** M2 builds no Project, no Matter, and no Party-to-Project assignment.

---

## 20. Unresolved items

| Question | Status | Blocks M2.1? |
|---|---|---|
| NIK / NPWP legal format rules | Deferred pending domain authority (section 11). Fields stay free-form. | **No** |
| Keyed dedup fingerprint construction | Undesigned. If unsettled when M2.1 begins, the fingerprint is not built and equality dedup waits for M2.5. | **No** |
| Duplicate-merge workflow | Requires its own architecture decision. M2 detects, never merges. | **No** |
| Cross-office Party consolidation | Out of scope; Party stays Office-owned. No pivot invented to pre-solve it. | **No** |
| Global Search integration | M2 builds directory filtering only. | **No** |
| Restore after archive | No canonical permission exists; none invented. | **No** |

None blocks M2.1. Every one is recorded rather than quietly assumed, and the first two are the
ones most likely to tempt improvisation while writing migrations — which is exactly why they
are named here.

The eight-item checklist from the specification's constraint questions is answered in sections
3, 4, 5, 6, 8, 9, 11, 13, and 15; none of them required a decision this document lacked the
authority to make.
