# Notary & PPAT Office Management System
## Legal Reference Register — v1.0

## 1. Purpose

This document is a **register of legal references only**.

It records the statutory instruments that establish the Notary and PPAT offices as distinct
legal positions. It does not describe, derive, or approve any operational workflow.

```text
Legal references do not automatically define software workflow. Operational implementation
requires domain validation against current applicable regulations and office procedure.
```

---

## 2. Notary

| Reference | Title |
|---|---|
| UU No. 30 Tahun 2004 | Undang-Undang tentang Jabatan Notaris |
| UU No. 2 Tahun 2014 | Undang-Undang tentang Perubahan atas Undang-Undang Nomor 30 Tahun 2004 tentang Jabatan Notaris |

---

## 3. PPAT

| Reference | Title |
|---|---|
| PP No. 37 Tahun 1998 | Peraturan Pemerintah tentang Peraturan Jabatan Pejabat Pembuat Akta Tanah |
| PP No. 24 Tahun 2016 | Peraturan Pemerintah tentang Perubahan atas Peraturan Pemerintah Nomor 37 Tahun 1998 tentang Peraturan Jabatan Pejabat Pembuat Akta Tanah |

---

## 4. Why the Two Domains Are Separated

Notary and PPAT are distinct legal positions governed by distinct statutory regimes. This is
the reason the system keeps `NOTARY` and `PPAT` as separate business domains on shared
infrastructure, rather than merging them into one generic record type.

See `docs/00_PROJECT_OVERVIEW.md` sections 8 and 9, and `docs/03_DATABASE_ERD.md`
section 10.

---

## 5. Scope Limits of This Register

This register does **not** establish:

- deed numbering rules;
- required Warkah composition;
- Notary approval requirements;
- registration deadlines;
- reporting periods or formats;
- tax computation or payment rules;
- retention periods;
- protocol handover procedure.

Any of the above must be validated by a qualified domain source before implementation, per
`CLAUDE.md` section 62.

---

## 6. Maintenance

When a reference is amended, superseded, or repealed:

1. add the new instrument to the table above;
2. keep the superseded entry with its status noted;
3. record the change in `docs/CHANGELOG.md`;
4. do not silently delete a historical reference.

Verify currency of every reference before relying on it. The entries above were recorded on
2026-08-08 and have not been re-verified since.

---

**Status:** Reference register — no operational rules defined
