# Notary & PPAT Office Management System
## Bilingual & Legal Terminology Guideline — v1.0

## 1. Purpose

The application supports Indonesian and English while preserving the legal meaning of Indonesian Notary and PPAT terminology.

The goal is not literal translation.

The goal is:

- clear Indonesian UI;
- professional English UI;
- preserved legal terminology;
- consistent labels;
- predictable translation architecture.

---

## 2. Supported Locales

```text
id
en
```

Default locale:

```text
id
```

---

## 3. Translation Architecture

Static UI:

```text
frontend/messages/id.json
frontend/messages/en.json
```

Dynamic/master content may use:

```text
name_id
name_en
description_id
description_en
```

Do not store every UI translation in the database.

---

## 4. Route Rule

Use the same route names in both locales.

Correct:

```text
/id/projects
/en/projects

/id/notary/matters
/en/notary/matters
```

Incorrect:

```text
/id/proyek
/en/projects
```

---

## 5. Legal Terminology Principle

Do not automatically translate Indonesian legal terminology word-for-word.

Use one of these patterns:

### Pattern A — Preserve original term

Use when the original legal term is the safest and clearest identifier.

Examples:

```text
PPAT
Waarmerking
Legalisasi
Warkah
```

### Pattern B — Preserve original + English explanation

Examples:

```text
AJB — Akta Jual Beli
Deed of Sale and Purchase
```

```text
APHT — Akta Pemberian Hak Tanggungan
Deed of Granting Mortgage
```

### Pattern C — Indonesian legal term as primary + explanatory English subtitle

Example:

```text
Minuta Akta
Original Deed Record
```

---

## 6. Core Legal Dictionary

| Code | Indonesian Primary | English UI / Explanation | Preserve Original |
|---|---|---|---|
| PPAT | PPAT | PPAT | Yes |
| AJB | AJB — Akta Jual Beli | AJB — Deed of Sale and Purchase | Yes |
| APHT | APHT — Akta Pemberian Hak Tanggungan | APHT — Deed of Granting Mortgage | Yes |
| WARKAH | Warkah | Warkah / Supporting Legal Documents | Yes |
| MINUTA_AKTA | Minuta Akta | Minuta Akta / Original Deed Record | Yes |
| LEGALISASI | Legalisasi | Legalisasi | Yes |
| WAARMERKING | Waarmerking | Waarmerking | Yes |
| REPERTORIUM | Repertorium | Notary Register / Repertorium | Yes |
| NOTARY_PROTOCOL | Protokol Notaris | Notary Protocol | No |
| PPAT_PROTOCOL | Protokol PPAT | PPAT Protocol | Yes for PPAT |
| NOTARIAL_DEED | Akta Notaris | Notarial Deed | No |
| PPAT_DEED | Akta PPAT | PPAT Deed | Yes for PPAT |

This dictionary is a UI terminology guideline, not a substitute for legal translation review.

---

## 7. UI Translation Examples

| Indonesian | English |
|---|---|
| Dasbor | Dashboard |
| Proyek | Projects |
| Pekerjaan Notaris | Notary Matters |
| Pekerjaan PPAT | PPAT Matters |
| Para Pihak | Parties |
| Dokumen | Documents |
| Tugas | Tasks |
| Kalender | Calendar |
| Data Master | Master Data |
| Pengaturan | Settings |
| Menunggu Dokumen | Waiting for Documents |
| Dokumen Belum Lengkap | Missing Documents |
| Perlu Review | Pending Review |
| Jadwal Penandatanganan | Signing Schedule |
| Selesai | Completed |
| Sedang Diproses | In Progress |
| Dibatalkan | Cancelled |
| Diarsipkan | Archived |

---

## 8. Status Codes

Database stores stable codes, not translated labels.

Example:

```text
IN_PROGRESS
WAITING_DOCUMENTS
COMPLETED
CANCELLED
ARCHIVED
```

