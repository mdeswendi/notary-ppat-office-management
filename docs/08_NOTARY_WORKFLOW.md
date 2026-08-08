# Notary & PPAT Office Management System
## Notary Workflow Specification

**Status:** `DRAFT — DOMAIN VALIDATION REQUIRED`

```text
DO NOT IMPLEMENT FROM THIS DOCUMENT YET
```

---

## 1. Purpose

This document will define the operational workflow for Notary matters: the stages a matter
passes through, the document requirements attached to each stage, the approval points, and
the conditions under which a Notarial Deed may be reviewed, approved, finalized, numbered,
and archived.

It is currently a placeholder. No workflow content has been authored, and none may be
inferred from other documents in this repository.

---

## 2. Why This Document Is Empty

`CLAUDE.md` section 62 prohibits inventing Notary procedures, approval requirements, deed
numbering rules, registration deadlines, or document requirements when the specification
does not define them.

The material available so far establishes the *shape* of the workflow engine — templates,
stage instances, snapshots, requirement checklists — but not the legally correct content of
any Notary workflow. Filling this document requires a qualified domain source.

---

## 3. Already-Established Terminology

The following terms are already fixed by `docs/05_I18N_LEGAL_TERMINOLOGY.md` and must be
used exactly as written when this document is completed:

```text
Minuta Akta
Legalisasi
Waarmerking
Repertorium
```

Related preserved terms:

```text
Akta Notaris     — Notarial Deed
Protokol Notaris — Notary Protocol
```

---

## 4. Already-Established Structure

These are architectural facts, not legal rules. They are recorded here so the completed
workflow can be expressed against them.

Matter status codes — `docs/03_DATABASE_ERD.md` section 9:

```text
OPEN
IN_PROGRESS
WAITING
ON_HOLD
COMPLETED
CANCELLED
ARCHIVED
```

Deed lifecycle — `docs/03_DATABASE_ERD.md` section 17:

```text
DRAFT
UNDER_REVIEW
APPROVED
FINALIZED
VOID
SUPERSEDED
```

Stage instance status — `docs/03_DATABASE_ERD.md` section 11:

```text
PENDING
ACTIVE
COMPLETED
SKIPPED
BLOCKED
```

Matter Status and Workflow Stage are separate concepts and must not be merged.

---

## 5. Validated Workflow

*To be added after domain validation.*

This section will hold, for each Notary service type:

- ordered workflow stages with stable codes;
- target duration per stage;
- required documents per stage;
- required tasks per stage;
- responsible role per stage;
- approval requirements and the permission that satisfies each;
- transition preconditions;
- finalization and locking rules;
- register and protocol handling.

Nothing may be written here without a cited domain source.

---

## 6. Open Questions For Domain Validation

```text
[ ] Which service types does this office actually handle?
[ ] What are the correct stage sequences per service type?
[ ] Which stages require Principal approval rather than staff completion?
[ ] What are the deed numbering rules, and who assigns the number?
[ ] What is the correct Repertorium entry procedure and period?
[ ] What triggers Minuta Akta archiving, and what release conditions apply?
[ ] What correction mechanisms are permitted after finalization?
```

---

**Status:** `DRAFT — DOMAIN VALIDATION REQUIRED`
