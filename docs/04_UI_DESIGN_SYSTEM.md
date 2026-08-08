# Notary & PPAT Office Management System
## UI Design System & Main Wireframes — v1.0

## 1. UI Goal

The interface should feel like a professional legal-office management application.

Design qualities:

```text
Professional
Legal
Modern
Calm
Structured
Minimal
Efficient
```

Avoid:

- excessive gradients;
- neon colors;
- decorative dashboards;
- oversized cards;
- excessive animation;
- overly rounded bubble UI;
- low information density.

---

## 2. UX Principle

Every operational page should help the user answer:

```text
What is this?
What is its current status?
Who is responsible?
What is missing?
What happens next?
When is it due?
```

---

## 3. Layout

Desktop-first application shell:

```text
┌───────────────┬─────────────────────────────────────────────┐
│               │ HEADER                                      │
│   SIDEBAR     ├─────────────────────────────────────────────┤
│               │                                             │
│               │ CONTENT                                     │
│               │                                             │
└───────────────┴─────────────────────────────────────────────┘
```

Sidebar width:

```text
240–260px
```

Collapsed:

```text
72px
```

---

## 4. Typography

Recommended:

```text
Inter
```

Hierarchy:

```text
Page Title       24px / Semibold
Section Title    18px / Semibold
Card Title       16px / Medium
Body             14px
Small Label      12px
Table            13–14px
```

---

## 5. Color System

Use semantic tokens.

Suggested primary brand color:

```text
Deep Navy
#172554
```

Supporting neutrals:

```text
Page background: #F8FAFC
Card background: #FFFFFF
Border: #E2E8F0
Secondary text: Slate
```

Status concepts:

```text
Success
Warning
Danger
Info
Neutral
```

Do not rely only on color to communicate status.

---

## 6. Domain Accents

Notary:

```text
Navy / Indigo
```

PPAT:

```text
Teal / Emerald
```

Use only as subtle accents:

- badge;
- icon;
- small border;
- section marker.

---

## 7. Spacing

Use a consistent spacing scale:

```text
4
8
12
16
24
32
48
```

Default desktop page padding:

```text
24px
```

---

## 8. Border Radius

Moderate radius:

```text
Button: 6–8px
Input: 6–8px
Card: 8–10px
Modal: 10–12px
```

---

## 9. Icons

Use Lucide Icons.

Examples:

```text
Dashboard       LayoutDashboard
Projects        FolderKanban
Notary          FileSignature
PPAT            Landmark
Clients         Users
Documents       Files
Tasks           CheckSquare
Calendar        CalendarDays
Billing         Receipt
Reports         BarChart3
Settings        Settings
```

---

## 10. Global Header

```text
┌─────────────────────────────────────────────────────────────┐
│ Search...          + New       Notifications    ID     User │
└─────────────────────────────────────────────────────────────┘
```

Components:

- Global Search
- Quick Create
- Notifications
- Locale Switcher
- User Menu

---

## 11. Sidebar

```text
Dashboard

Projects

Notary
├── Matters
├── Notarial Deeds
├── Drafts & Minuta Akta
├── Legalisasi
├── Waarmerking
├── Repertorium
└── Notary Protocol

PPAT
├── Matters
├── PPAT Deeds
├── Land & Property
├── Warkah
├── Taxes & Fees
├── Deed Register
├── PPAT Reports
└── PPAT Protocol

Clients & Parties
├── Individuals
└── Companies

Documents
Tasks
Calendar
Billing
Reports

Master Data
Settings
```

Visibility is permission-based.

---

## 12. Standard Page Header

Pattern:

```text
Breadcrumb

Page Title                              Primary Action
Subtitle

Filters / Search

Content
```

Example:

```text
PPAT / Matters

PPAT Matters                            + New PPAT Matter
Manage active PPAT matters

[Search] [Status] [PIC] [Service] [More Filters]
```

---

## 13. Tables

Use TanStack Table.

Support:

- search;
- sort;
- filter;
- pagination;
- column visibility;
- selected bulk actions where appropriate.

Example:

```text
Matter      Service     Client     PIC      Stage      Due
P-00128     AJB         PT ABC     Rina     Review     2d
P-00129     APHT        Budi       Dimas    Tax        5d
```

---

## 14. Status Badge

Use text + visual indicator.

Example:

```text
● In Progress
● Waiting
● Completed
● Overdue
```

