# Notary & PPAT Office Management System
## PPAT Workflow Specification

**Status:** `DRAFT — DOMAIN VALIDATION REQUIRED`

```text
DO NOT IMPLEMENT FROM THIS DOCUMENT YET
```

---

## 1. Purpose

This document will define the operational workflow for PPAT matters: the stages a matter
passes through, the land-object and tax data required at each stage, the Warkah composition
and verification rules, and the conditions under which a PPAT Deed may be reviewed,
approved, finalized, numbered, registered, and archived.

It is currently a placeholder. No workflow content has been authored, and none may be
inferred from other documents in this repository.

---

## 2. Why This Document Is Empty

`CLAUDE.md` section 62 prohibits inventing PPAT procedures, required Warkah, deed numbering
rules, tax rules, registration deadlines, or legal document requirements when the
specification does not define them.

PPAT carries statutory obligations around the deed register, monthly reporting, and the
binding of deeds together with their supporting Warkah. Those obligations are precisely the
kind of rule that must not be reconstructed from memory. See `docs/11_LEGAL_REFERENCES.md`.

---

## 3. Already-Established Terminology

The following terms are already fixed by `docs/05_I18N_LEGAL_TERMINOLOGY.md` and must be
used exactly as written when this document is completed:

```text
PPAT
AJB
APHT
Warkah
```

Display patterns already fixed:

```text
AJB — Akta Jual Beli
Deed of Sale and Purchase
```

```text
APHT — Akta Pemberian Hak Tanggungan
Deed of Granting Mortgage
```

```text
Warkah
Supporting Legal Documents
```

---

## 4. Already-Established Structure

These are architectural facts, not legal rules.

Deed type codes — `docs/03_DATABASE_ERD.md` section 18:

```text
AJB
APHT
HIBAH
TUKAR_MENUKAR
PEMBAGIAN_HAK_BERSAMA
OTHER
```

Warkah status — `docs/03_DATABASE_ERD.md` section 19:

```text
INCOMPLETE
UNDER_REVIEW
COMPLETE
FINALIZED
ARCHIVED
```

Tax record types — `docs/03_DATABASE_ERD.md` section 20:

```text
BPHTB
PPH
PBB
OTHER
```

Warkah is a distinct legal concept and must not be modelled as an ordinary document
attachment folder.

---

## 5. Validated Workflow

*To be added after domain validation.*

This section will hold, for each PPAT service type:

- ordered workflow stages with stable codes;
- target duration per stage;
- required documents per stage and per party role;
- Warkah item composition per deed type;
- tax processing preconditions;
- responsible role per stage;
- approval requirements and the permission that satisfies each;
- transition preconditions;
- deed numbering, register entry, and reporting obligations;
- finalization, locking, and protocol handling.

Nothing may be written here without a cited domain source.

---

## 6. Open Questions For Domain Validation

```text
[ ] Which PPAT service types does this office actually handle?
[ ] What is the correct stage sequence per deed type?
[ ] What is the mandatory Warkah composition per deed type?
[ ] Which tax obligations gate which stage, and in what order?
[ ] What are the deed numbering rules, and who assigns the number?
[ ] What is the deed register format and its finalization period?
[ ] What is the monthly reporting obligation, deadline, and recipient?
[ ] What are the binding/archiving requirements for deeds and supporting Warkah?
[ ] What correction mechanisms are permitted after finalization?
```

---

**Status:** `DRAFT — DOMAIN VALIDATION REQUIRED`