Frontend displays:

Indonesian:

```text
Sedang Diproses
Menunggu Dokumen
Selesai
Dibatalkan
Diarsipkan
```

English:

```text
In Progress
Waiting for Documents
Completed
Cancelled
Archived
```

---

## 9. Workflow Codes

Database example:

```text
INTAKE
DOCUMENT_COLLECTION
DOCUMENT_VERIFICATION
DRAFT_PREPARATION
NOTARY_REVIEW
TAX_PROCESSING
SIGNING
REGISTRATION
COMPLETION
ARCHIVE
```

UI may display:

| Code | Indonesian | English |
|---|---|---|
| INTAKE | Penerimaan Awal | Intake |
| DOCUMENT_COLLECTION | Pengumpulan Dokumen | Document Collection |
| DOCUMENT_VERIFICATION | Verifikasi Dokumen | Document Verification |
| DRAFT_PREPARATION | Penyusunan Draft | Draft Preparation |
| NOTARY_REVIEW | Review Notaris | Notary Review |
| TAX_PROCESSING | Proses Pajak | Tax Processing |
| SIGNING | Penandatanganan | Signing |
| REGISTRATION | Proses Pendaftaran | Registration |
| COMPLETION | Penyelesaian | Completion |
| ARCHIVE | Arsip | Archive |

---

## 10. Static Translation File Structure

Recommended:

```json
{
  "common": {},
  "navigation": {},
  "actions": {},
  "auth": {},
  "dashboard": {},
  "projects": {},
  "matters": {},
  "documents": {},
  "tasks": {},
  "status": {},
  "validation": {},
  "legal": {}
}
```

---

## 11. Translation Key Rule

Bad:

```tsx
<Button>Simpan</Button>
```

Good:

```tsx
<Button>{t('actions.save')}</Button>
```

Do not hardcode reusable UI strings.

---

## 12. Natural English Rule

English UI should sound like professional workplace software.

Avoid rigid literal translation.

Examples:

```text
Perlu Review
NOT "Need Review"
USE "Pending Review"
```

```text
Dokumen Belum Lengkap
NOT "Document Not Complete"
USE "Missing Documents"
```

```text
Pekerjaan Saya
USE "My Matters" or "My Work" depending on context
```

Choose the translation based on product context.

---

## 13. Legal Term Tooltip

Where helpful, English mode may show:

```text
Warkah
Supporting Legal Documents
```

or tooltip/help text.

Do not replace the legal term with a simplified English term if that causes ambiguity.

---

## 14. Admin-Managed Legal Terminology

A future Master Data module may allow:

```text
code
term_id
term_en
explanation_id
explanation_en
preserve_original_term
category
is_active
```

Changes should be controlled by authorized users.

---

## 15. Do Not Invent Legal Translation

If an unfamiliar legal term appears:

1. preserve the Indonesian term;
2. do not guess;
3. mark it for terminology review;
4. update this document after confirmation.

---

## 16. Dates, Time, Numbers

Indonesian UI:

```text
8 Agustus 2026
```

English UI:

```text
8 August 2026
```

The database should store timezone-aware timestamps where appropriate.

Currency formatting should follow the applicable business context.

---

## 17. Names and Legal Data

Never translate:

- person names;
- company legal names;
- certificate numbers;
- deed numbers;
- registration numbers;
- internal reference numbers.

Example:

```text
PT Sinar Abadi
```

remains:

```text
PT Sinar Abadi
```

in both locales.

---

## 18. Document Titles

If a document has an official Indonesian legal title, preserve that title.

An optional English explanation may be shown separately.

Do not rewrite official legal document names into an unofficial English title.

---

## 19. Default UI Preference

Default UI:

```text
Bahasa Indonesia
```

User preference may be stored as:

```text
preferred_locale = id
```

or:

```text
preferred_locale = en
```

The user's language choice should persist across sessions.

---

**Status:** Final baseline v1.0