Indonesian:

```text
● Diproses
● Menunggu
● Selesai
● Terlambat
```

Where an item lifecycle is shown rather than a single status, use:

```text
○ Not Started
● In Progress
✓ Completed
! Blocked
```

### SLA Indicator

Derived from the service `default_duration_days` and the target completion date.

```text
GREEN    On Track
YELLOW   Due Soon
RED      Overdue
```

These are presentation indicators only. They do not define statutory or legal deadlines.

---

## 15. Login Wireframe

```text
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  NOTARY & PPAT                 ┌─────────────────────────┐  │
│  OFFICE MANAGEMENT SYSTEM      │ Masuk                   │  │
│                                │ Email                   │  │
│                                │ [____________________]  │  │
│                                │ Password                │  │
│                                │ [____________________]  │  │
│                                │ □ Ingat saya            │  │
│                                │ [      Masuk         ]  │  │
│                                │ Lupa kata sandi?        │  │
│                                └─────────────────────────┘  │
│                          ID | EN                            │
└─────────────────────────────────────────────────────────────┘
```

---

## 16. Dashboard Wireframe

```text
┌─────────────────────────────────────────────────────────────┐
│ Selamat datang, Rina                                        │
├─────────────────────────────────────────────────────────────┤
│ [12 Tugas] [5 Menunggu Dok.] [3 Review] [2 Overdue]         │
├─────────────────────────────────────────────────────────────┤
│ TUGAS SAYA HARI INI            JADWAL HARI INI              │
│ Review dokumen PT ABC          10:00 Signing AJB            │
│ Prepare draft                  14:00 Client meeting         │
│ Verify KTP                     16:00 Review                 │
├─────────────────────────────────────────────────────────────┤
│ BUTUH PERHATIAN                                             │
│ Matter      Issue              PIC       Deadline           │
│ AJB PT ABC  BPHTB Missing      Rina      Today              │
├─────────────────────────────────────────────────────────────┤
│ RECENT ACTIVITY                                             │
└─────────────────────────────────────────────────────────────┘
```

Principal dashboard may additionally show:

- Awaiting My Review;
- Awaiting My Approval;
- Signing This Week;
- Critical Overdue;
- Office Workload.

---

## 17. Project List

```text
Projects                                  + New Project

[Search] [Status] [PIC] [Client] [Date]

Project       Client      PIC      Matters   Status      Due
PRJ-00128     PT ABC      Rina     4         Active      12d
PRJ-00129     Budi        Dimas    1         Waiting      3d
```

Default columns:

- Project Number
- Title
- Client
- PIC
- Matter Count
- Status
- Progress
- Target Date

---

## 18. Create Project

Prefer page or large drawer.

```text
New Project

Basic Information
Project Title
Primary Client
Description
Priority
PIC
Target Completion

Parties
+ Add Party

Initial Matters
□ Create Notary Matter
□ Create PPAT Matter

[Cancel]                         [Create Project]
```

---

## 19. Project Detail

Header:

```text
PRJ-2026-00128
Akuisisi Tanah PT ABC
● In Progress

Client: PT ABC
PIC: Rina
Opened: 02 Aug 2026
Target: 25 Aug 2026

[More] [ + Add Matter ]
```

Tabs:

```text
Overview
Matters
Parties
Documents
Tasks
Timeline
Billing
```

---

## 20. Notary Matter Detail

Header:

```text
N-2026-00312
Akta Perubahan — PT ABC

NOTARY
● IN PROGRESS

Current Stage: NOTARY REVIEW
PIC: Rina
Target: 12 Aug 2026
```

Tabs:

```text
Overview
Parties
Requirements
Documents
Draft
Deed
Tasks
Timeline
Billing
```

Workflow should use a vertical stepper when long.

---

## 21. PPAT Matter Detail

Header:

```text
P-2026-00128

AJB — Akta Jual Beli
Deed of Sale and Purchase

PPAT
● IN PROGRESS

Client: PT ABC
PIC: Rina
Target: 18 Aug 2026
```

Tabs:

```text
Overview
Parties
Property
Requirements
Taxes
Warkah
Deed
Tasks
Timeline
Billing
```

---

## 22. Requirements UI

Example:

```text
Requirements                     8 / 10 Complete

✓ KTP Direktur
  Verified

! NPWP Komisaris
  Missing
  [Upload Document]

● Minutes of Meeting
  Under Review
```

A requirement should have lifecycle state, not merely a checkbox.

---

## 23. Draft UI

```text
Draft Akta

Current Version
Draft Akta v3.docx
Uploaded by Rina
08 Aug 2026 13:20

[Preview] [Download]

Versions
v3 Current
v2
v1

[Upload New Version]
```

Never overwrite old versions.

---

## 24. Individual List

```text
Individuals                              + New Individual

Search by name, NIK, phone...

Name             NIK               Phone      Matters
Budi Santoso     3174******1234    0812...    12
```

NIK is masked by default.

---

## 25. Individual Detail

Tabs:

```text
Profile
Identity
Documents
Companies
Projects
Matters
Timeline
```

Sensitive identity fields are shown only to users with permission.

---

## 26. Company Detail

Tabs:

```text
Overview
Management
Shareholders
Documents
Projects
Matters
Timeline
```

Display management and ownership history rather than only current values.

---

## 27. Property List

```text
Land & Property                         + New Property

Property      Right     Certificate    Owner       Area
PROP-00128    HM        SHM 123        Budi        540 m²
```

---

## 28. Property Detail

Tabs:

```text
Overview
Ownership
Documents
Transactions
Timeline
```

Ownership history must preserve previous owners.

---

## 29. Document Center

```text
Documents                                + Upload Document

[Search]

[Type] [Related To] [Status] [Uploader] [Date]

Document         Type          Related       Status
KTP Budi         KTP           Budi          Verified
Draft AJB v3     Draft         P-00128       Current
SHM 123          Certificate   PROP-00128    Verified
```

---

## 30. Warkah UI

```text
Warkah
AJB No. 125/2026

7 of 9 complete
78%
● Under Review

SELLER DOCUMENTS
✓ KTP Penjual
✓ NPWP Penjual
! Persetujuan Pasangan
  Missing

BUYER DOCUMENTS
✓ KTP Pembeli
✓ NPWP Pembeli

PROPERTY DOCUMENTS
✓ Sertipikat
● PBB
  Under Review

TAX DOCUMENTS
✓ PPh
! BPHTB
  Missing
```

Actions:

```text
Save
Verify Warkah
Finalize Warkah
```

Finalization only appears when permission and business rules allow it.

---

## 31. Timeline

Example:

```text
08 Aug 2026

14:20 Rina uploaded Draft AJB v3
13:55 Dimas verified SHM 123
11:20 Workflow changed Verification → Tax Processing
09:12 BPHTB task assigned to Rina
```

Filters:

```text
All
Documents
Workflow
Tasks
Comments
Approvals
```

---

## 32. Sensitive Data

Example:

```text
NIK
3174 ******** 1234

[Show]
```

Full-value reveal requires permission.

---

## 33. Legal Record Locking UI

Finalized record:

```text
FINALIZED
🔒 Locked

This record is finalized and cannot be edited through the normal process.
```

If correction is supported:

```text
Request Correction
```

Do not show a generic Edit button.

---

## 34. Bilingual UI

Static strings must use translation keys.

Avoid word-for-word translation when it makes the English interface unnatural.

Examples:

```text
Dokumen Belum Lengkap
→ Missing Documents

Perlu Review
→ Pending Review

Jadwal Penandatanganan
→ Signing Schedule
```

---

## 35. Responsive Behavior

Desktop-first.

Desktop:
- fixed/collapsible sidebar.

Tablet:
- collapsible navigation.

Mobile:
- navigation drawer;
- horizontal table scroll or card conversion where practical.

Do not over-engineer mobile during MVP.

---

## 36. Component Library

Base shadcn/ui components plus custom components:

```text
PageHeader
PageContainer
DataTable
StatusBadge
DomainBadge
WorkflowStepper
RequirementList
DocumentCard
DocumentPreview
MatterHeader
ProjectSummary
PartyPicker
PropertyPicker
PermissionGuard
SensitiveField
ActivityTimeline
QuickCreate
```

---

## 37. Frontend Page Development Order

```text
1. App Shell
2. Login
3. Dashboard
4. Projects
5. Individuals
6. Companies
7. Notary Matters
8. PPAT Matters
9. Property
10. Documents
11. Warkah
12. Tasks
13. Calendar
14. Advanced modules
```

---

**Status:** Final baseline v1.0
